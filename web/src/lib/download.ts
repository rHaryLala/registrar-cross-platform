import { API_BASE, ApiError } from "@/lib/api";

/** Nom de fichier annoncé par l'API (en-tête Content-Disposition). Jamais de chemin : on ne garde que le nom. */
export function filenameFromDisposition(header: string | null, fallback: string): string {
  const match = /filename="?([^";]+)"?/i.exec(header ?? "");
  const name = match?.[1]?.trim().split(/[\/]/).pop();
  return name && /^[\w.-]+$/.test(name) ? name : fallback;
}

/**
 * Télécharge un fichier protégé par la session (PDF). Passe par fetch + Blob plutôt que par un simple lien :
 * hors ligne ou session expirée, on obtient une vraie erreur à montrer, pas une page blanche.
 */
export async function downloadFile(path: string, fallbackName: string): Promise<string> {
  let res: Response;
  try {
    res = await fetch(`${API_BASE}${path}`, { credentials: "include", headers: { Accept: "application/pdf,application/json" } });
  } catch {
    throw new ApiError(0, "network.offline", "Réseau indisponible", "Vérifiez votre connexion, puis réessayez.");
  }
  if (!res.ok) {
    const body: unknown = await res.json().catch(() => null);
    throw ApiError.from(res.status, body);
  }
  const name = filenameFromDisposition(res.headers.get("Content-Disposition"), fallbackName);
  const url = URL.createObjectURL(await res.blob());
  try {
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    document.body.append(a);
    a.click();
    a.remove();
  } finally {
    // Le navigateur a déjà reçu le fichier : on libère la mémoire un peu plus tard (certains navigateurs lisent après le clic).
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
  }
  return name;
}
