/**
 * Validation des remises de notes (registrariat) : formes des réponses de l'API (/api/v1/review/…) et petites fonctions pures.
 *
 * « Valider » ne touche pas aux notes : le serveur ouvre une fenêtre d'annulation de 10 secondes. L'écran attend la fin du délai puis demande la
 * finalisation ; si l'onglet se ferme, le serveur la fait lui-même (commande planifiée).
 */
import type { Check, Component, JournalEntry, Note, SheetStatus } from "@/lib/grading";

export type QueueItem = {
  id: number;
  course_id: number;
  code: string | null;
  title: string | null;
  year: string;
  semester: number | null;
  status: SheetStatus;
  teacher: string | null;
  submitted_at: string | null;
  processed_at: string | null;
  reject_reason: string | null;
  modified_at: string | null;
  pending_until: string | null;
  summary: { ready: number; total: number; blockers: number; warnings: number } | null;
};
export type Queue = { items: QueueItem[]; years: string[]; counts: Partial<Record<SheetStatus, number>> };

export type ReviewLine = {
  student_id: string;
  last_name: string | null;
  first_name: string | null;
  values: Record<string, number | null>;
  statut_special: string | null;
  commentaire: string | null;
  note: Note;
  /** note actuellement en base (celle que la validation remplacerait) */
  current_grade: string | null;
  /** ce que la validation écrirait (null : ligne sans inscription liée) */
  new_grade: string | null;
  linked: boolean;
};

export type Review = {
  id: number;
  course: { id: number; code: string | null; title: string | null; teacher: string | null };
  year: string;
  semester: number | null;
  status: SheetStatus;
  reject_reason: string | null;
  submitted_at: string | null;
  processed_at: string | null;
  components: Component[];
  total_weight: number;
  lines: ReviewLine[];
  check: Check;
  changes: number;
  pending: { until: string; remaining_ms: number } | null;
  can_approve: boolean;
  journal: JournalEntry[];
  undo_seconds: number;
};

export const reviewKeys = {
  queue: (status: string | null, year: string | null) => ["review", "queue", status ?? "all", year ?? "all"] as const,
  one: (id: number) => ["review", "one", id] as const,
};

const nf = new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 2 });

/** La valeur stockée dans `grade` : une note, ou un code (-2 validé, -3 échec, -4 pas encore noté). */
export function gradeLabel(grade: string | null): string {
  if (grade === null || grade === "") return "—";
  if (grade === "-2") return "Validé";
  if (grade === "-3") return "Échec";
  if (grade === "-4") return "Pas encore noté";
  const n = Number(grade.replace(",", "."));
  return Number.isFinite(n) ? nf.format(n) : grade;
}

/** Une ligne change-t-elle quelque chose dans le bulletin si on valide ? */
export function willChange(l: Pick<ReviewLine, "current_grade" | "new_grade">): boolean {
  return l.new_grade !== null && (l.current_grade ?? "") !== l.new_grade;
}

/** Secondes restantes à afficher (arrondies vers le haut : « 1 s » jusqu'à la toute fin). */
export function secondsLeft(remainingMs: number): number {
  return Math.max(0, Math.ceil(remainingMs / 1000));
}

export function reviewUrl(id: number): string {
  return `/validations/feuille/?id=${id}`;
}

// ---------------------------------------------------------------- validation groupée

/** Nombre maximal de feuilles par demande groupée (le même que l'API). */
export const BULK_MAX = 25;

export type BulkSkipped = { id: number; code: string; title: string; detail: string | null; remaining_ms?: number };
export type BulkApprove = { approved: { id: number; until: string; remaining_ms: number }[]; skipped: BulkSkipped[]; seconds: number };
export type BulkUndo = { undone: { id: number }[]; skipped: BulkSkipped[] };
export type BulkFinalize = { validated: { id: number; written: number | null; already: boolean }[]; skipped: BulkSkipped[] };

/** Une feuille peut-elle partir dans une validation groupée : transmise, sans point bloquant, et pas déjà en cours de validation. */
export function isSelectable(item: Pick<QueueItem, "status" | "summary" | "pending_until">): boolean {
  return item.status === "soumise" && item.summary !== null && item.summary.blockers === 0 && !item.pending_until;
}

/** Ajoute ou retire une feuille de la sélection ; au plafond, une nouvelle feuille n'est pas ajoutée. */
export function toggleSelection(selected: ReadonlySet<number>, id: number): Set<number> {
  const next = new Set(selected);
  if (next.has(id)) next.delete(id);
  else if (next.size < BULK_MAX) next.add(id);
  return next;
}

/** Les feuilles sélectionnables de la liste, dans l'ordre affiché, jusqu'au plafond. */
export function selectAll(items: readonly QueueItem[]): Set<number> {
  return new Set(items.filter(isSelectable).slice(0, BULK_MAX).map((i) => i.id));
}

/** Ne garde de la sélection que ce qui est encore sélectionnable dans la liste actuelle (la liste a pu changer). */
export function pruneSelection(selected: ReadonlySet<number>, items: readonly QueueItem[]): Set<number> {
  const ok = new Set(items.filter(isSelectable).map((i) => i.id));
  return new Set([...selected].filter((id) => ok.has(id)));
}

/** Texte de fin d'une validation groupée : ce qui est validé, et pourquoi le reste ne l'est pas. */
export function bulkOutcome(validated: number, skipped: readonly BulkSkipped[]): { title: string; description?: string; tone: "success" | "warning" | "error" } {
  const feuilles = (n: number) => `${n} feuille${n < 2 ? "" : "s"}`;
  if (skipped.length === 0) return { title: `${feuilles(validated)} validée${validated < 2 ? "" : "s"}`, tone: "success" };
  const why = skipped.map((s) => `#${s.id} : ${s.detail ?? s.title}`).join(" · ");
  if (validated === 0) return { title: "Aucune feuille validée", description: why, tone: "error" };
  return { title: `${feuilles(validated)} validée${validated < 2 ? "" : "s"}, ${skipped.length} non validée${skipped.length < 2 ? "" : "s"}`, description: why, tone: "warning" };
}
