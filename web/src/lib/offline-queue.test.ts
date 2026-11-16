import { beforeEach, describe, expect, it } from "vitest";
import { applyAnyway, counts, dependsOnTmp, discard, drain, enqueue, isTmp, MemoryStore, resolvePath, tmpRef, type NewOp, type SendResult, type Sender } from "@/lib/offline-queue";

const ACCOUNT = 7;
let ids = 0;
const newId = () => `op-${++ids}`;

function op(partial: Partial<NewOp> & Pick<NewOp, "path">): NewOp {
  return { account: ACCOUNT, label: "x", method: "PUT", body: {}, clientAt: 1_700_000_000_000, ...partial };
}

/** Un serveur factice : répond selon une fonction, et garde la trace de ce qui lui est envoyé. */
function server(reply: (call: { method: string; path: string; body: Record<string, unknown> }, n: number) => SendResult) {
  const calls: { method: string; path: string; body: Record<string, unknown> }[] = [];
  const send: Sender = async (c) => {
    calls.push(c);
    return reply(c, calls.length);
  };
  return { send, calls };
}
const ok = (data: unknown = {}): SendResult => ({ ok: true, data });
const fail = (status: number, code: string, extra?: Record<string, unknown>): SendResult => ({ ok: false, status, code, detail: `d-${code}`, extra });

let store: MemoryStore;
beforeEach(() => {
  store = new MemoryStore();
  ids = 0;
});

describe("identifiants provisoires", () => {
  it("s'écrivent @tmp-N dans une adresse et se résolvent avec le vrai identifiant", () => {
    expect(tmpRef(-3)).toBe("@tmp-3");
    expect(isTmp(-3)).toBe(true);
    expect(isTmp(12)).toBe(false);
    expect(resolvePath("/api/v1/teacher/sessions/@tmp-3/marks/E1", { [-3]: 41 })).toBe("/api/v1/teacher/sessions/41/marks/E1");
    expect(resolvePath("/api/v1/teacher/sessions/@tmp-3/x/@tmp-4", { [-3]: 41, [-4]: 5 })).toBe("/api/v1/teacher/sessions/41/x/5");
    expect(resolvePath("/api/v1/teacher/courses/9", {})).toBe("/api/v1/teacher/courses/9");
  });
  it("renvoient null tant qu'un identifiant n'est pas résolu (jamais « undefined » dans une adresse)", () => {
    expect(resolvePath("/s/@tmp-3", {})).toBeNull();
    expect(resolvePath("/s/@tmp-3/@tmp-4", { [-3]: 1 })).toBeNull();
  });
  it("se retrouvent dans une adresse", () => {
    expect(dependsOnTmp("/s/@tmp-3/m/@tmp-12")).toEqual([-3, -12]);
    expect(dependsOnTmp("/s/3")).toEqual([]);
  });
});

describe("enqueue", () => {
  it("numérote dans l'ordre d'arrivée", async () => {
    const a = await enqueue(store, op({ path: "/a" }), newId);
    const b = await enqueue(store, op({ path: "/b" }), newId);
    expect([a.seq, b.seq, a.state]).toEqual([1, 2, "pending"]);
    expect((await store.list(ACCOUNT)).map((o) => o.path)).toEqual(["/a", "/b"]);
  });
  it("garde l'ordre même quand deux gestes arrivent en même temps (numéros d'ordre distincts)", async () => {
    const [a, b, c] = await Promise.all([enqueue(store, op({ path: "/a" }), newId), enqueue(store, op({ path: "/b" }), newId), enqueue(store, op({ path: "/c" }), newId)]);
    expect([a.seq, b.seq, c.seq]).toEqual([1, 2, 3]);
    expect((await store.list(ACCOUNT)).map((o) => o.path)).toEqual(["/a", "/b", "/c"]);
  });
  it("remplace une écriture en attente de même clé : seul le dernier état voulu part", async () => {
    await enqueue(store, op({ path: "/m/E1", key: "mark:1:E1", body: { status: "absent" } }), newId);
    await enqueue(store, op({ path: "/other" }), newId);
    await enqueue(store, op({ path: "/m/E1", key: "mark:1:E1", body: { status: "present" } }), newId);
    const list = await store.list(ACCOUNT);
    expect(list.map((o) => [o.path, o.body.status])).toEqual([["/other", undefined], ["/m/E1", "present"]]);
  });
  it("ne remplace pas une écriture en conflit (la personne ne l'a pas encore tranchée)", async () => {
    const first = await enqueue(store, op({ path: "/m/E1", key: "k" }), newId);
    await store.put({ ...first, state: "conflict" });
    await enqueue(store, op({ path: "/m/E1", key: "k" }), newId);
    expect((await store.list(ACCOUNT)).length).toBe(2);
  });
  it("garde chaque compte à part", async () => {
    await enqueue(store, op({ path: "/a" }), newId);
    await enqueue(store, op({ path: "/b", account: 99 }), newId);
    expect((await store.list(ACCOUNT)).length).toBe(1);
    expect((await store.list(99)).length).toBe(1);
  });
});

