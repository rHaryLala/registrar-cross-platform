/**
 * Autorisation de réinscription (finance) : formes des réponses de l'API (/api/v1/staff/finance/reenrolment) et fonctions pures.
 *
 * Un étudiant peut se réinscrire quand la caisse l'autorise ET que le registraire a validé son dossier. Chaque rôle ne touche que sa colonne ;
 * la réinitialisation globale est réservée au superadmin.
 */
import type { DirectoryPage, Query } from "@/lib/directory";
import { queryString } from "@/lib/directory";

export type Field = "authorization" | "file";
export type ReenrolmentState = "prets" | "attente_caisse" | "attente_dossier" | "aucun";

export type Flag = { value: boolean; by: string | null; at: string | null };

export type ReenrolmentRow = {
  id: number;
  student_id: string;
  last_name: string | null;
  first_name: string | null;
  mention: string | null;
  track: string | null;
  year: string | null;
  residence: string | null;
  level: number | null;
  level_label: string;
  authorization: Flag;
  file: Flag;
  state: ReenrolmentState;
};

export type ReenrolmentStats = { total: number; prets: number; attente_caisse: number; attente_dossier: number; aucun: number; autorises: number; dossiers_ok: number };
export type ReenrolmentRights = { authorization: boolean; file: boolean; reset: boolean };
export type LastBatch = { lot: string; at: string; by: string | null; rows: number; columns: Field[] };

export type ReenrolmentPage = Omit<DirectoryPage<"students">, "rows" | "list"> & {
  list: "reenrolment";
  rows: ReenrolmentRow[];
  stats: ReenrolmentStats;
  rights: ReenrolmentRights;
  last_batch: LastBatch | null;
  batches_available: boolean;
};

export type ToggleResult = { changed: boolean; row: ReenrolmentRow; stats: ReenrolmentStats; message: string };
export type BulkResult = { changed: number[]; unchanged: number; missing: number[]; stats: ReenrolmentStats; message: string };
export type ResetPreview = { by_field: Partial<Record<Field, number>>; students: number; blocked: number; available: boolean };
export type ResetResult = { lot: string; by_field: Partial<Record<Field, number>>; total: number; by: string; date: string; stats: ReenrolmentStats; message: string };
export type UndoResult = { restored: Partial<Record<Field, number[]>>; ignored: number; stats: ReenrolmentStats; message: string };

export const BULK_MAX = 100;
export const CONFIRMATION = "RÉINITIALISER";

const BASE = "/api/v1/staff/finance/reenrolment";

export const reenrolmentKeys = {
  root: ["staff", "finance", "reenrolment"] as const,
  list: (query: Query) => ["staff", "finance", "reenrolment", query] as const,
  preview: (fields: Field[]) => ["staff", "finance", "reenrolment", "preview", fields] as const,
};

export const reenrolmentUrl = (query: Query, page = 0): string => `${BASE}${queryString(query, page)}`;
export const toggleUrl = (id: number, field: Field): string => `${BASE}/students/${id}/${field}`;
export const bulkUrl = `${BASE}/bulk`;
export const previewUrl = (fields: Field[]): string => `${BASE}/reset/preview${fields.length ? `?${fields.map((f) => `columns[]=${f}`).join("&")}` : ""}`;
export const resetUrl = `${BASE}/reset`;
export const undoUrl = (lot: string): string => `${BASE}/reset/${lot}/undo`;

export const FIELD_LABEL: Record<Field, { column: string; on: string; off: string; verb: string; undo: string }> = {
  authorization: { column: "Caisse", on: "Autorisé", off: "Non autorisé", verb: "Autoriser", undo: "Retirer l’autorisation" },
  file: { column: "Dossier", on: "Validé", off: "Non validé", verb: "Valider le dossier", undo: "Retirer la validation" },
};

export const STATE_LABEL: Record<ReenrolmentState, string> = {
  prets: "Prêts à se réinscrire",
  attente_caisse: "En attente de la caisse",
  attente_dossier: "En attente du dossier",
  aucun: "Ni l’un ni l’autre",
};

export const STATE_ORDER: ReenrolmentState[] = ["prets", "attente_caisse", "attente_dossier", "aucun"];

/** « 12/09/2026 » : le jour lu dans le texte ISO du serveur (son fuseau fait foi, pas celui du navigateur). */
export function dayFr(iso: string | null): string | null {
  const m = iso ? /^(\d{4})-(\d{2})-(\d{2})/.exec(iso) : null;
  return m && m[1] !== "0000" ? `${m[3]}/${m[2]}/${m[1]}` : null;
}

/** « Prénom Nom · 12/09/2026 », ou chaîne vide quand personne n'a encore posé le drapeau. */
export function trace(flag: Flag): string {
  if (!flag.by) return "";
  const day = dayFr(flag.at);
  return day ? `${flag.by} · ${day}` : flag.by;
}

