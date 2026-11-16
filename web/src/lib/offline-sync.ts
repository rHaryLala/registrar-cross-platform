"use client";

/**
 * Synchronisation hors ligne de l'espace enseignant : l'unique endroit qui parle à la fois à la file (offline-queue),
 * au stockage (offline-db) et au réseau.
 *
 * Ce que ça garantit :
 *  - tant qu'une écriture attend dans la file, toute nouvelle écriture la suit (même en ligne) : l'ORDRE des gestes de
 *    l'enseignant est celui que le serveur verra ;
 *  - l'envoi reprend tout seul (retour du réseau, retour sur l'onglet, toutes les 30 secondes : certains navigateurs, dont
 *    Safari sur iPhone, n'offrent pas la synchronisation en arrière-plan) ;
 *  - une lecture réussie est gardée (copie) : sans réseau on rouvre la dernière copie, signalée comme telle ;
 *  - l'instant d'une écriture est celui du SERVEUR (horloge corrigée du décalage de l'appareil) ;
 *  - à la déconnexion, tout ce que le compte a laissé sur l'appareil est effacé.
 */
import type { QueryClient } from "@tanstack/react-query";
import { useSyncExternalStore } from "react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { applyAnyway, counts, discard, drain, enqueue, type DrainReport, type Method, type QueuedOp, type Sender } from "@/lib/offline-queue";
import { IdbQueueStore, deleteSnapshot, loadSnapshot, purgeAccount, purgeOld, saveSnapshot } from "@/lib/offline-db";
import { plural } from "@/lib/format";

export type Fetched<T> = { data: T; /** instant de la copie si ce sont des données gardées (hors ligne), sinon null */ copyAt: number | null };

type State = { account: number | null; ops: QueuedOp[]; syncing: boolean; resolved: Record<number, number> };

const EMPTY: State = { account: null, ops: [], syncing: false, resolved: {} };
let state: State = EMPTY;
const listeners = new Set<() => void>();
const store = new IdbQueueStore();
let client: QueryClient | null = null;
let skewMs = 0;
let tmpCounter = 0;

// Les écrans enfants lancent leurs lectures AVANT que l'en-tête (parent) ait déclaré le compte : on les fait patienter un instant.
let announceAccount: () => void = () => undefined;
let accountKnown = new Promise<void>((resolve) => {
  announceAccount = resolve;
});

async function whenReady(): Promise<void> {
  if (state.account !== null) return;
  await Promise.race([accountKnown, new Promise<void>((resolve) => setTimeout(resolve, 3000))]);
}

function set(patch: Partial<State>) {
  state = { ...state, ...patch };
  listeners.forEach((l) => l());
}

export function subscribe(l: () => void): () => void {
  listeners.add(l);
  return () => listeners.delete(l);
}

/** L'état de la file, pour l'écran. */
export function useQueue() {
  const s = useSyncExternalStore(subscribe, () => state, () => EMPTY);
  return { ...s, ...counts(s.ops) };
}

/** L'identifiant réel d'une séance créée hors ligne, une fois envoyée (ou l'identifiant lui-même). */
export function useRealSession(id: number): number {
  const s = useSyncExternalStore(subscribe, () => state, () => EMPTY);
  return id < 0 ? (s.resolved[id] ?? id) : id;
}

/** L'heure du serveur (le décalage est appris à chaque lecture). */
export function serverNow(): number {
  return Date.now() + skewMs;
}

export function noteServerTime(serverMs: number) {
  if (Number.isFinite(serverMs) && serverMs > 0) skewMs = serverMs - Date.now();
}

/** Prochain identifiant provisoire (négatif) pour une séance créée hors ligne. */
export function newTmpId(): number {
  tmpCounter += 1;
  return -tmpCounter;
}

// ------------------------------------------------------------------ démarrage

async function refresh() {
  if (state.account === null) return;
  const [ops, resolved] = await Promise.all([store.list(state.account), store.resolved(state.account)]);
  set({ ops, resolved });
  const used = [...ops.map((o) => o.tmp ?? 0), ...Object.keys(resolved).map(Number)].map((n) => Math.abs(n));
  tmpCounter = Math.max(tmpCounter, ...used, 0);
}