describe("drain", () => {
  it("envoie dans l'ordre, avec l'instant de l'action, et vide la file", async () => {
    await enqueue(store, op({ path: "/1", clientAt: 111 }), newId);
    await enqueue(store, op({ path: "/2", clientAt: 222, body: { status: "absent" } }), newId);
    const s = server(() => ok());

    const r = await drain(store, ACCOUNT, s.send);

    expect(s.calls.map((c) => [c.path, c.body.client_at])).toEqual([["/1", 111], ["/2", 222]]);
    expect(s.calls[1].body.status).toBe("absent");
    expect(r).toMatchObject({ sent: 2, conflicts: 0, rejected: 0, offline: false });
    expect(await store.list(ACCOUNT)).toEqual([]);
  });

  it("s'arrête au premier échec de réseau et garde tout pour plus tard, dans l'ordre", async () => {
    for (const p of ["/1", "/2", "/3"]) await enqueue(store, op({ path: p }), newId);
    const s = server((_, n) => (n === 1 ? ok() : fail(0, "network.offline")));

    const r = await drain(store, ACCOUNT, s.send);

    expect(r).toMatchObject({ sent: 1, offline: true });
    expect((await store.list(ACCOUNT)).map((o) => [o.path, o.state])).toEqual([["/2", "pending"], ["/3", "pending"]]);
    expect(s.calls.length).toBe(2);
  });

  it("une erreur serveur (5xx) ou une limite (429) suspend l'envoi sans rien perdre", async () => {
    for (const status of [500, 503, 429]) {
      store = new MemoryStore();
      await enqueue(store, op({ path: "/1" }), newId);
      const s = server(() => fail(status, "boom"));
      const r = await drain(store, ACCOUNT, s.send);
      expect(r.failed, String(status)).toBe(true);
      expect((await store.list(ACCOUNT)).map((o) => o.state)).toEqual(["pending"]);
    }
  });

  it("une session expirée (401 ou 419) suspend l'envoi : la personne doit se reconnecter", async () => {
    await enqueue(store, op({ path: "/1" }), newId);
    await enqueue(store, op({ path: "/2" }), newId);
    const r = await drain(store, ACCOUNT, server(() => fail(401, "auth.unauthenticated")).send);
    expect(r.unauthenticated).toBe(true);
    expect((await store.list(ACCOUNT)).length).toBe(2);
  });

  it("un conflit (409) est mis de côté avec son motif, et les autres écritures partent", async () => {
    await enqueue(store, op({ path: "/1" }), newId);
    await enqueue(store, op({ path: "/2" }), newId);
    const s = server((c) => (c.path === "/1" ? fail(409, "presence.conflict", { target: "mark", modified_at: "2026-11-15 10:00:00" }) : ok()));

    const r = await drain(store, ACCOUNT, s.send);

    expect(r).toMatchObject({ sent: 1, conflicts: 1 });
    const left = await store.list(ACCOUNT);
    expect(left.map((o) => [o.path, o.state])).toEqual([["/1", "conflict"]]);
    expect(left[0].problem).toMatchObject({ code: "presence.conflict", target: "mark", modifiedAt: "2026-11-15 10:00:00" });
  });

  it("un refus (403, 404, 422) est mis de côté comme refusé, sans arrêter la suite", async () => {
    await enqueue(store, op({ path: "/1" }), newId);
    await enqueue(store, op({ path: "/2" }), newId);
    const s = server((c) => (c.path === "/1" ? fail(404, "presence.not_found") : ok()));
    const r = await drain(store, ACCOUNT, s.send);
    expect(r).toMatchObject({ sent: 1, rejected: 1 });
    expect((await store.list(ACCOUNT)).map((o) => o.state)).toEqual(["rejected"]);
  });

  it("n'envoie pas deux fois une écriture en conflit ou refusée", async () => {
    const a = await enqueue(store, op({ path: "/1" }), newId);
    await store.put({ ...a, state: "conflict" });
    const s = server(() => ok());
    const r = await drain(store, ACCOUNT, s.send);
    expect(s.calls.length).toBe(0);
    expect(r.sent).toBe(0);
  });

  it("réécrit l'identifiant provisoire d'une séance une fois créée, pour toutes les écritures qui la visent", async () => {
    await enqueue(store, op({ method: "POST", path: "/api/v1/teacher/courses/9/sessions", tmp: -1 }), newId);
    await enqueue(store, op({ path: "/api/v1/teacher/sessions/@tmp-1/marks/E1", body: { status: "absent" } }), newId);
    await enqueue(store, op({ method: "POST", path: "/api/v1/teacher/sessions/@tmp-1/mark-all-present" }), newId);
    const s = server((c) => (c.method === "POST" && c.path.endsWith("/sessions") ? ok({ data: { session_id: 77, existed: false } }) : ok()));

    const r = await drain(store, ACCOUNT, s.send);

    expect(s.calls.map((c) => c.path)).toEqual(["/api/v1/teacher/courses/9/sessions", "/api/v1/teacher/sessions/77/marks/E1", "/api/v1/teacher/sessions/77/mark-all-present"]);
    expect(r.resolved).toEqual({ [-1]: 77 });
    expect(await store.resolved(ACCOUNT)).toEqual({ [-1]: 77 });
  });

  it("garde la correspondance entre deux envois : la création est partie, les pointages suivent plus tard", async () => {
    await enqueue(store, op({ method: "POST", path: "/api/v1/teacher/courses/9/sessions", tmp: -1 }), newId);
    await drain(store, ACCOUNT, server(() => ok({ data: { session_id: 77 } })).send);
    await enqueue(store, op({ path: "/api/v1/teacher/sessions/@tmp-1/marks/E1" }), newId);
    const s = server(() => ok());

    await drain(store, ACCOUNT, s.send);

    expect(s.calls.map((c) => c.path)).toEqual(["/api/v1/teacher/sessions/77/marks/E1"]);
  });

  it("si la séance n'a pas pu être créée, les écritures qui la visent sont refusées au lieu de partir vers une adresse fausse", async () => {
    await enqueue(store, op({ method: "POST", path: "/api/v1/teacher/courses/9/sessions", tmp: -1 }), newId);
    await enqueue(store, op({ path: "/api/v1/teacher/sessions/@tmp-1/marks/E1" }), newId);
    const s = server((c) => (c.path.endsWith("/sessions") ? fail(422, "validation_failed") : ok()));

    const r = await drain(store, ACCOUNT, s.send);

    expect(s.calls.length).toBe(1);
    expect(r).toMatchObject({ rejected: 2 });
    expect((await store.list(ACCOUNT)).map((o) => [o.state, o.problem?.code])).toEqual([["rejected", "validation_failed"], ["rejected", "presence.session_missing"]]);
  });

  it("n'envoie qu'une fois quand deux envois sont demandés en même temps", async () => {
    await enqueue(store, op({ path: "/1" }), newId);
    const s = server(() => ok());
    const [a, b] = await Promise.all([drain(store, ACCOUNT, s.send), drain(store, ACCOUNT, s.send)]);
    expect(s.calls.length).toBe(1);
    expect(a).toBe(b);
  });
});

