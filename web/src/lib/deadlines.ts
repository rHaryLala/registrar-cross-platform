/**
 * Échéances de paiement (finance) : formes des réponses de l'API (/api/v1/staff/finance/deadlines) et fonctions pures (repères, contrôle de l'ordre,
 * reprise des dates de l'an dernier). Les jours sont comptés en UTC : « 2026-12-11 » est le même jour partout.
 */

export type DeadlineType = { label: string; parts: number[]; proposed: boolean };
export type DeadlineSession = { id: number; label: string; year: string; semester: number; start: string | null; end: string | null; configured: boolean };
/** type → rang (1…) → « AAAA-MM-JJ » */
export type Dates = Record<string, Record<number, string>>;

export type DeadlinesPage = {
  types_all: Record<string, DeadlineType>;
  sessions: DeadlineSession[];
  table_exists: boolean;
  session: DeadlineSession | null;
  previous?: number | null;
  next?: number | null;
  dates?: Dates;
  types?: Record<string, DeadlineType>;
  counts?: Record<string, number>;
  without_type?: number;
  enrolled?: number;
  filled?: number;
  expected?: number;
  copy?: { session_id: number; label: string; dates: Dates } | null;
};

export type SaveResult = { changed: number; dates: Dates; message: string };

/** Ce qui est affiché dans les champs : type → rang → texte (vide = pas de date). */
export type Draft = Record<string, Record<number, string>>;

export const deadlinesKeys = { root: ["staff", "finance", "deadlines"] as const, one: (session: number | null) => ["staff", "finance", "deadlines", session] as const };

export const deadlinesUrl = (session: number | null): string => `/api/v1/staff/finance/deadlines${session !== null ? `?session=${session}` : ""}`;
export const saveUrl = (session: number): string => `/api/v1/staff/finance/deadlines/${session}`;

const DAY = 86400000;
const MONTHS = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];

/** « 2026-12-11 » → numéro de jour (UTC) ; null si le texte n'est pas une date réelle (30 février, 5/1/2026…). */
export function dayNumber(ymd: string | null | undefined): number | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd ?? "");
  if (!m) return null;
  const t = Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return new Date(t).getUTCMonth() === Number(m[2]) - 1 ? Math.round(t / DAY) : null;
}

export const toYmd = (day: number): string => new Date(day * DAY).toISOString().slice(0, 10);

/** « 12 oct. 2026 » (espace insécable entre le jour et le mois, comme l'ancienne page). */
export function shortDate(day: number): string {
  const d = new Date(day * DAY);
  return `${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
}

export const ordinal = (n: number): string => (n === 1 ? "1re" : `${n}e`);

const days = (n: number): string => `${n} jour${n > 1 ? "s" : ""}`;

/** Où tombe une date : « Vendredi, 3e semaine de la session », « 12 jours avant le début de la session »… Vide : « À définir ». */
export function hint(value: string, start: string | null, end: string | null): { text: string; tone: "empty" | "error" | "alert" | "ok" } {
  const j = dayNumber(value);
  if (j === null) return value === "" ? { text: "À définir", tone: "empty" } : { text: "Date illisible", tone: "error" };
  const name = new Intl.DateTimeFormat("fr-FR", { weekday: "long", timeZone: "UTC" }).format(new Date(j * DAY));
  const s0 = dayNumber(start);
  const s1 = dayNumber(end);
  let where = "";
  let tone: "alert" | "ok" = "ok";
  if (s0 !== null && j < s0) {
    where = `${days(s0 - j)} avant le début de la session`;
    tone = "alert";
  } else if (s1 !== null && j > s1) {
    where = `${days(j - s1)} après la fin de la session`;
    tone = "alert";
  } else if (s0 !== null) where = `${ordinal(Math.floor((j - s0) / 7) + 1)} semaine de la session`;
  return { text: name.charAt(0).toUpperCase() + name.slice(1) + (where ? `, ${where}` : ""), tone };
}

/** Les champs affichés au départ : ce qui est enregistré, pour les types montrés. */
export function draftFrom(dates: Dates, types: Record<string, DeadlineType>): Draft {
  const out: Draft = {};
  for (const [code, t] of Object.entries(types)) {
    out[code] = {};
    t.parts.forEach((_, i) => {
      out[code][i + 1] = dates[code]?.[i + 1] ?? "";
    });
  }
  return out;
}

/** Les erreurs de saisie : clé « TYPE:rang ». Une date illisible, ou une tranche qui ne vient pas APRÈS la précédente (les tranches vides sont sautées). */
export function errors(draft: Draft): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [code, slots] of Object.entries(draft)) {
    let previous: { day: number; rank: number } | null = null;
    for (const rank of Object.keys(slots).map(Number).sort((a, b) => a - b)) {
      const value = slots[rank];
      const day = dayNumber(value);
      if (value !== "" && day === null) {
        out[`${code}:${rank}`] = "Date illisible.";
        continue;
      }
      if (day !== null && previous !== null && day <= previous.day) out[`${code}:${rank}`] = `Après la ${ordinal(previous.rank)} tranche (${shortDate(previous.day)}), pas avant.`;
      if (day !== null) previous = { day, rank };
    }
  }
  return out;
}

/** Combien de champs diffèrent de ce qui est enregistré. */
export function changedCount(draft: Draft, saved: Dates): number {
  let n = 0;
  for (const [code, slots] of Object.entries(draft)) for (const [rank, value] of Object.entries(slots)) if (value !== (saved[code]?.[Number(rank)] ?? "")) n++;
  return n;
}

/** Ce qu'on envoie : tous les rangs des types montrés (un champ vidé retire la tranche). */
export function payload(draft: Draft): Dates {
  const out: Dates = {};
  for (const [code, slots] of Object.entries(draft)) out[code] = { ...slots };
  return out;
}

/** « Reprendre » : les dates de l'an dernier, 52 semaines plus tard (même jour de la semaine), pour les types montrés. */
export function shifted(previous: Dates, types: Record<string, DeadlineType>, weeks = 52): Draft {
  const out: Draft = {};
  for (const [code, t] of Object.entries(types)) {
    out[code] = {};
    t.parts.forEach((_, i) => {
      const day = dayNumber(previous[code]?.[i + 1]);
      out[code][i + 1] = day === null ? "" : toYmd(day + weeks * 7);
    });
  }
  return out;
}

/** Position d'une date sur la frise de la session, de 0 à 100 % ; null si la session n'a pas de période. */
export function position(value: string, start: string | null, end: string | null): number | null {
  const j = dayNumber(value);
  const s0 = dayNumber(start);
  const s1 = dayNumber(end);
  if (j === null || s0 === null || s1 === null || s1 <= s0) return null;
  return Math.max(0, Math.min(100, ((j - s0) / (s1 - s0)) * 100));
}

/** « 4 dates sur 7 », « Échéances à définir », « Échéances définies ». */
export function stateText(filled: number, expected: number): { text: string; tone: "alert" | "partial" | "ok" } {
  if (filled === 0) return { text: "Échéances à définir", tone: "alert" };
  return filled < expected ? { text: `${filled} dates sur ${expected}`, tone: "partial" } : { text: "Échéances définies", tone: "ok" };
}

/** Le nom d'une session dans la liste : « — à définir » tant qu'elle n'a aucune date (sauf la session affichée). */
export function optionText(s: DeadlineSession, shown: boolean): string {
  return s.label + (s.configured || shown ? "" : " — à définir");
}

/** « 2 modifications », « 1 date modifiée ». */
export function changesText(n: number): string {
  return `${n} date${n > 1 ? "s" : ""} modifiée${n > 1 ? "s" : ""}`;
}
