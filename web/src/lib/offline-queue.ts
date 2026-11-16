/**
 * File d'écritures hors ligne (espace enseignant).
 *
 * Principes (docs/ETUDE-05) :
 *  - le SERVEUR fait foi. Une écriture faite sans réseau part au retour du réseau, DATÉE de l'instant où l'enseignant l'a
 *    faite (`client_at`). Si la donnée visée a changé depuis, le serveur répond 409 : le changement n'est PAS appliqué, la
 *    personne choisit « appliquer quand même » (`force`) ou « ignorer » ;
 *  - chaque écriture est un « état voulu » (pointer, justificatif, réglage…) ou idempotente (ouvrir une séance, tous
 *    présents) : la renvoyer deux fois ne change rien de plus. On peut donc rejouer sans crainte après une coupure en
 *    plein envoi ;
 *  - l'ORDRE compte : une séance créée hors ligne n'a pas encore d'identifiant serveur. Elle porte un identifiant
 *    provisoire (nombre négatif) ; les écritures qui la visent s'écrivent `@tmp-3` dans leur adresse, et sont réécrites avec
 *    le vrai identifiant dès que la création a réussi ;
 *  - deux pointages du même étudiant dans la même séance se remplacent (seul le dernier compte) : la file reste petite.
 *
 * Ce module ne touche ni au navigateur ni au réseau : le stockage (`QueueStore`) et l'envoi (`Sender`) sont injectés,
 * ce qui permet de le tester à fond.
 */

export type Method = "PUT" | "POST" | "PATCH" | "DELETE";

export type Problem = { code: string; status: number; detail?: string; target?: string; modifiedAt?: string };

export type QueuedOp = {
  id: string;
  seq: number;
  account: number;
  /** Texte lisible dans la liste des changements en attente (« Pointer · Rakoto Jean : absent »). */
  label: string;
  method: Method;
  /** Adresse relative ; peut contenir « @tmp-N » (séance créée hors ligne). */
  path: string;
  body: Record<string, unknown>;
  /** Instant de l'action, en millisecondes, déjà corrigé du décalage d'horloge de l'appareil. */
  clientAt: number;
  state: "pending" | "conflict" | "rejected";
  problem?: Problem;
  /** Opération qui crée une séance : identifiant provisoire (négatif) qu'elle résoudra. */
  tmp?: number;
  /** Deux opérations en attente de même clé : la plus récente remplace l'autre. */
  key?: string;
  /** « Appliquer quand même » choisi par la personne : part avec `force` et sans `client_at`. */
  force?: boolean;
};

export type NewOp = Omit<QueuedOp, "id" | "seq" | "state" | "problem">;

export interface QueueStore {
  list(account: number): Promise<QueuedOp[]>;
  put(op: QueuedOp): Promise<void>;
  remove(id: string): Promise<void>;
  /** identifiant provisoire → vrai identifiant, une fois la séance créée */
  resolved(account: number): Promise<Record<number, number>>;
  setResolved(account: number, tmp: number, real: number): Promise<void>;
}

/** Ce que l'envoi renvoie ; `status: 0` = pas de réseau. */
export type SendResult = { ok: true; data: unknown } | { ok: false; status: number; code: string; detail?: string; extra?: Record<string, unknown> };
export type Sender = (op: { method: Method; path: string; body: Record<string, unknown> }) => Promise<SendResult>;

export type DrainReport = { sent: number; conflicts: number; rejected: number; offline: boolean; unauthenticated: boolean; failed: boolean; resolved: Record<number, number> };

const TMP = /@tmp-(\d+)/g;

/** Un identifiant provisoire est toujours négatif ; son écriture dans une adresse est « @tmp-N ». */
export function tmpRef(id: number): string {
  return `@tmp-${Math.abs(id)}`;
}

export function isTmp(id: number): boolean {
  return id < 0;
}

/** Remplace chaque « @tmp-N » par son vrai identifiant ; null si l'un d'eux n'est pas (encore) résolu. */
export function resolvePath(path: string, resolved: Record<number, number>): string | null {
  let missing = false;
  const out = path.replace(TMP, (_, n: string) => {
    const real = resolved[-Number(n)];
    if (real === undefined) missing = true;
    return String(real);
  });
  return missing ? null : out;
}

export function dependsOnTmp(path: string): number[] {
  return [...path.matchAll(TMP)].map((m) => -Number(m[1]));
}

/** Ajoute une écriture à la file. Une écriture en attente de même clé est remplacée (l'état voulu est le dernier). */
export function enqueue(store: QueueStore, op: NewOp, newId: () => string = () => crypto.randomUUID()): Promise<QueuedOp> {
  // Une à la fois : deux gestes quasi simultanés liraient la même file et recevraient le MÊME numéro d'ordre (ordre d'envoi alors indéterminé).
  const run = enqueueChain.then(() => enqueueNow(store, op, newId));
  enqueueChain = run.then(() => undefined, () => undefined);
  return run;
}

let enqueueChain: Promise<void> = Promise.resolve();

