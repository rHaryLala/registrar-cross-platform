import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { allowReload, installChunkRecovery, isChunkLoadFailure, MIN_INTERVAL_MS } from "@/lib/chunk-recovery";

const fakeStorage = () => {
  const data = new Map<string, string>();
  return { getItem: (k: string) => data.get(k) ?? null, setItem: (k: string, v: string) => void data.set(k, v) };
};

describe("isChunkLoadFailure", () => {
  it("reconnaît les échecs de chargement de fichier", () => {
    expect(isChunkLoadFailure("ChunkLoadError: Failed to load chunk /_next/static/chunks/abc.js from module 123")).toBe(true);
    expect(isChunkLoadFailure("Loading chunk 42 failed.")).toBe(true);
    expect(isChunkLoadFailure("error loading dynamically imported module: https://x/a.js")).toBe(true);
  });
  it("ignore les autres erreurs", () => {
    expect(isChunkLoadFailure("TypeError: Cannot read properties of null")).toBe(false);
    expect(isChunkLoadFailure("")).toBe(false);
  });
});

describe("allowReload", () => {
  it("autorise une première fois, puis refuse dans l'intervalle (jamais de boucle de rechargement)", () => {
    const s = fakeStorage();
    expect(allowReload(1_000_000, s)).toBe(true);
    expect(allowReload(1_000_000 + MIN_INTERVAL_MS - 1, s)).toBe(false);
  });
  it("réautorise une fois l'intervalle écoulé", () => {
    const s = fakeStorage();
    allowReload(1_000_000, s);
    expect(allowReload(1_000_000 + MIN_INTERVAL_MS, s)).toBe(true);
  });
  it("autorise si le stockage est inutilisable (navigation privée)", () => {
    const broken = {
      getItem: () => {
        throw new Error("denied");
      },
      setItem: () => undefined,
    };
    expect(allowReload(5, broken)).toBe(true);
  });
});

describe("installChunkRecovery", () => {
  beforeEach(() => sessionStorage.clear());
  afterEach(() => vi.restoreAllMocks());

  it("recharge une fois sur un ChunkLoadError, pas deux", () => {
    const reload = vi.fn();
    const off = installChunkRecovery(reload);
    const fail = () => window.dispatchEvent(new ErrorEvent("error", { message: "ChunkLoadError: Failed to load chunk /a.js" }));
    fail();
    fail();
    expect(reload).toHaveBeenCalledTimes(1);
    off();
  });

  it("ne recharge pas pour une autre erreur", () => {
    const reload = vi.fn();
    const off = installChunkRecovery(reload);
    window.dispatchEvent(new ErrorEvent("error", { message: "TypeError: x is undefined" }));
    expect(reload).not.toHaveBeenCalled();
    off();
  });

  it("retire ses écouteurs", () => {
    const reload = vi.fn();
    installChunkRecovery(reload)();
    window.dispatchEvent(new ErrorEvent("error", { message: "ChunkLoadError" }));
    expect(reload).not.toHaveBeenCalled();
  });
});
