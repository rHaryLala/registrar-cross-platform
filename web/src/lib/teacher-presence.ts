/**
 * Registres de présence, côté enseignant : formes des réponses de l'API (/api/v1/teacher/…) et fonctions PURES
 * (libellés, application « optimiste » d'un changement sur les données déjà affichées, adresses).
 *
 * Ces fonctions pures servent à deux choses : montrer tout de suite le résultat d'un geste (même sans réseau), et être
 * testées sans navigateur. Les bilans (15 %, absences comptées) restent calculés PAR LE SERVEUR : hors ligne, on ne les
 * invente pas, on les marque « en attente » jusqu'à la synchronisation.
 */

export type Status = "present" | "retard" | "absent";
export type Justification = "medical" | "autre";
export type State = "ok" | "limit" | "exceeded" | "unknown";

export type Summary = {
  recorded: number;
  present: number;
  late: number;
  absent: number;
  no_penalty: number;
  converted_lates: number;
  counted: number;
  late_pending: boolean;
  planned: number | null;
  allowed: number | null;
  rate: number | null;
  state: State;
  gauge: number;
};

export type CourseRow = {
  id: number;
  code: string;
  title: string | null;
  level: number | null;
  semester: number | null;
  semester_label: string;
  students: number;
  held_sessions: number;
  planned_sessions: number | null;
  flagged: number;
};
export type CoursesData = { linked: boolean; year: string; years: string[]; courses: CourseRow[] };

export type RegisterStudent = { id: string; last_name: string | null; first_name: string | null; summary: Summary; flagged_at: string | null };
export type SessionRow = {
  id: number;
  date: string;
  planned_time: string | null;
  teacher_arrival: string | null;
  warned: boolean;
  comment: string | null;
  teacher_late_minutes: number | null;
  no_penalty: boolean;
  modified_at: string | null;
  absent: number;
  late: number;
  to_mark: number;
};
export type RegisterData = {
  course: { id: number; code: string; title: string | null };
  year: string;
  semester: number | null;
  semester_label: string;
  sheet: { id: number | null; planned_sessions: number | null; allowed_absences: number | null };
  students: RegisterStudent[];
  sessions: SessionRow[];
  exceeded: number;
  today: string;
  suggested_time: string | null;
};

export type Line = {
  student_id: string;
  last_name: string | null;
  first_name: string | null;
  status: Status | null;
  minutes_late: number | null;
  justification: Justification | null;
  label: string;
  category: "present" | "retard" | "absence" | null;
  modified_at: string | null;
  summary: Summary;
  /** vrai tant que le bilan n'a pas été recalculé par le serveur (changement fait hors ligne) */
  stale?: boolean;
};
export type SessionView = Omit<SessionRow, "absent" | "late" | "to_mark"> & { sheet_id: number | null; course_id: number; year: string; semester: number | null };
export type SessionData = { session: SessionView; lines: Line[] };

export const LATE_TOLERATED = 5;
export const LATE_AS_ABSENCE = 10;

/** Ce que compte un pointage (§476) : c'est la DURÉE du retard qui décide, pas le bouton choisi. */
export function category(status: Status | null, minutes: number | null): Line["category"] {
  if (status === "present") return "present";
  if (status === "absent") return "absence";
  if (status === "retard") {
    const m = minutes ?? LATE_TOLERATED;
    return m >= LATE_AS_ABSENCE ? "absence" : m >= LATE_TOLERATED ? "retard" : "present";
  }
  return null;
}

/** Libellé court d'un pointage, identique à celui du serveur (« Retard 12 min (compte absence) »). */
export function recordLabel(status: Status | null, minutes: number | null): string {
  const cat = category(status, minutes);
  if (cat === null) return "Non pointé";
  if (status === "retard") {
    const m = minutes ?? LATE_TOLERATED;
    return `Retard ${m} min${cat === "absence" ? " (compte absence)" : cat === "present" ? " (toléré)" : ""}`;
  }
  return cat === "present" ? "Présent" : "Absent";
}

/** « 8:30 » → « 08:30 » ; « 25:00 » ou « 8h30 » → null. */
export function normalizeTime(raw: string): string | null {
  const m = /^([01]?\d|2[0-3]):([0-5]\d)$/.exec(raw.trim());
  return m ? `${m[1].padStart(2, "0")}:${m[2]}` : null;
}

/** Minutes de retard de l'enseignant (arrivée − début prévu), ou null si une heure manque. Jamais négatif. */
export function teacherLateMinutes(planned: string | null, arrival: string | null): number | null {
  const a = toMinutes(planned);
  const b = toMinutes(arrival);
  return a === null || b === null ? null : Math.max(0, b - a);
}

function toMinutes(t: string | null): number | null {
  const m = t ? /^(\d{1,2}):(\d{2})/.exec(t) : null;
  return m ? Number(m[1]) * 60 + Number(m[2]) : null;
}

/** §474 : l'enseignant a plus de 10 minutes de retard et n'a pas prévenu : la séance est sans pénalité pour les étudiants. */
export function noPenalty(planned: string | null, arrival: string | null, warned: boolean): boolean {
  const late = teacherLateMinutes(planned, arrival);
  return late !== null && late > 10 && !warned;
}

// ------------------------------------------------------------ application optimiste

