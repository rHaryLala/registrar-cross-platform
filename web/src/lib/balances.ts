/**
 * Solde de compte par session (finance) : formes des réponses de l'API (/api/v1/staff/finance/balances) et fonctions pures.
 *
 * Le solde est vu DU COMPTE de l'étudiant, comme dans l'ancienne page : une dette (débitaire) s'écrit « -150 000 Ar », une avance (créditaire) « +25 000 Ar ».
 * Côté serveur le solde est positif quand l'étudiant doit de l'argent.
 */
import type { Query } from "@/lib/directory";
import { queryString } from "@/lib/directory";

export type BalanceState = "debitaire" | "creditaire" | "solde" | "sans_ligne";

export type BalanceRow = {
  student_id: string;
  account_code: string;
  name: string;
  finance_id: string | null;
  balance: number;
  state: BalanceState;
  editable: boolean;
};

export type SessionChoice = { id: number; label: string; year: string; semester: string; selected: boolean };

export type BalancesPage = {
  sessions: SessionChoice[];
  session_id: number | null;
  total: number;
  shown: number;
  remaining: number;
  per_page: number;
  students_in_session: number;
  rows: BalanceRow[];
  counts: Record<BalanceState, number>;
  states: Record<BalanceState, string>;
  totals: { debit: number; credit: number; net: number };
  sorts: Record<string, { label: string; direction: "asc" | "desc" }>;
  state: { q: string; etat: BalanceState[]; sort: string; direction: "asc" | "desc"; page: number };
  balance_column: boolean;
};

export type SaveResult = { changed: boolean; row: BalanceRow };

export const STATE_ORDER: BalanceState[] = ["debitaire", "creditaire", "solde", "sans_ligne"];

export const STATE_LABEL: Record<BalanceState, string> = { debitaire: "Débitaire", creditaire: "Créditaire", solde: "Soldé", sans_ligne: "Sans ligne finance" };

export const STATE_HINT: Record<BalanceState, string> = {
  debitaire: "L’étudiant doit de l’argent",
  creditaire: "L’étudiant a versé plus que dû",
  solde: "Rien à payer ni à rendre",
  sans_ligne: "Aucune ligne finance pour cette session : le solde n’est pas modifiable",
};

export const balancesKeys = {
  root: ["staff", "finance", "balances"] as const,
  list: (session: number | null, query: Query) => ["staff", "finance", "balances", session, query] as const,
};

/** L'adresse de l'API : la session (si choisie), puis la recherche, les états, le tri et la page. */
export function balancesUrl(session: number | null, query: Query, page = 0): string {
  const qs = queryString(query, page);
  const sess = session !== null ? `session=${session}` : "";
  const all = [sess, qs.replace(/^\?/, "")].filter((x) => x !== "").join("&");
  return `/api/v1/staff/finance/balances${all ? `?${all}` : ""}`;
}

export const saveUrl = (session: number, financeId: string): string => `/api/v1/staff/finance/balances/${session}/${financeId}`;

/** « -150 000 Ar » (dette), « +25 000 Ar » (avance), « 0 Ar » : le signe est celui du compte de l'étudiant. */
export function formatBalance(balance: number): string {
  const grouped = Math.round(Math.abs(balance)).toLocaleString("fr-FR").replace(/[\u00a0\u202f]/g, " ");
  return `${balance > 0 ? "-" : balance < 0 ? "+" : ""}${grouped} Ar`;
}

/** Le solde à saisir : la valeur exacte avec une virgule décimale (« -25000,5 »), comme l'ancien éditeur. */
export function editText(balance: number): string {
  return String(balance).replace(".", ",");
}

/** Un montant saisi, lu comme le serveur : espaces (dont l'insécable) retirés, virgule décimale admise ; null si ce n'est pas un nombre de la plage de la colonne. */
export function parseAmount(text: string): number | null {
  const raw = text.replace(/[\s\u00a0\u202f]/g, "").replace(",", ".");
  if (raw === "" || !/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test(raw)) return null;
  const n = Number(raw);
  return Number.isFinite(n) && Math.abs(n) <= 9999999999999.99 ? Math.round((n + Number.EPSILON) * 100) / 100 : null;
}

/** « 2 249 étudiants », « 1 étudiant », « Aucun étudiant ». */
export function countText(total: number): string {
  return total === 0 ? "Aucun étudiant" : `${total.toLocaleString("fr-FR")} étudiant${total > 1 ? "s" : ""}`;
}

/** Choisit ou retire un état dans la recherche (plusieurs états : OU). */
export function toggleState(query: Query, state: BalanceState): Query {
  const current = query.filters.etat ?? [];
  const next = current.includes(state) ? current.filter((s) => s !== state) : [...current, state];
  const filters = { ...query.filters };
  if (next.length > 0) filters.etat = next;
  else delete filters.etat;
  return { ...query, filters };
}

/** Le solde par session : « Les sessions en cours d'abord » ; la session active porte une marque. */
export function sessionOptionText(s: SessionChoice): string {
  return s.selected ? `${s.label} · en cours` : s.label;
}