describe("conflit : appliquer quand même, ou ignorer", () => {
  it("« appliquer quand même » renvoie l'écriture avec force et SANS l'instant hors ligne", async () => {
    const first = await enqueue(store, op({ path: "/m", body: { status: "absent" }, clientAt: 5 }), newId);
    await store.put({ ...first, state: "conflict", problem: { code: "presence.conflict", status: 409 } });

    await applyAnyway(store, (await store.list(ACCOUNT))[0]);
    const s = server(() => ok());
    await drain(store, ACCOUNT, s.send);

    expect(s.calls[0].body).toEqual({ status: "absent", force: true });
    expect(await store.list(ACCOUNT)).toEqual([]);
  });

  it("« ignorer » abandonne l'écriture", async () => {
    const a = await enqueue(store, op({ path: "/1" }), newId);
    await enqueue(store, op({ path: "/2" }), newId);
    await discard(store, a);
    expect((await store.list(ACCOUNT)).map((o) => o.path)).toEqual(["/2"]);
  });

  it("ignorer la création d'une séance abandonne aussi les écritures qui la visaient", async () => {
    const create = await enqueue(store, op({ method: "POST", path: "/c/9/sessions", tmp: -1 }), newId);
    await enqueue(store, op({ path: "/s/@tmp-1/marks/E1" }), newId);
    await enqueue(store, op({ path: "/s/@tmp-2/marks/E1" }), newId);
    await enqueue(store, op({ path: "/autre" }), newId);

    await discard(store, create);

    expect((await store.list(ACCOUNT)).map((o) => o.path)).toEqual(["/s/@tmp-2/marks/E1", "/autre"]);
  });
});

describe("counts", () => {
  it("sépare ce qui attend, ce qui est en conflit et ce qui est refusé", () => {
    const base = { id: "", seq: 0, account: 1, label: "", method: "PUT" as const, path: "", body: {}, clientAt: 0 };
    expect(counts([{ ...base, state: "pending" }, { ...base, state: "pending" }, { ...base, state: "conflict" }, { ...base, state: "rejected" }])).toEqual({ pending: 2, conflict: 1, rejected: 1 });
    expect(counts([])).toEqual({ pending: 0, conflict: 0, rejected: 0 });
  });
});
