import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { api, ApiError } from "@/lib/api";

type Call = { url: string; init?: RequestInit };

/** Une réponse HTTP de test. `null` = corps vide. */
const reply = (status: number, body: unknown = null, headers: Record<string, string> = {}) =>
  new Response(body === null ? null : JSON.stringify(body), { status, headers: { "Content-Type": "application/json", ...headers } });

function mockFetch(handler: (call: Call, n: number) => Response | Promise<Response>) {
  const calls: Call[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({ url, init });
      return handler({ url, init }, calls.length);
    }),
  );
  return calls;
}

const headerOf = (c: Call, name: string) => (c.init?.headers as Record<string, string> | undefined)?.[name];

beforeEach(() => {
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
});
afterEach(() => vi.unstubAllGlobals());

describe("api — lecture", () => {
  it("renvoie le JSON et envoie les cookies (credentials: include)", async () => {
    document.cookie = "XSRF-TOKEN=present; path=/"; // le cookie existe, mais une lecture ne doit pas l'envoyer
    const calls = mockFetch(() => reply(200, { data: { id: 1 } }));
    expect(await api("/api/v1/auth/me")).toEqual({ data: { id: 1 } });
    expect(calls[0].init?.credentials).toBe("include");
    expect(headerOf(calls[0], "X-XSRF-TOKEN")).toBeUndefined(); // jamais de jeton CSRF sur une lecture
  });

  it("renvoie undefined sur 204", async () => {
    mockFetch(() => reply(204));
    expect(await api("/api/v1/auth/logout")).toBeUndefined();
  });
});

describe("api — erreurs", () => {
  it("transforme un problem+json en ApiError portant le code stable", async () => {
    mockFetch(() => reply(401, { code: "auth.invalid_credentials", title: "Identifiant ou mot de passe incorrect", detail: "Vérifiez…" }));
    const err = await api("/api/v1/auth/me").catch((e) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect(err).toMatchObject({ status: 401, code: "auth.invalid_credentials", detail: "Vérifiez…" });
    expect(err.isOffline).toBe(false);
  });

  it("garde les erreurs par champ d'une validation 422", async () => {
    mockFetch(() => reply(422, { code: "validation_failed", title: "Données invalides", errors: { password: ["requis"] } }));
    const err = await api("/api/v1/auth/me").catch((e) => e);
    expect(err.fieldErrors).toEqual({ password: ["requis"] });
  });

  it("survit à une réponse sans JSON (page d'erreur du serveur)", async () => {
    mockFetch(() => new Response("<html>502</html>", { status: 502 }));
    const err = await api("/api/v1/auth/me").catch((e) => e);
    expect(err).toMatchObject({ status: 502, code: "http.502" });
  });

  it("distingue l'absence de réseau d'un refus de l'API", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("Failed to fetch")));
    const err = await api("/api/v1/auth/me").catch((e) => e);
    expect(err).toMatchObject({ status: 0, code: "network.offline" });
    expect(err.isOffline).toBe(true);
  });
});

describe("api — écritures et CSRF", () => {
  it("demande le cookie CSRF avant la première écriture, puis envoie le jeton en en-tête", async () => {
    const calls = mockFetch(({ url }) => {
      if (url.endsWith("/auth/csrf")) {
        document.cookie = "XSRF-TOKEN=jeton%20un; path=/";
        return reply(204);
      }
      return reply(200, { ok: true });
    });
    await api("/api/v1/auth/login", { method: "POST", body: { identifier: "a", password: "b" } });

    expect(calls.map((c) => c.url.replace(/^.*\/api/, "/api"))).toEqual(["/api/v1/auth/csrf", "/api/v1/auth/login"]);
    expect(headerOf(calls[1], "X-XSRF-TOKEN")).toBe("jeton un"); // décodé
    expect(calls[1].init?.body).toBe(JSON.stringify({ identifier: "a", password: "b" }));
  });

  it("ne redemande pas le cookie s'il existe déjà", async () => {
    document.cookie = "XSRF-TOKEN=deja; path=/";
    const calls = mockFetch(() => reply(200, {}));
    await api("/api/v1/x", { method: "POST", body: {} });
    expect(calls).toHaveLength(1);
  });

  it("sur 419 : renouvelle le jeton et réessaie UNE fois", async () => {
    document.cookie = "XSRF-TOKEN=perime; path=/";
    const calls = mockFetch(({ url }, n) => {
      if (url.endsWith("/auth/csrf")) {
        document.cookie = "XSRF-TOKEN=frais; path=/";
        return reply(204);
      }
      return n === 1 ? reply(419, { code: "csrf.mismatch", title: "Session expirée" }) : reply(200, { ok: true });
    });
    await expect(api("/api/v1/x", { method: "POST", body: {} })).resolves.toEqual({ ok: true });
    expect(calls).toHaveLength(3); // écriture refusée, nouveau jeton, écriture refaite
    expect(headerOf(calls[2], "X-XSRF-TOKEN")).toBe("frais");
  });

  it("ne boucle pas : un second 419 est remonté à l'appelant", async () => {
    document.cookie = "XSRF-TOKEN=x; path=/";
    const calls = mockFetch(({ url }) => (url.endsWith("/auth/csrf") ? reply(204) : reply(419, { code: "csrf.mismatch", title: "Session expirée" })));
    const err = await api("/api/v1/x", { method: "POST", body: {} }).catch((e) => e);
    expect(err).toMatchObject({ status: 419, code: "csrf.mismatch" });
    expect(calls.filter((c) => !c.url.endsWith("/auth/csrf"))).toHaveLength(2);
  });

  it("ne réessaie jamais une lecture sur 419", async () => {
    const calls = mockFetch(() => reply(419, { code: "csrf.mismatch", title: "x" }));
    await api("/api/v1/x").catch(() => undefined);
    expect(calls).toHaveLength(1);
  });
});
