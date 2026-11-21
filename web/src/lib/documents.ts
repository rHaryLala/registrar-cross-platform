/**
 * Documents imprimables d'un étudiant, pour le personnel (lot 6) : formes des réponses de l'API (/api/v1/staff/students/{id}/…) et petites
 * fonctions pures. Les PDF sont fabriqués par le serveur ; ici on ne fait que les demander et les nommer.
 */

export type EnrollmentSession = {
  session_id: number;
  session_name: string;
  year: string;
  level_label: string | null;
  mention: string | null;
  enrolled_on: string | null;
  courses: number;
  has_fees: boolean;
};

export type EnrollmentSheets = {
  student_id: string;
  name: string;
  sessions: EnrollmentSession[];
};

/** La fiche d'inscription porte des montants : superadmin, administration, registrariat et comptabilité (niveaux 1 à 4). Le serveur revérifie. */
export function canPrintEnrollment(level: number | null | undefined): boolean {
  return typeof level === "number" && level >= 1 && level <= 4;
}

export function enrollmentSheetsUrl(studentRowId: number): string {
  return `/api/v1/staff/students/${studentRowId}/enrollment-sheets`;
}

export function enrollmentSheetPdfUrl(studentRowId: number, sessionId: number): string {
  return `${enrollmentSheetsUrl(studentRowId)}/${sessionId}/pdf`;
}

export const documentKeys = {
  enrollmentSheets: (studentRowId: number) => ["staff", "documents", "enrollment-sheets", studentRowId] as const,
};

/** Nom de repli si le serveur n'en annonce pas : sans accents ni espaces, comme celui du serveur. */
export function sheetFallbackName(studentId: string, s: Pick<EnrollmentSession, "session_name" | "year">): string {
  const plain = `${s.session_name} ${s.year}`.normalize("NFD").replace(/[̀-ͯ]/g, "");
  const slug = plain.replace(/[^A-Za-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
  const id = studentId.replace(/[^A-Za-z0-9_-]+/g, "");
  return `${id}-fiche-inscription-${slug}.pdf`;
}

/** « 3 cours », « 1 cours », « aucun cours » : une fiche sans cours est une fiche vide, on le dit avant de l'imprimer. */
export function coursesText(n: number): string {
  return n === 0 ? "aucun cours" : n === 1 ? "1 cours" : `${n} cours`;
}

/** Une fiche sans ligne financière ne vaut pas justificatif de paiement : on le dit avant de l'imprimer. */
export function feesText(hasFees: boolean): string {
  return hasFees ? "frais enregistrés" : "sans ligne financière";
}

/** Regroupe les sessions par année scolaire, dans l'ordre reçu (la plus récente d'abord). */
export function groupByYear(sessions: EnrollmentSession[]): { year: string; sessions: EnrollmentSession[] }[] {
  const groups: { year: string; sessions: EnrollmentSession[] }[] = [];
  for (const s of sessions) {
    const last = groups[groups.length - 1];
    if (last && last.year === s.year) last.sessions.push(s);
    else groups.push({ year: s.year, sessions: [s] });
  }
  return groups;
}

// ---------------------------------------------------------------------------------------------------------------------
// Relevé semestriel des notes

export type TranscriptSession = {
  session_id: number;
  session_name: string;
  year: string;
  semester: number;
  courses: number;
};

export type TranscriptOptions = {
  student_id: string;
  name: string;
  sessions: TranscriptSession[];
};

/** Ce que le relevé couvre : tout l'historique, une année scolaire, ou une seule session. */
export type TranscriptScope = { kind: "all" } | { kind: "year"; year: string } | { kind: "session"; id: number };

export const transcriptKeys = {
  options: (studentRowId: number) => ["staff", "documents", "transcript", studentRowId] as const,
};

export function transcriptOptionsUrl(studentRowId: number): string {
  return `/api/v1/staff/students/${studentRowId}/transcript`;
}

export function transcriptPdfUrl(studentRowId: number, scope: TranscriptScope): string {
  const base = `${transcriptOptionsUrl(studentRowId)}/pdf`;
  if (scope.kind === "year") return `${base}?year=${encodeURIComponent(scope.year)}`;
  if (scope.kind === "session") return `${base}?session=${scope.id}`;
  return base;
}

/** « all », « year:2025 - 2026 », « session:12 » : la valeur d'une option du sélecteur. */
export function scopeValue(scope: TranscriptScope): string {
  return scope.kind === "all" ? "all" : scope.kind === "year" ? `year:${scope.year}` : `session:${scope.id}`;
}

export function parseScope(value: string): TranscriptScope {
  if (value.startsWith("year:")) return { kind: "year", year: value.slice(5) };
  if (value.startsWith("session:")) {
    const id = Number(value.slice(8));
    if (Number.isInteger(id) && id > 0) return { kind: "session", id };
  }
  return { kind: "all" };
}

/** Les choix du sélecteur : tout, chaque année (si l'étudiant en a plusieurs), chaque session. Les sessions arrivent de la plus ancienne à la plus récente. */
export function scopeChoices(sessions: TranscriptSession[]): { value: string; label: string; group: string }[] {
  const out: { value: string; label: string; group: string }[] = [{ value: "all", label: `Tout l’historique (${sessions.length} ${sessions.length > 1 ? "sessions" : "session"})`, group: "" }];
  const years = [...new Set(sessions.map((s) => s.year))];
  if (years.length > 1) {
    for (const y of years) {
      const n = sessions.filter((s) => s.year === y).length;
      out.push({ value: `year:${y}`, label: `Année ${y} (${n} ${n > 1 ? "sessions" : "session"})`, group: "Une année" });
    }
  }
  for (const s of sessions) {
    out.push({ value: `session:${s.session_id}`, label: `${s.session_name} · ${s.year} (${coursesText(s.courses)})`, group: "Une session" });
  }
  return out;
}

/** Nom de repli si le serveur n'en annonce pas. */
export function transcriptFallbackName(studentId: string, scope: TranscriptScope): string {
  const id = studentId.replace(/[^A-Za-z0-9_-]+/g, "");
  const year = scope.kind === "year" ? `-${scope.year.replace(/[^0-9]+/g, "-").replace(/^-+|-+$/g, "")}` : "";
  return `${id}-releve-semestriel${year}.pdf`;
}