/** Marque (ou démarque) des lignes comme « bilan en attente » : vrai seulement tant qu'un changement n'a pas atteint le serveur. */
export function setStale(data: SessionData, who: readonly string[] | "all", stale: boolean): SessionData {
  return { ...data, lines: data.lines.map((l) => (who === "all" || who.includes(l.student_id) ? { ...l, stale: stale || undefined } : l)) };
}

/** Un pointage appliqué aux données de l'appel. Le bilan, lui, devient « en attente » (le serveur le recalculera). */
export function applyMark(data: SessionData, studentId: string, status: Status | null, minutes: number | null, at: string | null = null): SessionData {
  const mins = status === "retard" ? (minutes ?? LATE_TOLERATED) : null;
  return {
    ...data,
    lines: data.lines.map((l) =>
      l.student_id === studentId
        ? { ...l, status, minutes_late: mins, label: recordLabel(status, mins), category: category(status, mins), modified_at: at ?? l.modified_at, stale: true }
        : l,
    ),
  };
}

export function applyJustification(data: SessionData, studentId: string, justification: Justification | null): SessionData {
  return { ...data, lines: data.lines.map((l) => (l.student_id === studentId ? { ...l, justification } : l)) };
}

/** « Tous présents » : seuls les étudiants non encore pointés changent. */
export function applyAllPresent(data: SessionData, at: string | null = null): SessionData {
  return {
    ...data,
    lines: data.lines.map((l) => (l.status === null ? { ...l, status: "present", minutes_late: null, label: "Présent", category: "present", modified_at: at ?? l.modified_at, stale: true } : l)),
  };
}

export function applySessionEdit(data: SessionData, edit: { planned_time: string | null; teacher_arrival: string | null; warned: boolean; comment: string | null }): SessionData {
  const s = data.session;
  return {
    ...data,
    session: {
      ...s,
      ...edit,
      teacher_late_minutes: teacherLateMinutes(edit.planned_time, edit.teacher_arrival),
      no_penalty: noPenalty(edit.planned_time, edit.teacher_arrival, edit.warned),
    },
    lines: data.lines.map((l) => ({ ...l, stale: true })),
  };
}

/** L'appel d'une séance créée hors ligne : la liste des inscrits du registre, personne de pointé. */
export function blankSession(reg: RegisterData, id: number, input: { date: string; planned_time: string | null; teacher_arrival: string | null; warned: boolean }): SessionData {
  return {
    session: {
      id,
      date: input.date,
      planned_time: input.planned_time,
      teacher_arrival: input.teacher_arrival,
      warned: input.warned,
      comment: null,
      teacher_late_minutes: teacherLateMinutes(input.planned_time, input.teacher_arrival),
      no_penalty: noPenalty(input.planned_time, input.teacher_arrival, input.warned),
      modified_at: null,
      sheet_id: reg.sheet.id,
      course_id: reg.course.id,
      year: reg.year,
      semester: reg.semester,
    },
    lines: reg.students.map((s) => ({
      student_id: s.id,
      last_name: s.last_name,
      first_name: s.first_name,
      status: null,
      minutes_late: null,
      justification: null,
      label: "Non pointé",
      category: null,
      modified_at: null,
      summary: s.summary,
    })),
  };
}

/** La séance ajoutée à la liste du registre (comptes à zéro tant que rien n'est pointé). */
export function addSessionRow(reg: RegisterData, s: SessionView): RegisterData {
  if (reg.sessions.some((x) => x.id === s.id)) return reg;
  const row: SessionRow = { ...s, absent: 0, late: 0, to_mark: reg.students.length };
  const sessions = [...reg.sessions, row].sort((a, b) => (a.date === b.date ? (b.planned_time ?? "").localeCompare(a.planned_time ?? "") || b.id - a.id : b.date.localeCompare(a.date)));
  return { ...reg, sessions };
}

export function removeSessionRow(reg: RegisterData, id: number): RegisterData {
  return { ...reg, sessions: reg.sessions.filter((s) => s.id !== id) };
}

// ------------------------------------------------------------------ adresses

export const keys = {
  courses: (year: string | null) => ["teacher", "courses", year ?? "auto"] as const,
  register: (course: number, year: string, semester: number | null) => ["teacher", "register", course, year, semester ?? "all"] as const,
  session: (id: number) => ["teacher", "session", id] as const,
};

export function registerPath(course: number, year: string, semester: number | null): string {
  const q = new URLSearchParams({ year });
  if (semester !== null) q.set("semester", String(semester));
  return `/api/v1/teacher/courses/${course}/register?${q}`;
}

/** Adresse de l'écran de l'appel (le site est exporté en statique : tout passe par la chaîne de requête). */
export function callUrl(session: number, course: number, year: string, semester: number | null): string {
  const q = new URLSearchParams({ seance: String(session), cours: String(course), annee: year });
  if (semester !== null) q.set("semestre", String(semester));
  return `/presence/appel/?${q}`;
}

export function registerUrl(course: number, year: string, semester: number | null): string {
  const q = new URLSearchParams({ cours: String(course), annee: year });
  if (semester !== null) q.set("semestre", String(semester));
  return `/presence/registre/?${q}`;
}

/** Lit « semestre » dans l'adresse : absent ou vide = tous les semestres (null). */
export function parseSemester(raw: string | null): number | null {
  if (raw === null || raw === "") return null;
  const n = Number(raw);
  return Number.isInteger(n) && n >= 1 && n <= 4 ? n : null;
}