async function enqueueNow(store: QueueStore, op: NewOp, newId: () => string): Promise<QueuedOp> {
  const all = await store.list(op.account);
  if (op.key) {
    for (const old of all) {
      if (old.key === op.key && old.state === "pending") await store.remove(old.id);
    }
  }
  const seq = all.reduce((m, o) => Math.max(m, o.seq), 0) + 1;
  const queued: QueuedOp = { ...op, id: newId(), seq, state: "pending" };
  await store.put(queued);
  return queued;
}

let draining: Promise<DrainReport> | null = null;

/**
 * Envoie, dans l'ordre, les écritures en attente. S'arrête au premier échec de réseau (le reste attendra) ; un conflit ou un
 * refus n'arrête pas les autres écritures, indépendantes. Un seul envoi à la fois dans cet onglet.
 */
export function drain(store: QueueStore, account: number, send: Sender): Promise<DrainReport> {
  draining ??= run(store, account, send).finally(() => {
    draining = null;
  });
  return draining;
}

async function run(store: QueueStore, account: number, send: Sender): Promise<DrainReport> {
  const report: DrainReport = { sent: 0, conflicts: 0, rejected: 0, offline: false, unauthenticated: false, failed: false, resolved: {} };
  const resolved = { ...(await store.resolved(account)) };
  const ops = (await store.list(account)).filter((o) => o.state === "pending").sort((a, b) => a.seq - b.seq);

  for (const op of ops) {
    const path = resolvePath(op.path, resolved);
    if (path === null) {
      // La séance dont dépend cette écriture n'a jamais été créée (refusée ou ignorée) : l'écriture n'a plus de cible.
      await store.put({ ...op, state: "rejected", problem: { code: "presence.session_missing", status: 0, detail: "La séance concernée n’a pas pu être créée : ce changement n’a plus d’objet." } });
      report.rejected++;
      continue;
    }
    const body = op.force ? { ...op.body, force: true } : { ...op.body, client_at: op.clientAt };
    const res = await send({ method: op.method, path, body });

    if (res.ok) {
      if (op.tmp !== undefined) {
        const real = (res.data as { data?: { session_id?: number } })?.data?.session_id;
        if (typeof real === "number") {
          resolved[op.tmp] = real;
          report.resolved[op.tmp] = real;
          await store.setResolved(account, op.tmp, real);
        }
      }
      await store.remove(op.id);
      report.sent++;
      continue;
    }
    if (res.status === 0) {
      report.offline = true;
      break;
    }
    if (res.status === 401 || res.status === 419) {
      report.unauthenticated = true;
      break;
    }
    if (res.status >= 500 || res.status === 429) {
      report.failed = true;
      break;
    }
    const problem: Problem = { code: res.code, status: res.status, detail: res.detail, target: res.extra?.target as string | undefined, modifiedAt: res.extra?.modified_at as string | undefined };
    if (res.status === 409) {
      await store.put({ ...op, state: "conflict", problem });
      report.conflicts++;
    } else {
      await store.put({ ...op, state: "rejected", problem });
      report.rejected++;
    }
  }
  return report;
}

/** « Appliquer quand même » : l'écriture repart avec `force`, datée de maintenant par le serveur. */
export async function applyAnyway(store: QueueStore, op: QueuedOp): Promise<void> {
  await store.put({ ...op, state: "pending", force: true, problem: undefined });
}

/** « Ignorer » : l'écriture est abandonnée, ainsi que celles qui visaient la séance qu'elle devait créer. */
export async function discard(store: QueueStore, op: QueuedOp): Promise<void> {
  await store.remove(op.id);
  if (op.tmp === undefined) return;
  for (const other of await store.list(op.account)) {
    if (dependsOnTmp(other.path).includes(op.tmp)) await store.remove(other.id);
  }
}

/** Les écritures qui attendent encore d'être envoyées (en conflit ou refusées : à traiter par la personne). */
export function counts(ops: QueuedOp[]): { pending: number; conflict: number; rejected: number } {
  return {
    pending: ops.filter((o) => o.state === "pending").length,
    conflict: ops.filter((o) => o.state === "conflict").length,
    rejected: ops.filter((o) => o.state === "rejected").length,
  };
}

/** Mémoire pour les tests et pour un navigateur sans IndexedDB. */
export class MemoryStore implements QueueStore {
  private ops = new Map<string, QueuedOp>();
  private map = new Map<string, number>();

  async list(account: number): Promise<QueuedOp[]> {
    return [...this.ops.values()].filter((o) => o.account === account).sort((a, b) => a.seq - b.seq);
  }
  async put(op: QueuedOp): Promise<void> {
    this.ops.set(op.id, op);
  }
  async remove(id: string): Promise<void> {
    this.ops.delete(id);
  }
  async resolved(account: number): Promise<Record<number, number>> {
    const out: Record<number, number> = {};
    for (const [k, v] of this.map) {
      const [a, t] = k.split("|");
      if (Number(a) === account) out[Number(t)] = v;
    }
    return out;
  }
  async setResolved(account: number, tmp: number, real: number): Promise<void> {
    this.map.set(`${account}|${tmp}`, real);
  }
}
