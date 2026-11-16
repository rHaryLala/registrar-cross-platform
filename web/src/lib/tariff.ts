/**
 * Barème financier par session (finance) : formes des réponses de l'API (/api/v1/staff/finance/tariff) et fonctions pures (saisie, contrôle, charge envoyée).
 *
 * Le barème a deux étages : le BARÈME DE BASE (repli de toute session) et la SURCHARGE d'une session, qui n'existe que si on l'a ajustée. Une case sans montant
 * propre à la session affiche celui de la base (« hérité ») ; la modifier crée la surcharge, en partant de la base.
 */
import { parseAmount } from "@/lib/balances";

export type Source = "session" | "base" | "none";
export type Cell = { source: Source; values: Record<string, number> | null };
export type Field = { column: string; label: string };
export type ExtraCell = { amount: number | null; inherited: boolean };

export type MentionCard = {
  sigle: string;
  label: string;
  adjusted: boolean;
  no_tariff: boolean;
  fields: Field[];
  grid: Record<string, Cell>;
  extra: Record<string, Record<string, ExtraCell>>;
};

export type Fee = { id: number; label: string; active: boolean; order: number; condition: string; semesters: string };
export type TariffSession = { id: number; name: string; year: string; semester: number };

export type TariffPage = {
  years: string[];
  year: string;
  sessions: TariffSession[];
  semester: number;
  session: TariffSession | null;
  editing_base: boolean;
  target: number;
  levels: { level: number; label: string; category: string }[];
  statuses: string[];
  mentions: MentionCard[];
  adjusted_count: number;
  mention_count: number;
  extra_fees: Fee[];
  extra_fee_conditions: Record<string, string>;
  extra_fees_installed: boolean;
};

export type SaveResult = { session_id: number; mentions: string[]; semester: number; written: number; created: number; extra_written: number; extra_cleared: number; message: string };

/** Ce qui a été modifié dans une carte : colonne → texte saisi, par case « niveau|statut » ; frais → texte (vide = retour à la base). */
export type Draft = { amounts: Record<string, Record<string, string>>; extra: Record<string, Record<string, string>> };
export const EMPTY_DRAFT: Draft = { amounts: {}, extra: {} };

export const tariffKeys = { root: ["staff", "finance", "tariff"] as const, one: (year: string | null, semester: number | null, base: boolean) => ["staff", "finance", "tariff", year, semester, base] as const };

export function tariffUrl(year: string | null, semester: number | null, base: boolean): string {
  const p = new URLSearchParams();
  if (year) p.set("annee", year);
  if (semester) p.set("semestre", String(semester));
  if (base) p.set("base", "1");
  const qs = p.toString();
  return `/api/v1/staff/finance/tariff${qs ? `?${qs}` : ""}`;
}

export const SAVE_URL = "/api/v1/staff/finance/tariff";
export const COPY_URL = "/api/v1/staff/finance/tariff/copy";
export const RESET_URL = "/api/v1/staff/finance/tariff/reset";
export const FEES_URL = "/api/v1/staff/finance/extra-fees";
export const feeUrl = (id: number): string => `${FEES_URL}/${id}`;

export const cellKey = (level: number, status: string): string => `${level}|${status}`;

/** Un montant saisi, non négatif (le barème n'a pas de montant négatif) ; null si illisible. */
export function parseTariffAmount(text: string): number | null {
  const n = parseAmount(text);
  return n !== null && n >= 0 && n <= 999999999999 ? n : null;
}

/** Le montant d'une case, pour le champ de saisie : « 150000 » ou « 12,5 » ; vide si la case n'a pas de tarif. */
export function inputText(value: number | null | undefined): string {
  return value === null || value === undefined ? "" : String(value).replace(".", ",");
}

/** Le texte affiché dans une case : ce qui a été saisi, sinon la valeur enregistrée (propre à la session ou héritée de la base). */
export function shownText(draft: Draft, card: MentionCard, key: string, column: string): string {
  const typed = draft.amounts[key]?.[column];
  return typed !== undefined ? typed : inputText(card.grid[key]?.values?.[column]);
}

export function shownExtra(draft: Draft, card: MentionCard, feeId: number, key: string): string {
  const typed = draft.extra[String(feeId)]?.[key];
  return typed !== undefined ? typed : inputText(card.extra[String(feeId)]?.[key]?.amount);
}

