const decimal = new Intl.NumberFormat("fr-FR", { minimumFractionDigits: 1, maximumFractionDigits: 2 });

/** 14.5 → « 14,5 » (virgule décimale française). */
export function formatGrade(value: number): string {
  return decimal.format(value);
}

/** Une moyenne qui n'existe pas encore s'écrit « — », jamais « 0 » ni « NaN ». */
export function formatAverage(value: number | null | undefined): string {
  return value === null || value === undefined ? "—" : formatGrade(value);
}

/** États d'une note : S succès, E échec, V validé (cours de validation), NN pas encore noté. */
export type GradeCode = "S" | "E" | "V" | "NN";

/** La pastille d'une note : toujours un mot, la couleur n'est qu'un renfort. */
export function gradeStateChip(code: GradeCode): { label: string; tone: "ok" | "bad" | "info" } {
  switch (code) {
    case "S":
      return { label: "Succès", tone: "ok" };
    case "V":
      return { label: "Validé", tone: "ok" };
    case "E":
      return { label: "Échec", tone: "bad" };
    default:
      return { label: "Pas encore noté", tone: "info" };
  }
}

/** « Session 1 · 2025-2026 » */
export function sessionLabel(s: { name: string; year: string }): string {
  return `${s.name} · ${s.year}`;
}

const longDate = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", year: "numeric" });

/** « 2025-09-22 » → « Lundi 22 septembre 2025 ». Lue en date LOCALE : pas de décalage d'un jour selon le fuseau. */
export function formatLongDate(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  if (!m) return iso;
  const text = longDate.format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  return text.charAt(0).toUpperCase() + text.slice(1);
}
