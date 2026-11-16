/**
 * Remise de notes, côté enseignant : formes des réponses de l'API (/api/v1/teacher/grading/…) et fonctions PURES
 * (calcul de la note, contrôle avant transmission, application « optimiste » d'une saisie, adresses).
 *
 * Le calcul est le MÊME que celui du serveur (GradingRules.php, lui-même confronté à l'ancien code) : il permet de montrer la note
 * dès la saisie, même sans réseau. Le serveur reste seul juge : il recalcule à chaque écriture et à la transmission.
 */

/** Une colonne de la feuille : `slot` = identité stable (les notes s'y rattachent), le titre et la pondération sont au choix de l'enseignant. */
export type Component = { slot: number; label: string; weight: number };
export type CellField = `c${number}` | "statut_special" | "commentaire";
export type SpecialCode = "V" | "E" | "NN";
export type SheetStatus = "brouillon" | "soumise" | "validee" | "rejetee";

export const MAX_COMPONENTS = 12;
export const MAX_LABEL = 40;

export type NoteState = "calculated" | "special" | "incomplete" | "empty";
export type Note = { value: number | null; state: NoteState; detail: string };

export type GradeLine = {
  student_id: string;
  last_name: string | null;
  first_name: string | null;
  level: number | null;
  /** note de l'étudiant dans chaque colonne, par `slot` (clés textuelles dans le JSON) */
  values: Record<string, number | null>;
  statut_special: SpecialCode | null;
  commentaire: string | null;
  note: Note;
  modified_at: string | null;
  attendance_exceeded: boolean;
  /** vrai tant que la saisie n'a pas atteint le serveur (hors ligne) */
  pending?: boolean;
};

export type Check = { blockers: string[]; warnings: string[]; ready: number; total: number };

export type JournalEntry = { at: string; author: string | null; role: string | null; action: string; field: string | null; student_id: string | null; old: string | null; new: string | null };

export type Sheet = {
  course: { id: number; code: string; title: string | null; credits: number | null; level: number | null; teacher: string | null; validation_course: boolean };
  year: string;
  semester: number | null;
  semester_label: string;
  remise: { id: number | null; status: SheetStatus; editable: boolean; reject_reason: string | null; submitted_at: string | null; processed_at: string | null; modified_at: string | null };
  components: Component[];
  total_weight: number;
  limits: { max_components: number; max_label: number };
  lines: GradeLine[];
  check: Check;
  journal: JournalEntry[];
};

export type GradingCourse = {
  id: number;
  code: string;
  title: string | null;
  level: number | null;
  credits: number | null;
  semester: number | null;
  semester_label: string;
  students: number;
  without_grade: number;
  status: SheetStatus;
  modified_at: string | null;
  submitted_at: string | null;
  processed_at: string | null;
  reject_reason: string | null;
};
export type GradingCourses = { linked: boolean; year: string; years: string[]; courses: GradingCourse[] };

export const STATUS_INFO: Record<SheetStatus, { label: string; tone: "ok" | "warn" | "bad" | "info" }> = {
  brouillon: { label: "Brouillon", tone: "info" },
  soumise: { label: "En attente de validation", tone: "warn" },
  validee: { label: "Validée", tone: "ok" },
  rejetee: { label: "Renvoyée pour correction", tone: "bad" },
};

// ----------------------------------------------------------------- calcul

export function totalWeight(components: Pick<Component, "weight">[]): number {
  return Math.round(components.reduce((t, c) => t + (Number(c.weight) || 0), 0) * 100) / 100;
}

/** Arrondi à 2 décimales « comme PHP » (la moitié s'arrondit vers le haut, sans l'erreur binaire de 1,005 × 100). */
export function round2(x: number): number {
  return Math.round((x + Number.EPSILON * Math.sign(x)) * 100) / 100;
}

/** La note finale d'une ligne : moyenne pondérée, jamais au prorata (une composante pondérée non saisie rend la ligne incomplète). */
export function computeNote(values: Record<string, number | null | undefined>, special: string | null | undefined, components: Component[]): Note {
  const sp = (special ?? "").trim();
  if (sp !== "") return { value: null, state: "special", detail: sp.toUpperCase() };

  let sum = 0;
  let used = 0;
  let entered = 0;
  const missing: string[] = [];
  for (const c of components) {
    const weight = Number(c.weight) || 0;
    if (weight <= 0) continue;
    used += weight;
    const v = values[String(c.slot)];
    if (v === null || v === undefined || (v as unknown) === "") {
      missing.push(c.label);
      continue;
    }
    entered++;
    sum += Number(v) * weight;
  }
  if (used <= 0) return { value: null, state: "empty", detail: "Aucune pondération définie" };
  if (entered === 0) return { value: null, state: "empty", detail: "Aucune note saisie" };
  if (missing.length) return { value: null, state: "incomplete", detail: `Manque : ${missing.join(", ")}` };
  return { value: round2(sum / used), state: "calculated", detail: "Moyenne pondérée des composantes" };
}

function shortList(names: string[], max = 5): string {
  return names.length <= max ? names.join(", ") : `${names.slice(0, max).join(", ")} et ${names.length - max} autre(s)`;
}

const comma = new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 2 });

