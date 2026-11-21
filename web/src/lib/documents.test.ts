import { describe, expect, it } from "vitest";
import { canPrintEnrollment, coursesText, enrollmentSheetPdfUrl, enrollmentSheetsUrl, feesText, groupByYear, sheetFallbackName, type EnrollmentSession } from "@/lib/documents";

const s = (session_id: number, year: string, name = "Session 1"): EnrollmentSession => ({
  session_id, session_name: name, year, level_label: "Licence 2", mention: "Informatique", enrolled_on: "2026-09-01", courses: 3, has_fees: true,
});

describe("droit d'imprimer", () => {
  it("niveaux 1 à 4 seulement", () => {
    for (const level of [1, 2, 3, 4]) expect(canPrintEnrollment(level), String(level)).toBe(true);
    for (const level of [0, 5, 6, 7, 8, 9, -1, 14.5]) expect(canPrintEnrollment(level), String(level)).toBe(false);
    expect(canPrintEnrollment(null)).toBe(false);
    expect(canPrintEnrollment(undefined)).toBe(false);
  });
});

describe("adresses", () => {
  it("liste et PDF d'une session", () => {
    expect(enrollmentSheetsUrl(12)).toBe("/api/v1/staff/students/12/enrollment-sheets");
    expect(enrollmentSheetPdfUrl(12, 488)).toBe("/api/v1/staff/students/12/enrollment-sheets/488/pdf");
  });
});

describe("textes", () => {
  it("nom de repli : sans accents, espaces ni caractères spéciaux", () => {
    expect(sheetFallbackName("20481MG", { session_name: "Première session", year: "2026 - 2027" })).toBe("20481MG-fiche-inscription-Premiere-session-2026-2027.pdf");
    expect(sheetFallbackName("../x y", { session_name: "S", year: "2026" })).toBe("xy-fiche-inscription-S-2026.pdf");
  });
  it("cours et frais", () => {
    expect(coursesText(0)).toBe("aucun cours");
    expect(coursesText(1)).toBe("1 cours");
    expect(coursesText(7)).toBe("7 cours");
    expect(feesText(true)).toBe("frais enregistrés");
    expect(feesText(false)).toBe("sans ligne financière");
  });
});

describe("regroupement par année", () => {
  it("garde l'ordre reçu et regroupe les années voisines", () => {
    const groups = groupByYear([s(3, "2026 - 2027", "Session 2"), s(2, "2026 - 2027"), s(1, "2024 - 2025")]);
    expect(groups.map((g) => g.year)).toEqual(["2026 - 2027", "2024 - 2025"]);
    expect(groups[0].sessions.map((x) => x.session_id)).toEqual([3, 2]);
    expect(groupByYear([])).toEqual([]);
  });
});

import { parseScope, scopeChoices, scopeValue, transcriptFallbackName, transcriptOptionsUrl, transcriptPdfUrl, type TranscriptSession } from "@/lib/documents";

const ts = (id: number, year: string, name = "Session 1", courses = 5): TranscriptSession => ({ session_id: id, session_name: name, year, semester: 1, courses });

describe("relevé semestriel : adresses", () => {
  it("liste, tout l'historique, une année, une session", () => {
    expect(transcriptOptionsUrl(12)).toBe("/api/v1/staff/students/12/transcript");
    expect(transcriptPdfUrl(12, { kind: "all" })).toBe("/api/v1/staff/students/12/transcript/pdf");
    expect(transcriptPdfUrl(12, { kind: "year", year: "2025 - 2026" })).toBe("/api/v1/staff/students/12/transcript/pdf?year=2025%20-%202026");
    expect(transcriptPdfUrl(12, { kind: "session", id: 488 })).toBe("/api/v1/staff/students/12/transcript/pdf?session=488");
  });
});

describe("relevé semestriel : choix de la période", () => {
  it("la valeur d'une option se relit à l'identique", () => {
    for (const scope of [{ kind: "all" }, { kind: "year", year: "2025 - 2026" }, { kind: "session", id: 7 }] as const) {
      expect(parseScope(scopeValue(scope))).toEqual(scope);
    }
  });
  it("une valeur illisible retombe sur tout l'historique", () => {
    for (const bad of ["", "x", "session:", "session:abc", "session:-3", "session:0", "session:1.5"]) expect(parseScope(bad), bad).toEqual({ kind: "all" });
  });
  it("propose l'historique, les années seulement s'il y en a plusieurs, puis chaque session", () => {
    const one = scopeChoices([ts(1, "2025 - 2026")]);
    expect(one.map((c) => c.value)).toEqual(["all", "session:1"]);
    expect(one[0].label).toBe("Tout l’historique (1 session)");
    const many = scopeChoices([ts(1, "2025 - 2026"), ts(2, "2025 - 2026", "Session 2", 0), ts(3, "2026 - 2027", "Session 1", 1)]);
    expect(many.map((c) => c.value)).toEqual(["all", "year:2025 - 2026", "year:2026 - 2027", "session:1", "session:2", "session:3"]);
    expect(many[0].label).toBe("Tout l’historique (3 sessions)");
    expect(many[1].label).toBe("Année 2025 - 2026 (2 sessions)");
    expect(many[2].label).toBe("Année 2026 - 2027 (1 session)");
    expect(many[4].label).toBe("Session 2 · 2025 - 2026 (aucun cours)");
    expect(many[5].label).toBe("Session 1 · 2026 - 2027 (1 cours)");
  });
  it("nom de repli", () => {
    expect(transcriptFallbackName("20481MG", { kind: "all" })).toBe("20481MG-releve-semestriel.pdf");
    expect(transcriptFallbackName("20481MG", { kind: "year", year: "2025 - 2026" })).toBe("20481MG-releve-semestriel-2025-2026.pdf");
    expect(transcriptFallbackName("../a b", { kind: "session", id: 3 })).toBe("ab-releve-semestriel.pdf");
  });
});
