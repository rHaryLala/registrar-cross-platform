/**
 * Reprise après l'échec de chargement d'un fichier de l'application (« ChunkLoadError »).
 *
 * Deux causes réelles : un réseau qui a coupé en plein chargement, et surtout une NOUVELLE VERSION mise en ligne :
 * les fichiers à empreinte de l'ancienne version n'existent plus, alors qu'un téléphone garde l'ancienne page ouverte.
 * Dans les deux cas, recharger la page règle le problème.
 *
 * Garde-fou : une seule tentative par intervalle, sinon un fichier réellement absent ferait recharger la page sans fin.
 */

const KEY = "registrar:chunk-reload";
export const MIN_INTERVAL_MS = 30_000;

export function isChunkLoadFailure(text: string): boolean {
  return /ChunkLoadError|Loading chunk [\w-]+ failed|Failed to load chunk|error loading dynamically imported module/i.test(text);
}

/** Vrai si l'on peut recharger maintenant (et note l'instant) ; faux si l'on vient déjà de le faire. */
export function allowReload(now = Date.now(), storage: Pick<Storage, "getItem" | "setItem"> | null = safeStorage()): boolean {
  try {
    const last = Number(storage?.getItem(KEY) ?? 0);
    if (now - last < MIN_INTERVAL_MS) return false;
    storage?.setItem(KEY, String(now));
  } catch {
    // Stockage indisponible (navigation privée) : on autorise ; pire cas, un seul rechargement par page vue.
  }
  return true;
}

function safeStorage(): Storage | null {
  try {
    return typeof sessionStorage === "undefined" ? null : sessionStorage;
  } catch {
    return null;
  }
}

/** À appeler une fois, côté navigateur ; renvoie la fonction qui retire les écouteurs. */
export function installChunkRecovery(reload: () => void = () => window.location.reload()): () => void {
  const onFailure = (event: ErrorEvent | PromiseRejectionEvent) => {
    const reason = "reason" in event ? event.reason : undefined;
    const text = `${reason?.name ?? ""} ${reason?.message ?? ""} ${"message" in event ? (event.message ?? "") : ""}`;
    if (isChunkLoadFailure(text) && allowReload()) reload();
  };
  window.addEventListener("error", onFailure);
  window.addEventListener("unhandledrejection", onFailure);
  return () => {
    window.removeEventListener("error", onFailure);
    window.removeEventListener("unhandledrejection", onFailure);
  };
}
