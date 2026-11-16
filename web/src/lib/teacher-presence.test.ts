import { describe, expect, it } from "vitest";
import {
  addSessionRow,
  applyAllPresent,
  applyJustification,
  applyMark,
  applySessionEdit,
  blankSession,
  callUrl,
  category,
  noPenalty,
  normalizeTime,
  parseSemester,
  recordLabel,
  registerPath,
  registerUrl,
  removeSessionRow,
  setStale,
  teacherLateMinutes,
  type RegisterData,
  type SessionData,
  type Summary,
} from "@/lib/teacher-presence";

const summary: Summary = { recorded: 0, present: 0, late: 0, absent: 0, no_penalty: 0, converted_lates: 0, counted: 0, late_pending: false, planned: 30, allowed: 4, rate: 0, state: "ok", gauge: 0 };
const line = (id: string, over: Record<string, unknown> = {}) => ({
  student_id: id, last_name: id, first_name: "x", status: null, minutes_late: null, justification: null, label: "Non pointé", category: null, modified_at: null, summary, ...over,
});
const session = (): SessionData => ({
  session: { id: 5, date: "2026-11-10", planned_time: "08:00", teacher_arrival: null, warned: false, comment: null, teacher_late_minutes: null, no_penalty: false, modified_at: null, sheet_id: 1, course_id: 9, year: "2026 - 2027", semester: 1 },
  lines: [line("E1"), line("E2"), line("E3", { status: "absent", label: "Absent", category: "absence" })],
});
const register = (): RegisterData => ({
  course: { id: 9, code: "INF 101", title: "Intro" }, year: "2026 - 2027", semester: 1, semester_label: "Semestre 1",
  sheet: { id: 1, planned_sessions: 30, allowed_absences: 4 },
  students: [{ id: "E1", last_name: "A", first_name: "a", summary, flagged_at: null }, { id: "E2", last_name: "B", first_name: "b", summary, flagged_at: null }],
  sessions: [], exceeded: 0, today: "2026-11-15", suggested_time: null,
});

describe("règles d'un pointage (§476) : la durée décide, pas le bouton", () => {
  it.each([
    ["present", null, "present"],
    ["absent", null, "absence"],
    ["retard", null, "retard"], // sans durée : 5 minutes
    ["retard", 4, "present"],
    ["retard", 5, "retard"],
    ["retard", 9, "retard"],
    ["retard", 10, "absence"],
    ["retard", 240, "absence"],
  ] as const)("%s %s → %s", (status, minutes, expected) => {
    expect(category(status, minutes)).toBe(expected);
  });
  it("un non pointé ne compte pour rien", () => {
    expect(category(null, null)).toBeNull();
    expect(category(null, 12)).toBeNull();
  });
  it("libellés identiques à ceux du serveur", () => {
    expect([recordLabel(null, null), recordLabel("present", null), recordLabel("absent", null)]).toEqual(["Non pointé", "Présent", "Absent"]);
    expect([recordLabel("retard", null), recordLabel("retard", 7), recordLabel("retard", 12), recordLabel("retard", 3)]).toEqual([
      "Retard 5 min", "Retard 7 min", "Retard 12 min (compte absence)", "Retard 3 min (toléré)",
    ]);
  });
});

describe("heures", () => {
  it("normalise HH:MM et refuse le reste", () => {
    expect(normalizeTime("8:30")).toBe("08:30");
    expect(normalizeTime(" 08:05 ")).toBe("08:05");
    expect(normalizeTime("23:59")).toBe("23:59");
    for (const bad of ["25:00", "8h30", "12:61", "", "8", "08:30:15", "ab:cd"]) expect(normalizeTime(bad), bad).toBeNull();
  });
  it("retard de l'enseignant : jamais négatif, null si une heure manque", () => {
    expect(teacherLateMinutes("08:00", "08:25")).toBe(25);
    expect(teacherLateMinutes("08:00", "07:50")).toBe(0);
    expect(teacherLateMinutes("08:00:00", "08:20:00")).toBe(20);
    expect(teacherLateMinutes(null, "08:20")).toBeNull();
    expect(teacherLateMinutes("08:00", null)).toBeNull();
  });
  it("§474 : sans pénalité au-delà de 10 minutes et sans avoir prévenu", () => {
    expect(noPenalty("08:00", "08:10", false)).toBe(false);
    expect(noPenalty("08:00", "08:11", false)).toBe(true);
    expect(noPenalty("08:00", "08:40", true)).toBe(false);
    expect(noPenalty("08:00", null, false)).toBe(false);
  });
});