export function stateOf(authorization: boolean, file: boolean): ReenrolmentState {
  return authorization && file ? "prets" : file ? "attente_caisse" : authorization ? "attente_dossier" : "aucun";
}

/** Les droits d'écriture du compte, dits une fois en tête de page. */
export function canWrite(rights: ReenrolmentRights, field: Field): boolean {
  return rights[field];
}

/** Les actions groupées que CE compte peut lancer (une paire par colonne qu'il peut modifier). */
export function bulkActions(rights: ReenrolmentRights): { field: Field; value: boolean; label: string }[] {
  const out: { field: Field; value: boolean; label: string }[] = [];
  for (const field of ["authorization", "file"] as const) {
    if (!rights[field]) continue;
    out.push({ field, value: true, label: FIELD_LABEL[field].verb }, { field, value: false, label: FIELD_LABEL[field].undo });
  }
  return out;
}

/** Remplace une ligne dans une page de la liste (réponse du serveur après une bascule). */
export function patchRow(page: ReenrolmentPage, row: ReenrolmentRow, stats?: ReenrolmentStats): ReenrolmentPage {
  return { ...page, rows: page.rows.map((r) => (r.id === row.id ? row : r)), stats: stats ?? page.stats };
}

/** Écrit la valeur d'un drapeau dans une ligne (affichage immédiat, avant la réponse du serveur). */
export function withFlag(row: ReenrolmentRow, field: Field, value: boolean): ReenrolmentRow {
  const next = { ...row, [field]: { ...row[field], value } } as ReenrolmentRow;
  return { ...next, state: stateOf(next.authorization.value, next.file.value) };
}

/** Les compteurs après une bascule, sans attendre le serveur : l'étudiant change d'état, le total ne bouge pas. */
export function statsAfter(stats: ReenrolmentStats, row: ReenrolmentRow, field: Field, value: boolean): ReenrolmentStats {
  if (row[field].value === value) return stats;
  const after = withFlag(row, field, value);
  const next = { ...stats, [row.state]: stats[row.state] - 1, [after.state]: stats[after.state] + 1 };
  const flagKey = field === "authorization" ? "autorises" : "dossiers_ok";
  return { ...next, [flagKey]: stats[flagKey] + (value ? 1 : -1) };
}

/** La sélection tient-elle dans une action groupée ? */
export function selectionProblem(count: number): string | null {
  if (count === 0) return null;
  return count > BULK_MAX ? `Une action groupée porte sur ${BULK_MAX} étudiants au plus : retirez-en ${count - BULK_MAX}.` : null;
}

/** « 3 étudiants sélectionnés ». */
export function selectedText(count: number): string {
  return `${count.toLocaleString("fr-FR")} ${count < 2 ? "étudiant sélectionné" : "étudiants sélectionnés"}`;
}

/** La phrase de confirmation, tolérante comme celle du serveur : casse, accents et espaces autour ne comptent pas. */
export function confirmationOk(typed: string): boolean {
  return typed.trim().normalize("NFD").replace(/[̀-ͯ]/g, "").toUpperCase() === "REINITIALISER";
}

/** « 457 autorisations de la caisse et 460 validations de dossier, pour 917 étudiants ; 400 ne pourront plus se réinscrire. » */
export function resetScope(preview: ResetPreview): string {
  const parts: string[] = [];
  if (preview.by_field.authorization !== undefined) parts.push(`${preview.by_field.authorization.toLocaleString("fr-FR")} autorisation${preview.by_field.authorization > 1 ? "s" : ""} de la caisse`);
  if (preview.by_field.file !== undefined) parts.push(`${preview.by_field.file.toLocaleString("fr-FR")} validation${preview.by_field.file > 1 ? "s" : ""} de dossier`);
  if (parts.length === 0) return "Choisissez au moins une colonne à réinitialiser.";
  const students = `${preview.students.toLocaleString("fr-FR")} étudiant${preview.students > 1 ? "s" : ""}`;
  const blocked = preview.blocked > 0 ? ` ${preview.blocked.toLocaleString("fr-FR")} ${preview.blocked > 1 ? "ne pourront" : "ne pourra"} plus se réinscrire.` : "";
  return `${parts.join(" et ")}, pour ${students}.${blocked}`;
}

/** Combien de jours reste-t-il pour annuler ce lot ? (le serveur ne le propose que sept jours) */
export function batchText(batch: LastBatch): string {
  const day = dayFr(batch.at);
  const time = /T(\d{2}:\d{2})/.exec(batch.at)?.[1];
  const columns = batch.columns.length === 2 ? "autorisations et dossiers" : batch.columns[0] === "file" ? "dossiers" : "autorisations";
  return `Réinitialisation du ${day ?? "?"}${time ? ` à ${time}` : ""}${batch.by ? ` par ${batch.by}` : ""} : ${batch.rows.toLocaleString("fr-FR")} case${batch.rows > 1 ? "s" : ""} remise${batch.rows > 1 ? "s" : ""} à « non coché » (${columns}).`;
}
