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

/** 72 000 ms → « 1 min 12 s » ; 42 000 ms → « 42 s ». Arrondi à la seconde, jamais « 0 s » pour une vraie durée. */
export function formatDuration(ms: number): string {
  const total = Math.max(1, Math.round(ms / 1000));
  const min = Math.floor(total / 60);
  const sec = total % 60;
  if (min === 0) return `${sec} s`;
  return sec === 0 ? `${min} min` : `${min} min ${sec} s`;
}

/** 1 → « 1er », 3 → « 3e » (ordinal français). */
export function formatRank(rank: number): string {
  return rank === 1 ? "1er" : `${rank}e`;
}

/** « 1 partie », « 3 parties » : jamais « partie(s) ». 0 et 1 sont au singulier (usage français). */
export function plural(n: number, one: string, many: string = `${one}s`): string {
  return `${n} ${n < 2 ? one : many}`;
}

const ariary = new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 });

/** 1500000 → « 1 500 000 Ar » (espaces insécables : un montant ne se coupe jamais en fin de ligne). */
export function formatAr(value: number): string {
  return `${ariary.format(value).replace(/[\s\u202f]/g, "\u00a0")}\u00a0Ar`;
}

const dateFr = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", year: "numeric" });

/** « 2001-03-12 » → « 12 mars 2001 ». Lue en date LOCALE (pas de décalage d'un jour) ; une valeur qui n'est pas une date reste telle quelle. */
export function formatDate(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  if (!m) return iso;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return d.getMonth() === Number(m[2]) - 1 ? dateFr.format(d) : iso;
}

/** Masque un numéro sensible : tout sauf les 4 derniers caractères (« 101 202 303 404 » → « •••• •••• 3404 »). */
export function maskTail(value: string, keep = 4): string {
  const compact = value.replace(/\s+/g, "");
  if (compact.length <= keep) return "•".repeat(compact.length);
  return `${"•".repeat(compact.length - keep).replace(/(.{4})(?=.)/g, "$1 ")} ${compact.slice(-keep)}`.trim();
}

/** 83 000 ms → « 01:23 » ; au-delà d'une heure « 1:02:03 ». Jamais de valeur négative ni de « NaN ». */
export function formatClock(ms: number): string {
  const total = Number.isFinite(ms) && ms > 0 ? Math.floor(ms / 1000) : 0;
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const two = (n: number) => String(n).padStart(2, "0");
  return h > 0 ? `${h}:${two(m)}:${two(s)}` : `${two(m)}:${two(s)}`;
}