describe("application optimiste d'un changement", () => {
  it("pointer : seul l'étudiant visé change, et son bilan est marqué en attente", () => {
    const next = applyMark(session(), "E1", "retard", 7, "2026-11-15 10:00:00");
    expect(next.lines[0]).toMatchObject({ status: "retard", minutes_late: 7, label: "Retard 7 min", category: "retard", stale: true, modified_at: "2026-11-15 10:00:00" });
    expect(next.lines[1]).toEqual(line("E2"));
    expect(next.lines[2].status).toBe("absent");
  });
  it("un retard sans durée vaut 5 minutes ; une durée est effacée hors retard", () => {
    expect(applyMark(session(), "E1", "retard", null).lines[0]).toMatchObject({ minutes_late: 5, label: "Retard 5 min" });
    expect(applyMark(session(), "E1", "absent", 9).lines[0]).toMatchObject({ minutes_late: null, label: "Absent" });
  });
  it("effacer un pointage le remet à « Non pointé »", () => {
    expect(applyMark(session(), "E3", null, null).lines[2]).toMatchObject({ status: null, label: "Non pointé", category: null });
  });
  it("ne modifie jamais les données d'origine", () => {
    const before = session();
    const frozen = JSON.stringify(before);
    applyMark(before, "E1", "absent", null);
    applyAllPresent(before);
    applyJustification(before, "E1", "medical");
    applySessionEdit(before, { planned_time: "09:00", teacher_arrival: "09:30", warned: false, comment: null });
    expect(JSON.stringify(before)).toBe(frozen);
  });
  it("un étudiant inconnu ne change rien", () => {
    expect(applyMark(session(), "ZZZ", "absent", null).lines.map((l) => l.status)).toEqual([null, null, "absent"]);
  });
  it("tous présents : seuls les non pointés changent", () => {
    const next = applyAllPresent(session());
    expect(next.lines.map((l) => l.status)).toEqual(["present", "present", "absent"]);
    expect(next.lines[0].label).toBe("Présent");
    expect(next.lines[2].stale).toBeUndefined();
  });
  it("justificatif : n'efface pas le pointage", () => {
    const next = applyJustification(session(), "E3", "medical");
    expect(next.lines[2]).toMatchObject({ justification: "medical", status: "absent" });
    expect(applyJustification(next, "E3", null).lines[2].justification).toBeNull();
  });
  it("modifier la séance recalcule le retard de l'enseignant et le §474, et met les bilans en attente", () => {
    const next = applySessionEdit(session(), { planned_time: "08:00", teacher_arrival: "08:30", warned: false, comment: "Pluie" });
    expect(next.session).toMatchObject({ teacher_late_minutes: 30, no_penalty: true, comment: "Pluie" });
    expect(next.lines.every((l) => l.stale)).toBe(true);
    expect(applySessionEdit(next, { planned_time: "08:00", teacher_arrival: "08:30", warned: true, comment: null }).session.no_penalty).toBe(false);
  });
});

describe("bilan « en attente »", () => {
  it("ne concerne que les lignes visées, et se retire", () => {
    const marked = setStale(session(), ["E1", "E3"], true);
    expect(marked.lines.map((l) => l.stale)).toEqual([true, undefined, true]);
    expect(setStale(marked, ["E1"], false).lines.map((l) => l.stale)).toEqual([undefined, undefined, true]);
    expect(setStale(marked, "all", false).lines.every((l) => l.stale === undefined)).toBe(true);
    expect(setStale(session(), "all", true).lines.every((l) => l.stale === true)).toBe(true);
  });
  it("ne modifie pas les données d'origine", () => {
    const before = session();
    const frozen = JSON.stringify(before);
    setStale(before, "all", true);
    expect(JSON.stringify(before)).toBe(frozen);
  });
});

describe("séance créée hors ligne", () => {
  it("part de la liste des inscrits, personne de pointé", () => {
    const s = blankSession(register(), -1, { date: "2026-11-15", planned_time: "08:00", teacher_arrival: "08:05", warned: false });
    expect(s.session).toMatchObject({ id: -1, course_id: 9, sheet_id: 1, year: "2026 - 2027", semester: 1, teacher_late_minutes: 5, no_penalty: false });
    expect(s.lines.map((l) => [l.student_id, l.status, l.label])).toEqual([["E1", null, "Non pointé"], ["E2", null, "Non pointé"]]);
  });
  it("s'ajoute à la liste du registre, la plus récente d'abord, une seule fois", () => {
    let reg = register();
    reg = addSessionRow(reg, blankSession(reg, -1, { date: "2026-11-10", planned_time: "08:00", teacher_arrival: null, warned: false }).session);
    reg = addSessionRow(reg, blankSession(reg, -2, { date: "2026-11-15", planned_time: "08:00", teacher_arrival: null, warned: false }).session);
    reg = addSessionRow(reg, blankSession(reg, -2, { date: "2026-11-15", planned_time: "08:00", teacher_arrival: null, warned: false }).session);
    expect(reg.sessions.map((s) => [s.id, s.date, s.to_mark])).toEqual([[-2, "2026-11-15", 2], [-1, "2026-11-10", 2]]);
    expect(removeSessionRow(reg, -2).sessions.map((s) => s.id)).toEqual([-1]);
  });
});

describe("adresses", () => {
  it("construisent des adresses lisibles par le site statique", () => {
    expect(registerPath(9, "2026 - 2027", 1)).toBe("/api/v1/teacher/courses/9/register?year=2026+-+2027&semester=1");
    expect(registerPath(9, "2026 - 2027", null)).toBe("/api/v1/teacher/courses/9/register?year=2026+-+2027");
    expect(callUrl(5, 9, "2026 - 2027", 2)).toBe("/presence/appel/?seance=5&cours=9&annee=2026+-+2027&semestre=2");
    expect(callUrl(-3, 9, "2026 - 2027", null)).toBe("/presence/appel/?seance=-3&cours=9&annee=2026+-+2027");
    expect(registerUrl(9, "2026 - 2027", 1)).toBe("/presence/registre/?cours=9&annee=2026+-+2027&semestre=1");
  });
  it("relisent le semestre : absent ou invalide = tous", () => {
    expect([parseSemester("1"), parseSemester("4"), parseSemester(null), parseSemester(""), parseSemester("0"), parseSemester("5"), parseSemester("x"), parseSemester("1.5")]).toEqual([1, 4, null, null, null, null, null, null]);
  });
});
