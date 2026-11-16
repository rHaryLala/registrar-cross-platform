/**
 * Tableau de bord du personnel : formes des réponses de l'API (/api/v1/staff/dashboard) et textes en français composés à partir de faits.
 * Les mots restent ici (testables) ; le serveur ne renvoie que des nombres, des dates et des identifiants.
 */

export type ToDoItem =
  | { key: "reviews"; tone: Tone; count: number; since: string | null; link: string }
  | { key: "flags"; tone: Tone; count: number; link: string }
  | { key: "applications"; tone: Tone; count: number; in_progress: number; link: string }
  | { key: "ungraded"; tone: Tone; count: number; courses: number; year: string; list: { course_id: number | null; code: string; title: string; teacher: string | null; count: number }[] }
  | { key: "no_teacher"; tone: Tone; count: number; year: string; list: { course_id: number; code: string; title: string; session: string }[] }
  | { key: "duplicate_codes"; tone: Tone; count: number; list: { code: string; course_ids: number[] }[] };

export type Tone = "attention" | "info";

export type Breakdown = { enrolled: number; new: number; bachelor: number; master: number; women: number; men: number; boarding: number; bungalow: number; external: number };

export type Dashboard = {
  year: string;
  years: string[];
  previous: string;
  is_current: boolean;
  to_do: ToDoItem[];
  figures: {
    current: Breakdown;
    before: Breakdown;
    same_date: number | null;
    mentions: { code: string; label: string; count: number; before: number }[];
    applications: { received: number; accepted: number; before: number } | null;
  };
};

export const dashboardKeys = { one: (year: string | null) => ["staff", "dashboard", year ?? "current"] as const };

export function dashboardUrl(year: string | null): string {
  return `/api/v1/staff/dashboard${year ? `?year=${encodeURIComponent(year)}` : ""}`;
}

/** « 1 280 » (espace insécable fin des milliers, à la française). */
export function nombre(n: number): string {
  return n.toLocaleString("fr-FR");
}

/** « 1 remise », « 3 remises » : le pluriel dès 2, le nombre en toutes lettres de chiffres. */
export function accord(n: number, one: string, many: string): string {
  return `${nombre(n)} ${n > 1 ? many : one}`;
}

/** « 2025 - 2026 » → « 2025-2026 » (dans les textes). */
export function shortYear(year: string): string {
  return year.replace(" - ", "-");
}

/** « 2026-11-10 10:00:00 » → « il y a 5 jours », « hier », « aujourd'hui » ; `now` se règle pour les tests. */
export function since(stamp: string, now: Date = new Date()): string {
  const then = new Date(stamp.replace(" ", "T"));
  if (Number.isNaN(then.getTime())) return "";
  const days = Math.floor((now.getTime() - then.getTime()) / 86_400_000);
  return days <= 0 ? "aujourd’hui" : days === 1 ? "hier" : `il y a ${days} jours`;
}

/** Le titre et le détail d'un élément « à traiter ». */
export function toDoText(item: ToDoItem, now: Date = new Date()): { title: string; detail: string } {
  switch (item.key) {
    case "reviews":
      return { title: `${accord(item.count, "remise de notes", "remises de notes")} à valider`, detail: item.since ? `la plus ancienne transmise ${since(item.since, now)}` : "" };
    case "flags":
      return { title: `${accord(item.count, "signalement", "signalements")} d’absences (15 %)`, detail: "à examiner avant les examens" };
    case "applications":
      return {
        title: accord(item.count, "nouvelle candidature", "nouvelles candidatures"),
        detail: item.in_progress > 0 ? `${accord(item.in_progress, "autre", "autres")} en cours d’instruction` : "à instruire",
      };
    case "ungraded":
      return {
        title: `${accord(item.count, "note non saisie", "notes non saisies")} en ${shortYear(item.year)}`,
        detail: `${accord(item.courses, "cours concerné", "cours concernés")}${item.courses > item.list.length ? ` · les ${item.list.length} plus en retard ci-dessous` : ""}`,
      };
    case "no_teacher":
      return { title: `${accord(item.count, "cours offert", "cours offerts")} sans enseignant`, detail: shortYear(item.year) };
    case "duplicate_codes":
      return { title: `${accord(item.count, "sigle affiché", "sigles affichés")} par deux cours`, detail: "renommez l’un des cours pour les distinguer" };
  }
}

export type Trend = { value: number; sign: "up" | "down" | "flat"; text: string };

/** L'écart avec l'année précédente : « +14 », « −3 », « 0 » (signe moins typographique). */
export function trend(current: number, before: number): Trend {
  const value = current - before;
  const sign = value > 0 ? "up" : value < 0 ? "down" : "flat";
  return { value, sign, text: `${value > 0 ? "+" : value < 0 ? "−" : ""}${nombre(Math.abs(value))}` };
}

/** Part en pourcentage entier ; 0 quand il n'y a rien à diviser. */
export function share(n: number, total: number): number {
  return total > 0 ? Math.round((n / total) * 100) : 0;
}

/** Les parts d'une répartition, avec le reste « non renseigné » quand elles ne totalisent pas les inscrits. */
export function split(total: number, parts: [label: string, n: number, tone: string][]): { label: string; n: number; tone: string; pct: number }[] {
  const out = parts.filter(([, n]) => n > 0).map(([label, n, tone]) => ({ label, n, tone, pct: share(n, total) }));
  const unknown = total - parts.reduce((s, [, n]) => s + n, 0);
  if (unknown > 0) out.push({ label: "Non renseigné", n: unknown, tone: "x", pct: share(unknown, total) });
  return out;
}

/** Le lien d'une ligne de liste (fiche d'un cours), ou null. */
export function courseLink(id: number | null): string | null {
  return id === null ? null : `/cours/fiche/?id=${id}`;
}
