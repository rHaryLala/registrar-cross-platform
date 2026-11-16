import { describe, expect, it } from "vitest";
import { clockText, countedText, delayText, flagStanding, markProcessed, overviewUrl, stampDate, type AttendanceOverview, type Flag } from "@/lib/attendance-review";
import type { Summary } from "@/lib/teacher-presence";

const summary = (over: Partial<Summary> = {}): Summary => ({
  recorded: 10, present: 6, late: 0, absent: 4, no_penalty: 0, converted_lates: 0, counted: 4, late_pending: false, planned: 20, allowed: 3, rate: 20, state: "exceeded", gauge: 100, ...over,
});
const flag = (id: number, over: Partial<Flag> = {}): Flag => ({
  id, student_id: `E${id}`, last_name: "Andria", first_name: "Zo", course_id: 1, code: "INF 101", title: "Intro", semester: 1, semester_label: "Semestre 1", teacher: "Andry Rasoa",
  flagged_at: "2026-10-10 10:00:00", processed_at: null, counted_at_flag: 4, planned_at_flag: 20, today: summary(), ...over,
});
const overview = (flags: Flag[], pending = flags.length): AttendanceOverview => ({ year: "2026 - 2027", years: ["2026 - 2027"], pending_total: pending, flags, teacher_delays: [], delays_by_teacher: [], without_base: [], wait_minutes: 10 });

describe("adresse de la liste", () => {
  it("n'ajoute que ce qui est demandé", () => {
    expect(overviewUrl(null, false)).toBe("/api/v1/review/attendance");
    expect(overviewUrl("2025 - 2026", false)).toBe("/api/v1/review/attendance?year=2025+-+2026");
    expect(overviewUrl(null, true)).toBe("/api/v1/review/attendance?processed=1");
    expect(overviewUrl("2025 - 2026", true)).toBe("/api/v1/review/attendance?year=2025+-+2026&processed=1");
  });
});

describe("où en est l'étudiant aujourd'hui", () => {
  it("dit s'il est toujours au-delà, revenu sous le seuil, ou sans base", () => {
    expect(flagStanding(flag(1)).label).toBe("Toujours au-delà");
    expect(flagStanding(flag(1, { today: summary({ state: "ok", counted: 2 }) }))).toEqual({ label: "Revenu sous le seuil", tone: "ok" });
    expect(flagStanding(flag(1, { today: summary({ state: "limit", counted: 3 }) })).label).toBe("Revenu sous le seuil");
    expect(flagStanding(flag(1, { today: summary({ state: "unknown", allowed: null }) })).tone).toBe("warn");
  });
  it("le compte rappelle ce qui était permis", () => {
    expect(countedText({ counted: 4, allowed: 3 })).toBe("4 sur 3 permises");
    expect(countedText({ counted: 4, allowed: null })).toBe("4");
    expect(countedText({ counted: 0, allowed: 0 })).toBe("0 sur 0 permises");
  });
});

describe("heures et durées", () => {
  it("écrit les heures à la française", () => {
    expect(clockText("08:05")).toBe("8 h 05");
    expect(clockText("14:00")).toBe("14 h 00");
    expect(clockText("n/a")).toBe("n/a");
  });
  it("écrit les retards en minutes puis en heures", () => {
    expect([delayText(11), delayText(59), delayText(60), delayText(90), delayText(125), delayText(null)]).toEqual(["11 min", "59 min", "1 h", "1 h 30", "2 h 05", "—"]);
  });
  it("lit la date du serveur sans décalage de fuseau", () => {
    expect(stampDate("2026-10-05 00:30:00")).toBe("5 octobre 2026");
    expect(stampDate(null)).toBe("");
    expect(stampDate("pas une date")).toBe("pas une date");
  });
});

describe("marquer comme traité dans la copie", () => {
  it("retire le signalement quand on n'affiche pas les traités, et baisse le compteur", () => {
    const next = markProcessed(overview([flag(1), flag(2)]), 1, "2026-11-02 14:30:00", false);
    expect(next.flags.map((f) => f.id)).toEqual([2]);
    expect(next.pending_total).toBe(1);
  });
  it("le garde, daté, quand on affiche les traités", () => {
    const next = markProcessed(overview([flag(1), flag(2)]), 2, "2026-11-02 14:30:00", true);
    expect(next.flags.map((f) => [f.id, f.processed_at])).toEqual([[1, null], [2, "2026-11-02 14:30:00"]]);
    expect(next.pending_total).toBe(1);
  });
  it("ne change rien pour un signalement inconnu ou déjà traité, et le compteur ne passe jamais sous zéro", () => {
    const base = overview([flag(1, { processed_at: "2026-10-20 08:00:00" })], 0);
    expect(markProcessed(base, 1, "2026-11-02 14:30:00", true)).toBe(base);
    expect(markProcessed(base, 99, "2026-11-02 14:30:00", true)).toBe(base);
    expect(markProcessed(overview([flag(1)], 0), 1, "x", false).pending_total).toBe(0);
  });
  it("ne modifie pas l'original", () => {
    const base = overview([flag(1)]);
    markProcessed(base, 1, "x", true);
    expect(base.flags[0].processed_at).toBeNull();
    expect(base.pending_total).toBe(1);
  });
});