/** À appeler une fois connecté en tant qu'enseignant : charge la file et programme l'envoi automatique. */
export function startSync(account: number, queryClient: QueryClient): () => void {
  client = queryClient;
  set({ account });
  announceAccount();
  void purgeOld();
  void refresh().then(() => sync());

  const wake = () => void sync();
  const visible = () => document.visibilityState === "visible" && void sync();
  window.addEventListener("online", wake);
  window.addEventListener("focus", wake);
  document.addEventListener("visibilitychange", visible);
  const timer = window.setInterval(wake, 30_000);
  return () => {
    window.removeEventListener("online", wake);
    window.removeEventListener("focus", wake);
    document.removeEventListener("visibilitychange", visible);
    window.clearInterval(timer);
    set({ account: null, ops: [], resolved: {} });
    accountKnown = new Promise<void>((resolve) => {
      announceAccount = resolve;
    });
  };
}

// --------------------------------------------------------------------- envoi

const sender: Sender = async ({ method, path, body }) => {
  try {
    return { ok: true, data: await api<unknown>(path, { method, body }) };
  } catch (e) {
    if (e instanceof ApiError) return { ok: false, status: e.status, code: e.code, detail: e.detail, extra: e.extra };
    return { ok: false, status: 0, code: "network.offline" };
  }
};

/** Verrou entre onglets : deux onglets ouverts ne rejouent pas la même file en même temps (c'est sans danger, mais inutile). */
async function withTabLock<T>(fn: () => Promise<T>): Promise<T | null> {
  const locks = typeof navigator !== "undefined" ? (navigator as Navigator & { locks?: LockManager }).locks : undefined;
  if (!locks) return fn();
  return locks.request("registrar-sync", { ifAvailable: true }, async (lock) => (lock ? fn() : null));
}

/** Envoie la file. Silencieux s'il n'y a rien à envoyer ou pas de réseau. */
export async function sync(): Promise<DrainReport | null> {
  const account = state.account;
  if (account === null || state.syncing) return null;
  if (typeof navigator !== "undefined" && !navigator.onLine) return null;
  await refresh();
  if (!state.ops.some((o) => o.state === "pending")) return null;

  set({ syncing: true });
  try {
    const report = await withTabLock(() => drain(store, account, sender));
    await refresh();
    if (report) announce(report);
    if (report && report.sent > 0) void client?.invalidateQueries({ queryKey: ["teacher"] });
    return report;
  } finally {
    set({ syncing: false });
  }
}

function announce(r: DrainReport) {
  if (r.sent > 0) toast.success(`${plural(r.sent, "changement envoyé", "changements envoyés")}`, { description: "Vos modifications faites hors ligne sont enregistrées." });
  if (r.conflicts > 0) toast.warning(`${plural(r.conflicts, "changement à vérifier", "changements à vérifier")}`, { description: "Une donnée a changé entre-temps. Choisissez quoi faire dans la liste des changements en attente." });
  if (r.rejected > 0) toast.error(`${plural(r.rejected, "changement refusé", "changements refusés")}`, { description: "Voyez le détail dans la liste des changements en attente." });
  if (r.unauthenticated) toast.error("Session expirée", { description: "Reconnectez-vous : vos changements sont gardés et partiront ensuite." });
}

/** Ce que la personne décide d'un changement en conflit : l'appliquer quand même, ou l'ignorer. */
export async function resolveConflict(op: QueuedOp, choice: "apply" | "ignore") {
  if (choice === "apply") await applyAnyway(store, op);
  else await discard(store, op);
  await refresh();
  if (choice === "ignore") void client?.invalidateQueries({ queryKey: ["teacher"] });
  else void sync();
}

// ------------------------------------------------------------------- lecture

/** Lit une adresse ; sans réseau, rouvre la dernière copie (avec la date de la copie). */
export async function teacherGet<T>(path: string): Promise<Fetched<T>> {
  await whenReady();
  // Les changements en attente partent AVANT la lecture : sinon la lecture les ferait « disparaître » de l'écran.
  if (state.ops.some((o) => o.state === "pending")) await sync();
  const account = state.account;
  try {
    const res = await api<{ data: T; meta?: { server_time?: number } }>(path);
    if (res.meta?.server_time) noteServerTime(res.meta.server_time);
    if (account !== null) void saveSnapshot(account, path, res.data);
    return { data: res.data, copyAt: null };
  } catch (e) {
    if (e instanceof ApiError && e.isOffline && account !== null) {
      const copy = await loadSnapshot<T>(account, path);
      if (copy) return { data: copy.data, copyAt: copy.savedAt };
    }
    throw e;
  }
}