/** Modifie une case du brouillon ; rétablir la valeur enregistrée retire la modification. */
export function withAmount(draft: Draft, card: MentionCard, key: string, column: string, text: string): Draft {
  const saved = inputText(card.grid[key]?.values?.[column]);
  const amounts = { ...draft.amounts, [key]: { ...draft.amounts[key] } };
  if (text === saved) delete amounts[key][column];
  else amounts[key][column] = text;
  if (Object.keys(amounts[key]).length === 0) delete amounts[key];
  return { ...draft, amounts };
}

export function withExtra(draft: Draft, card: MentionCard, feeId: number, key: string, text: string): Draft {
  const saved = inputText(card.extra[String(feeId)]?.[key]?.amount);
  const id = String(feeId);
  const extra = { ...draft.extra, [id]: { ...draft.extra[id] } };
  if (text === saved) delete extra[id][key];
  else extra[id][key] = text;
  if (Object.keys(extra[id]).length === 0) delete extra[id];
  return { ...draft, extra };
}

export function dirtyCount(draft: Draft): number {
  let n = 0;
  for (const cols of Object.values(draft.amounts)) n += Object.keys(cols).length;
  for (const cells of Object.values(draft.extra)) n += Object.keys(cells).length;
  return n;
}

/** Les erreurs de saisie : clé « niveau|statut|colonne » ou « extra|id|niveau|statut ». Un champ de barème vide n'est pas une erreur : il n'est simplement pas envoyé. */
export function draftErrors(draft: Draft): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, cols] of Object.entries(draft.amounts)) for (const [column, text] of Object.entries(cols)) if (text.trim() !== "" && parseTariffAmount(text) === null) out[`${key}|${column}`] = "Montant invalide";
  for (const [id, cells] of Object.entries(draft.extra)) for (const [key, text] of Object.entries(cells)) if (text.trim() !== "" && parseTariffAmount(text) === null) out[`extra|${id}|${key}`] = "Montant invalide";
  return out;
}

/** La charge envoyée : les seuls champs modifiés et lisibles ; une case de frais vidée est envoyée vide (retour à la base). */
export function savePayload(draft: Draft, sessionId: number, mention: string, others: string[], semester: number) {
  const amounts: Record<string, Record<string, number>> = {};
  for (const [key, cols] of Object.entries(draft.amounts))
    for (const [column, text] of Object.entries(cols)) {
      const n = parseTariffAmount(text);
      if (n !== null && text.trim() !== "") (amounts[key] ??= {})[column] = n;
    }
  const extra: Record<string, Record<string, number | "">> = {};
  for (const [id, cells] of Object.entries(draft.extra))
    for (const [key, text] of Object.entries(cells)) {
      if (text.trim() === "") (extra[id] ??= {})[key] = "";
      else {
        const n = parseTariffAmount(text);
        if (n !== null) (extra[id] ??= {})[key] = n;
      }
    }
  return { session_id: sessionId, mention, mentions: others, semester, amounts, extra };
}

export function stateText(card: MentionCard, editingBase: boolean): { text: string; tone: "alert" | "own" | "base" } | null {
  if (card.no_tariff) return { text: "Aucun tarif défini : les inscriptions de cette filière échoueront", tone: "alert" };
  if (editingBase) return null;
  return card.adjusted ? { text: "Tarif propre à cette session", tone: "own" } : { text: "Hérite du barème de base", tone: "base" };
}

/** « 3 / 7 filières ajustées », « Aucun ajustement : hérite du barème de base ». */
export function adjustedText(adjusted: number, total: number): string {
  return adjusted > 0 ? `${adjusted} / ${total} filière${total > 1 ? "s" : ""} ajustée${adjusted > 1 ? "s" : ""}` : "Aucun ajustement : hérite du barème de base";
}

export function modifiedText(n: number): string {
  return `${n} montant${n > 1 ? "s" : ""} modifié${n > 1 ? "s" : ""}`;
}

/** Les semestres d'un frais en cases à cocher : « 1,2 » → [1, 2] ; vide = tous. */
export function semestersOf(text: string): number[] {
  return text.trim() === "" ? [] : text.split(",").map((s) => Number(s.trim())).filter((n) => n >= 1 && n <= 4);
}

export function semestersText(selected: number[]): string {
  return [...new Set(selected)].sort().join(",");
}
