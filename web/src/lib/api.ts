/**
 * Client de l'API Registrar (/api/v1).
 *
 * - Session par cookie : `credentials: "include"` ; jamais de jeton dans le stockage du navigateur.
 * - CSRF : avant toute écriture, on s'assure du cookie XSRF-TOKEN et on le renvoie en en-tête.
 *   Sur un 419 (jeton périmé), on le renouvelle et on réessaie UNE fois.
 * - Les erreurs de l'API (RFC 9457) deviennent des `ApiError` portant un `code` stable : c'est le code,
 *   jamais le texte, qui décide du comportement de l'interface.
 */

/** "" = même origine (production) ; en développement : http://localhost:8000 */
export const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "";

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    readonly title: string,
    readonly detail?: string,
    readonly fieldErrors?: Record<string, string[]>,
  ) {
    super(detail ?? title);
    this.name = "ApiError";
  }

  /** Pas de réseau, ou serveur injoignable (distinct d'un refus de l'API). */
  get isOffline(): boolean {
    return this.status === 0;
  }

  static from(status: number, body: unknown): ApiError {
    const b = (body && typeof body === "object" ? body : {}) as Record<string, unknown>;
    return new ApiError(
      status,
      typeof b.code === "string" ? b.code : `http.${status}`,
      typeof b.title === "string" ? b.title : "Une erreur est survenue",
      typeof b.detail === "string" ? b.detail : undefined,
      b.errors && typeof b.errors === "object" ? (b.errors as Record<string, string[]>) : undefined,
    );
  }
}

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const hit = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`));
  return hit ? decodeURIComponent(hit.slice(name.length + 1)) : null;
}

let csrfInFlight: Promise<void> | null = null;

async function ensureCsrf(force = false): Promise<void> {
  if (!force && readCookie("XSRF-TOKEN")) return;
  csrfInFlight ??= fetch(`${API_BASE}/api/v1/auth/csrf`, { credentials: "include" })
    .then(() => undefined)
    .catch(() => {
      throw new ApiError(0, "network.offline", "Réseau indisponible", "Vérifiez votre connexion, puis réessayez.");
    })
    .finally(() => {
      csrfInFlight = null;
    });
  await csrfInFlight;
}

type Options = { method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE"; body?: unknown; signal?: AbortSignal };

async function send(path: string, { method = "GET", body, signal }: Options): Promise<Response> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  if (method !== "GET") {
    const token = readCookie("XSRF-TOKEN");
    if (token) headers["X-XSRF-TOKEN"] = token;
  }
  try {
    return await fetch(`${API_BASE}${path}`, {
      method,
      headers,
      credentials: "include",
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
    });
  } catch (e) {
    if (e instanceof DOMException && e.name === "AbortError") throw e;
    throw new ApiError(0, "network.offline", "Réseau indisponible", "Vérifiez votre connexion, puis réessayez.");
  }
}

export async function api<T = void>(path: string, options: Options = {}): Promise<T> {
  const isWrite = (options.method ?? "GET") !== "GET";
  if (isWrite) await ensureCsrf();

  let res = await send(path, options);
  if (res.status === 419 && isWrite) {
    await ensureCsrf(true);
    res = await send(path, options);
  }

  if (res.status === 204) return undefined as T;

  const json: unknown = await res.json().catch(() => null);
  if (!res.ok) throw ApiError.from(res.status, json);
  return json as T;
}