/** La copie locale d'une séance créée hors ligne (elle n'existe pas encore sur le serveur). */
export const localSessionPath = (id: number) => `local/session/${id}`;

export async function readLocal<T>(path: string): Promise<Fetched<T> | null> {
  await whenReady();
  if (state.account === null) return null;
  const copy = await loadSnapshot<T>(state.account, path);
  return copy ? { data: copy.data, copyAt: null } : null;
}

/** Garde sur l'appareil une donnée qui n'existe pas encore sur le serveur (séance créée hors ligne). */
export async function saveLocal(path: string, data: unknown) {
  if (state.account !== null) await saveSnapshot(state.account, path, data);
}

export async function dropLocal(path: string) {
  if (state.account !== null) await deleteSnapshot(state.account, path);
}

/**
 * Modifie les données déjà affichées ET leur copie locale (pour qu'un rechargement hors ligne les retrouve).
 * `path` est l'adresse sous laquelle la lecture est gardée.
 */
export function patchData<T>(key: readonly unknown[], path: string, update: (data: T) => T) {
  if (!client) return;
  const apply = () => {
    const before = client?.getQueryData<Fetched<T>>(key);
    if (!client || !before) return;
    const next = { ...before, data: update(before.data) };
    client.setQueryData(key, next);
    if (state.account !== null) void saveSnapshot(state.account, path, next.data);
  };
  // Une lecture déjà partie (retour d'onglet, intervalle) ne doit pas revenir écraser ce geste : on la coupe d'abord, PUIS on applique (méthode de
  // TanStack Query pour les mises à jour optimistes ; couper après avoir écrit ferait rétablir l'état d'avant).
  const reading = client.getQueryCache().find({ queryKey: [...key], exact: true });
  if (reading?.state.fetchStatus === "fetching") void client.cancelQueries({ queryKey: [...key], exact: true }).then(apply);
  else apply();
}

// ------------------------------------------------------------------ écriture

export type WriteArgs = {
  label: string;
  method: Method;
  path: string;
  body: Record<string, unknown>;
  /** Deux gestes de même clé en attente : le dernier remplace l'autre (« état voulu »). */
  key?: string;
  /** Écriture qui crée une séance : son identifiant provisoire. */
  tmp?: number;
  /** Montre le résultat tout de suite (sera corrigé par la lecture suivante si le serveur dit autre chose). */
  optimistic?: () => void;
};

/**
 * Fait une écriture : en direct si tout va bien, sinon dans la file. Renvoie la réponse du serveur (en direct) ou
 * `queued: true`. Une réponse d'erreur du serveur (refus, conflit) annule l'affichage optimiste et remonte à l'appelant.
 */
export async function teacherWrite<T = unknown>(args: WriteArgs): Promise<{ queued: boolean; data: T | null }> {
  await whenReady();
  const account = state.account;
  if (account === null) throw new ApiError(401, "auth.unauthenticated", "Connexion requise");
  args.optimistic?.();

  const mustQueue = (typeof navigator !== "undefined" && !navigator.onLine) || state.ops.some((o) => o.state === "pending") || args.path.includes("@tmp-");
  if (!mustQueue) {
    try {
      const res = await api<{ data: T }>(args.path, { method: args.method, body: args.body });
      return { queued: false, data: res.data };
    } catch (e) {
      if (!(e instanceof ApiError && e.isOffline)) {
        void client?.invalidateQueries({ queryKey: ["teacher"] });
        throw e;
      }
    }
  }

  await enqueue(store, { account, label: args.label, method: args.method, path: args.path, body: args.body, clientAt: serverNow(), key: args.key, tmp: args.tmp });
  await refresh();
  void sync();
  return { queued: true, data: null };
}

// ---------------------------------------------------------------- déconnexion

/** Efface ce que le compte a laissé sur l'appareil. À appeler APRÈS la fermeture de la session. */
export async function purgeLocalAccount(account: number) {
  await purgeAccount(account);
  set({ ops: [], resolved: {} });
}

export function pendingCount(): number {
  return state.ops.filter((o) => o.state !== "rejected").length;
}
