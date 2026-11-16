/**
 * Stockage hors ligne dans IndexedDB : la file des écritures, la correspondance « séance provisoire → vraie séance », et
 * les COPIES des dernières lectures (pour rouvrir un registre sans réseau).
 *
 * Tout est rangé PAR COMPTE (identifiant du compte en préfixe) : sur un appareil partagé, un autre compte ne voit jamais
 * ce qui appartient au précédent. À la déconnexion, tout le compte est effacé (`purgeAccount`). Les copies de plus de
 * 14 jours sont jetées (`MAX_AGE_MS`) : une donnée si ancienne induirait en erreur.
 *
 * Aucune erreur de stockage ne doit jamais casser l'écran : navigation privée, quota plein, base verrouillée… on retombe
 * sur la mémoire (la file vit alors le temps de l'onglet) ou on ignore la copie.
 */
import { MemoryStore, type QueuedOp, type QueueStore } from "@/lib/offline-queue";

export const MAX_AGE_MS = 14 * 24 * 3600 * 1000;

const DB_NAME = "registrar-offline";
const OPS = "ops";
const RESOLVED = "resolved";
const SNAPSHOTS = "snapshots";

type Snapshot = { key: string; account: number; savedAt: number; data: unknown };

let opened: Promise<IDBDatabase | null> | null = null;

function open(): Promise<IDBDatabase | null> {
  if (typeof indexedDB === "undefined") return Promise.resolve(null);
  opened ??= new Promise<IDBDatabase | null>((resolve) => {
    try {
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = () => {
        const db = req.result;
        db.createObjectStore(OPS, { keyPath: "id" }).createIndex("account", "account");
        db.createObjectStore(RESOLVED, { keyPath: "key" });
        db.createObjectStore(SNAPSHOTS, { keyPath: "key" }).createIndex("account", "account");
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
      req.onblocked = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
  return opened;
}

function wrap<T>(req: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function tx<T>(store: string, mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest<T>): Promise<T | undefined> {
  const db = await open();
  if (!db) return undefined;
  try {
    return await wrap(fn(db.transaction(store, mode).objectStore(store)));
  } catch {
    return undefined;
  }
}

/** La file des écritures : IndexedDB si possible, sinon la mémoire de l'onglet. */
export class IdbQueueStore implements QueueStore {
  private fallback = new MemoryStore();

  private async usable(): Promise<boolean> {
    return (await open()) !== null;
  }

  async list(account: number): Promise<QueuedOp[]> {
    if (!(await this.usable())) return this.fallback.list(account);
    const rows = (await tx<QueuedOp[]>(OPS, "readonly", (s) => s.index("account").getAll(account))) ?? [];
    return rows.sort((a, b) => a.seq - b.seq);
  }

  async put(op: QueuedOp): Promise<void> {
    if (!(await this.usable())) return this.fallback.put(op);
    await tx(OPS, "readwrite", (s) => s.put(op));
  }

  async remove(id: string): Promise<void> {
    if (!(await this.usable())) return this.fallback.remove(id);
    await tx(OPS, "readwrite", (s) => s.delete(id));
  }

  async resolved(account: number): Promise<Record<number, number>> {
    if (!(await this.usable())) return this.fallback.resolved(account);
    const rows = (await tx<{ key: string; account: number; tmp: number; real: number }[]>(RESOLVED, "readonly", (s) => s.getAll())) ?? [];
    const out: Record<number, number> = {};
    for (const r of rows) if (r.account === account) out[r.tmp] = r.real;
    return out;
  }

  async setResolved(account: number, tmp: number, real: number): Promise<void> {
    if (!(await this.usable())) return this.fallback.setResolved(account, tmp, real);
    await tx(RESOLVED, "readwrite", (s) => s.put({ key: `${account}|${tmp}`, account, tmp, real }));
  }
}

// -------------------------------------------------------------------- copies

/** Garde la dernière lecture réussie d'une adresse, pour la rouvrir sans réseau. */
export async function saveSnapshot(account: number, path: string, data: unknown): Promise<void> {
  const row: Snapshot = { key: `${account}|${path}`, account, savedAt: Date.now(), data };
  await tx(SNAPSHOTS, "readwrite", (s) => s.put(row));
}

/** La copie d'une adresse, ou null si elle n'existe pas ou a plus de 14 jours. */
export async function loadSnapshot<T>(account: number, path: string, now = Date.now()): Promise<{ data: T; savedAt: number } | null> {
  const row = await tx<Snapshot | undefined>(SNAPSHOTS, "readonly", (s) => s.get(`${account}|${path}`));
  if (!row || now - row.savedAt > MAX_AGE_MS) return null;
  return { data: row.data as T, savedAt: row.savedAt };
}

export async function deleteSnapshot(account: number, path: string): Promise<void> {
  await tx(SNAPSHOTS, "readwrite", (s) => s.delete(`${account}|${path}`));
}

/** Jette les copies de plus de 14 jours (appelé à l'ouverture de l'application). */
export async function purgeOld(now = Date.now()): Promise<void> {
  const db = await open();
  if (!db) return;
  try {
    const rows = (await wrap(db.transaction(SNAPSHOTS, "readonly").objectStore(SNAPSHOTS).getAll())) as Snapshot[];
    const old = rows.filter((r) => now - r.savedAt > MAX_AGE_MS);
    if (old.length === 0) return;
    const store = db.transaction(SNAPSHOTS, "readwrite").objectStore(SNAPSHOTS);
    for (const r of old) store.delete(r.key);
  } catch {
    /* sans importance */
  }
}

/** Efface TOUT ce que ce compte a laissé sur l'appareil (déconnexion). */
export async function purgeAccount(account: number): Promise<void> {
  const db = await open();
  if (!db) return;
  try {
    const t = db.transaction([OPS, RESOLVED, SNAPSHOTS], "readwrite");
    const drop = (name: string, index: string | null) => {
      const s = t.objectStore(name);
      const req = index ? s.index(index).openKeyCursor(IDBKeyRange.only(account)) : s.openCursor();
      req.onsuccess = () => {
        const c = req.result as IDBCursor | null;
        if (!c) return;
        const row = (c as IDBCursorWithValue).value as { account?: number } | undefined;
        if (index || row?.account === account) s.delete(c.primaryKey);
        c.continue();
      };
    };
    drop(OPS, "account");
    drop(SNAPSHOTS, "account");
    drop(RESOLVED, null);
    await new Promise<void>((resolve) => {
      t.oncomplete = () => resolve();
      t.onerror = () => resolve();
      t.onabort = () => resolve();
    });
  } catch {
    /* sans importance */
  }
}
