import { describe, expect, it } from "vitest";
import { formatAverage, formatGrade, formatLongDate, gradeStateChip, sessionLabel } from "@/lib/format";

describe("formatGrade", () => {
  it("utilise la virgule décimale française", () => {
    expect(formatGrade(14.5)).toBe("14,5");
  });
  it("garde une décimale aux notes entières", () => {
    expect(formatGrade(12)).toBe("12,0");
  });
  it("garde deux décimales pour une moyenne", () => {
    expect(formatGrade(12.96)).toBe("12,96");
  });
  it("écrit un vrai zéro", () => {
    expect(formatGrade(0)).toBe("0,0");
  });
});

describe("formatAverage", () => {
  it("n'invente jamais une moyenne absente", () => {
    expect(formatAverage(null)).toBe("—");
    expect(formatAverage(undefined)).toBe("—");
  });
  it("formate une moyenne existante, même à 0", () => {
    expect(formatAverage(13.2)).toBe("13,2");
    expect(formatAverage(0)).toBe("0,0");
  });
});

describe("gradeStateChip", () => {
  it("dit l'état en mots", () => {
    expect(gradeStateChip("S")).toEqual({ label: "Succès", tone: "ok" });
    expect(gradeStateChip("V")).toEqual({ label: "Validé", tone: "ok" });
    expect(gradeStateChip("E")).toEqual({ label: "Échec", tone: "bad" });
    expect(gradeStateChip("NN")).toEqual({ label: "Pas encore noté", tone: "info" });
  });
});

describe("sessionLabel", () => {
  it("assemble nom et année", () => {
    expect(sessionLabel({ name: "Session 1", year: "2025-2026" })).toBe("Session 1 · 2025-2026");
  });
});

describe("formatLongDate", () => {
  it("écrit la date en français, avec majuscule", () => {
    expect(formatLongDate("2025-09-22")).toBe("Lundi 22 septembre 2025");
    expect(formatLongDate("2026-12-31")).toBe("Jeudi 31 décembre 2026");
  });
  it("ne décale jamais d'un jour (dates en heure locale)", () => {
    expect(formatLongDate("2026-01-01")).toContain("janvier 2026");
    expect(formatLongDate("2026-03-31")).toContain("31 mars");
  });
  it("renvoie le texte tel quel s'il n'est pas une date", () => {
    expect(formatLongDate("n'importe quoi")).toBe("n'importe quoi");
  });
});