/** Ce qui empêche de transmettre (blockers), ce qu'il vaut mieux savoir (warnings), et le nombre de notes prêtes. */
export function checkSheet(lines: GradeLine[], components: Component[]): Check {
  const blockers: string[] = [];
  const warnings: string[] = [];
  const incomplete: string[] = [];
  const outOfScale: string[] = [];
  const notGraded: string[] = [];
  let ready = 0;

  const total = totalWeight(components);
  if (Math.abs(total - 100) > 0.01) blockers.push(`La pondération totalise ${comma.format(total)} % au lieu de 100 %.`);
  if (lines.length === 0) blockers.push("Aucun étudiant inscrit à ce cours pour cette année et ce semestre.");

  for (const l of lines) {
    const n = computeNote(l.values, l.statut_special, components);
    const name = [l.last_name, l.first_name].filter(Boolean).join(" ").trim() || l.student_id;
    if (n.state === "incomplete") incomplete.push(name);
    else if (n.state === "empty") notGraded.push(name);
    else if (n.value !== null && (n.value < 0 || n.value > 20)) outOfScale.push(`${name} (${n.value})`);
    else ready++; // calculée ou statut spécial : transférable
  }

  if (outOfScale.length) blockers.push(`Note hors barème 0-20 : ${shortList(outOfScale)}.`);
  if (incomplete.length) blockers.push(`${incomplete.length} étudiant(s) avec une composante pondérée non saisie : ${shortList(incomplete)}.`);
  if (notGraded.length) warnings.push(`${notGraded.length} étudiant(s) sans aucune note : ${shortList(notGraded)}. Ils seront enregistrés « pas encore noté » (NN).`);
  return { blockers, warnings, ready, total: lines.length };
}

// ------------------------------------------------------------------ saisie

/** « 12,5 » → 12.5 ; vide → null ; hors 0–20 ou illisible → undefined (refusé). */
export function parseComponent(raw: string): number | null | undefined {
  const t = raw.trim();
  if (t === "") return null;
  const n = t.replace(",", ".");
  if (!/^[+-]?(\d+(\.\d*)?|\.\d+)$/.test(n)) return undefined;
  const v = round2(Number(n));
  return v < 0 || v > 20 ? undefined : v;
}

/** Un poids saisi : vide ou illisible = 0 ; borné de 0 à 100. */
export function parseWeight(raw: string): number {
  const n = raw.trim().replace(",", ".");
  if (n === "" || !/^[+-]?(\d+(\.\d*)?|\.\d+)$/.test(n)) return 0;
  return round2(Math.max(0, Math.min(100, Number(n))));
}

/** 12.5 → « 12,5 » ; null → « » (champ vide). */
export function formatCell(v: number | null): string {
  return v === null ? "" : comma.format(v);
}

/** Le `slot` visé par un champ « c<slot> », ou null pour le statut et le commentaire. */
export function slotOf(field: CellField): number | null {
  const m = /^c(\d+)$/.exec(field);
  return m ? Number(m[1]) : null;
}

/** Une saisie appliquée à la feuille déjà affichée : la note de la ligne et le contrôle sont recalculés tout de suite. */
export function applyCell(sheet: Sheet, student: string, field: CellField, value: number | string | null, pending = false): Sheet {
  const slot = slotOf(field);
  const lines = sheet.lines.map((l) => {
    if (l.student_id !== student) return l;
    const next: GradeLine = slot !== null ? { ...l, values: { ...l.values, [String(slot)]: value as number | null } } : ({ ...l, [field]: value } as GradeLine);
    return { ...next, pending: pending || undefined, note: computeNote(next.values, next.statut_special, sheet.components) };
  });
  return { ...sheet, lines, check: checkSheet(lines, sheet.components) };
}

/** Une nouvelle liste de colonnes appliquée à la feuille (réponse du serveur) : notes et contrôle recalculés. */
export function applyComponents(sheet: Sheet, components: Component[]): Sheet {
  const keep = new Set(components.map((c) => String(c.slot)));
  const lines = sheet.lines.map((l) => {
    const values = Object.fromEntries(Object.entries(l.values).filter(([k]) => keep.has(k)));
    return { ...l, values, note: computeNote(values, l.statut_special, components) };
  });
  return { ...sheet, components, total_weight: totalWeight(components), lines, check: checkSheet(lines, components) };
}

/** Le statut qui remplace le cours (V, E, NN) : seuls ces codes sont acceptés. */
export function parseSpecial(raw: string): SpecialCode | null | undefined {
  const c = raw.trim().toUpperCase();
  if (c === "") return null;
  return c === "V" || c === "E" || c === "NN" ? c : undefined;
}

// ------------------------------------------------------------------ adresses

const q = (year: string, semester: number | null) => {
  const p = new URLSearchParams({ year });
  if (semester !== null) p.set("semester", String(semester));
  return p.toString();
};

export const sheetPath = (course: number, year: string, semester: number | null) => `/api/v1/teacher/grading/courses/${course}/sheet?${q(year, semester)}`;
export const gradingKeys = {
  courses: (year: string | null) => ["teacher", "grading", "courses", year ?? "auto"] as const,
  sheet: (course: number, year: string, semester: number | null) => ["teacher", "grading", "sheet", course, year, semester ?? "all"] as const,
};

export function sheetUrl(course: number, year: string, semester: number | null): string {
  const p = new URLSearchParams({ cours: String(course), annee: year });
  if (semester !== null) p.set("semestre", String(semester));
  return `/notes/feuille/?${p}`;
}

/** 1 → « Licence 1 », 4 → « Master 1 » (comme partout ailleurs dans l'application). */
export function levelLabel(level: number | null | undefined): string {
  const n = Number(level);
  if (!Number.isFinite(n) || n <= 0) return "—";
  return n <= 3 ? `Licence ${n}` : `Master ${n - 3}`;
}
