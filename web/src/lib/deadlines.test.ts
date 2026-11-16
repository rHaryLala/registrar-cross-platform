import { describe, expect, it } from "vitest";
import { changedCount, changesText, dayNumber, deadlinesUrl, draftFrom, errors, hint, optionText, payload, position, saveUrl, shifted, shortDate, stateText, toYmd, type DeadlineType } from "@/lib/deadlines";

const types: Record<string, DeadlineType> = {
  A: { label: "En une fois", parts: [100], proposed: true },
  B: { label: "Deux tranches de 50 %", parts: [50, 50], proposed: true },
  E: { label: "Quatre tranches de 25 %", parts: [25, 25, 25, 25], proposed: true },
};

describe("jours", () => {
  it("lit les vraies dates seulement", () => {
    expect(dayNumber("2026-12-11")).not.toBeNull();
    for (const bad of ["", "abc", "2026-02-30", "2026-13-01", "2026-5-1", "12/05/2026", "2026-05-01x", null, undefined]) expect(dayNumber(bad as string), String(bad)).toBeNull();
    expect(dayNumber("2026-12-12")! - dayNumber("2026-12-11")!).toBe(1);
    expect(toYmd(dayNumber("2024-02-29")!)).toBe("2024-02-29");
  });
  it("écrit une date courte avec espace insécable", () => {
    expect(shortDate(dayNumber("2026-10-12")!)).toBe("12 oct. 2026");
    expect(shortDate(dayNumber("2027-03-01")!)).toBe("1 mars 2027");
  });
});

describe("repères", () => {
  const start = "2026-09-28"; // un lundi
  const end = "2027-02-12";
  it("dit où tombe la date : jour de la semaine et semaine de la session", () => {
    expect(hint("2026-10-02", start, end)).toEqual({ text: "Vendredi, 1re semaine de la session", tone: "ok" });
    expect(hint("2026-10-12", start, end).text).toBe("Lundi, 3e semaine de la session");
  });
  it("alerte avant le début et après la fin, avec le nombre de jours", () => {
    expect(hint("2026-09-27", start, end)).toEqual({ text: "Dimanche, 1 jour avant le début de la session", tone: "alert" });
    expect(hint("2027-02-15", start, end).text).toBe("Lundi, 3 jours après la fin de la session");
  });
  it("sans période : seulement le jour ; vide : à définir ; illisible : erreur", () => {
    expect(hint("2026-10-02", null, null)).toEqual({ text: "Vendredi", tone: "ok" });
    expect(hint("", start, end)).toEqual({ text: "À définir", tone: "empty" });
    expect(hint("2026-02-30", start, end)).toEqual({ text: "Date illisible", tone: "error" });
  });
});

describe("saisie", () => {
  it("part de ce qui est enregistré, une case par tranche", () => {
    expect(draftFrom({ B: { 1: "2026-12-11" } }, types)).toEqual({ A: { 1: "" }, B: { 1: "2026-12-11", 2: "" }, E: { 1: "", 2: "", 3: "", 4: "" } });
  });
  it("chaque tranche doit venir après la précédente ; les tranches vides sont sautées", () => {
    expect(errors({ B: { 1: "2026-12-11", 2: "2027-02-12" } })).toEqual({});
    expect(errors({ B: { 1: "2026-12-11", 2: "2026-12-11" } })["B:2"]).toBe("Après la 1re tranche (11 déc. 2026), pas avant.");
    expect(errors({ B: { 1: "2027-02-12", 2: "2026-12-11" } })["B:2"]).toMatch(/pas avant/);
    expect(errors({ E: { 1: "2026-11-13", 2: "", 3: "2026-12-01", 4: "2026-11-30" } })).toEqual({ "E:4": "Après la 3e tranche (1 déc. 2026), pas avant." });
    expect(errors({ E: { 1: "2026-11-13", 2: "", 3: "2026-11-01" } })["E:3"]).toMatch(/Après la 1re tranche/);
    expect(errors({ A: { 1: "2026-02-30" } })).toEqual({ "A:1": "Date illisible." });
    expect(errors({ A: { 1: "" }, B: { 1: "", 2: "" } })).toEqual({});
  });
  it("compte les champs modifiés par rapport à l'enregistré (y compris un champ vidé)", () => {
    const saved = { B: { 1: "2026-12-11", 2: "2027-02-12" } };
    expect(changedCount(draftFrom(saved, types), saved)).toBe(0);
    expect(changedCount({ A: { 1: "" }, B: { 1: "2026-12-11", 2: "" } }, saved)).toBe(1);
    expect(changedCount({ A: { 1: "2026-12-11" }, B: { 1: "2026-12-12", 2: "2027-02-12" } }, saved)).toBe(2);
  });
  it("envoie tous les rangs des types montrés (un champ vidé retire la tranche) sans toucher à l'original", () => {
    const d = { B: { 1: "2026-12-11", 2: "" } };
    const p = payload(d);
    expect(p).toEqual(d);
    p.B[1] = "x";
    expect(d.B[1]).toBe("2026-12-11");
  });
});

describe("reprise de l'an dernier", () => {
  it("décale de 52 semaines : même jour de la semaine, un an et un jour (ou deux) plus tard", () => {
    const d = shifted({ B: { 1: "2025-12-12", 2: "2026-02-13" }, D: { 1: "2025-10-03" } }, types);
    expect(d.B).toEqual({ 1: "2026-12-11", 2: "2027-02-12" });
    expect(d.A).toEqual({ 1: "" });
    expect(d.D).toBeUndefined(); // type non montré : écarté
    expect(new Date(d.B[1] + "T00:00:00Z").getUTCDay()).toBe(new Date("2025-12-12T00:00:00Z").getUTCDay());
  });
});

describe("frise et textes", () => {
  it("positionne une date entre le début et la fin, bornée", () => {
    expect(position("2026-09-28", "2026-09-28", "2027-02-12")).toBe(0);
    expect(position("2027-02-12", "2026-09-28", "2027-02-12")).toBe(100);
    expect(position("2026-01-01", "2026-09-28", "2027-02-12")).toBe(0);
    expect(position("2028-01-01", "2026-09-28", "2027-02-12")).toBe(100);
    expect(position("2026-12-11", null, "2027-02-12")).toBeNull();
    expect(position("abc", "2026-09-28", "2027-02-12")).toBeNull();
    expect(position("2026-12-11", "2027-02-12", "2026-09-28")).toBeNull();
  });
  it("état de la session, libellés, adresses", () => {
    expect(stateText(0, 7)).toEqual({ text: "Échéances à définir", tone: "alert" });
    expect(stateText(3, 7)).toEqual({ text: "3 dates sur 7", tone: "partial" });
    expect(stateText(7, 7)).toEqual({ text: "Échéances définies", tone: "ok" });
    const s = { id: 1, label: "Premier semestre 2026 - 2027", year: "", semester: 1, start: null, end: null, configured: false };
    expect(optionText(s, false)).toBe("Premier semestre 2026 - 2027 — à définir");
    expect(optionText(s, true)).toBe("Premier semestre 2026 - 2027");
    expect(optionText({ ...s, configured: true }, false)).toBe("Premier semestre 2026 - 2027");
    expect(changesText(1)).toBe("1 date modifiée");
    expect(changesText(3)).toBe("3 dates modifiées");
    expect(deadlinesUrl(null)).toBe("/api/v1/staff/finance/deadlines");
    expect(deadlinesUrl(490)).toBe("/api/v1/staff/finance/deadlines?session=490");
    expect(saveUrl(490)).toBe("/api/v1/staff/finance/deadlines/490");
  });
});
