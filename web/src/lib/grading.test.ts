import { describe, expect, it } from "vitest";
import {
  applyCell,
  applyComponents,
  checkSheet,
  computeNote,
  formatCell,
  parseComponent,
  parseSpecial,
  parseWeight,
  round2,
  sheetPath,
  sheetUrl,
  slotOf,
  totalWeight,
  type Component,
  type GradeLine,
  type Sheet,
} from "@/lib/grading";

/** Les cinq colonnes de l'ancienne feuille (slots 1 à 5), avec la pondération par défaut. */
const FIVE: Component[] = [
  { slot: 1, label: "Quiz", weight: 10 },
  { slot: 2, label: "Recherche", weight: 20 },
  { slot: 3, label: "Autres", weight: 10 },
  { slot: 4, label: "Examen mi-sem.", weight: 25 },
  { slot: 5, label: "Examen final", weight: 35 },
];
/** Une feuille libre : trois colonnes aux slots quelconques. */
const FREE: Component[] = [
  { slot: 1007, label: "Devoir", weight: 30 },
  { slot: 3, label: "Projet", weight: 20 },
  { slot: 1001, label: "Examen", weight: 50 },
];

const line = (values: Record<string, number | null> = {}, over: Partial<GradeLine> = {}): GradeLine => ({
  student_id: "E1", last_name: "Rabe", first_name: "Zo", level: 1, values, statut_special: null,
  commentaire: null, note: { value: null, state: "empty", detail: "" }, modified_at: null, attendance_exceeded: false, ...over,
});
const full = (over: Partial<GradeLine> = {}) => line({ "1": 10, "2": 12, "3": 14, "4": 16, "5": 18 }, over);
const sheet = (lines: GradeLine[], components: Component[] = FIVE): Sheet => ({
  course: { id: 1, code: "INF", title: "Cours", credits: 3, level: 1, teacher: null, validation_course: false }, year: "2026 - 2027", semester: 1, semester_label: "Semestre 1",
  remise: { id: null, status: "brouillon", editable: true, reject_reason: null, submitted_at: null, processed_at: null, modified_at: null },
  components, total_weight: totalWeight(components), limits: { max_components: 12, max_label: 40 },
  lines: lines.map((l) => ({ ...l, note: computeNote(l.values, l.statut_special, components) })), check: checkSheet(lines, components), journal: [],
});

describe("note calculée (mêmes règles que le serveur)", () => {
  it("moyenne pondérée des cinq colonnes par défaut", () => {
    expect(computeNote(full().values, null, FIVE)).toMatchObject({ value: 15.1, state: "calculated" });
  });
  it("colonnes libres : chaque colonne compte avec SA pondération, quels que soient les slots", () => {
    expect(computeNote({ "1007": 10, "3": 15, "1001": 12 }, null, FREE)).toMatchObject({ value: 12, state: "calculated" }); // 3 + 3 + 6
  });
  it("une seule colonne à 100 % suffit ; douze colonnes aussi", () => {
    expect(computeNote({ "1": 14.5 }, null, [{ slot: 1, label: "Unique", weight: 100 }]).value).toBe(14.5);
    const twelve = Array.from({ length: 12 }, (_, i) => ({ slot: i + 1, label: `C${i + 1}`, weight: i < 4 ? 9 : 8 }));
    expect(totalWeight(twelve)).toBe(100);
    expect(computeNote(Object.fromEntries(twelve.map((c) => [String(c.slot), 10])), null, twelve).value).toBe(10);
  });
  it("une colonne sans poids est ignorée et n'est pas exigée", () => {
    const c = [{ slot: 1, label: "A", weight: 0 }, { slot: 2, label: "B", weight: 50 }, { slot: 3, label: "C", weight: 50 }];
    expect(computeNote({ "2": 10, "3": 15 }, null, c)).toMatchObject({ value: 12.5, state: "calculated" });
  });
  it("une colonne pondérée non saisie rend la ligne incomplète (avec son titre), jamais un prorata", () => {
    expect(computeNote({ "1007": 10 }, null, FREE)).toEqual({ value: null, state: "incomplete", detail: "Manque : Projet, Examen" });
  });
  it("zéro est une note, pas un manque", () => {
    expect(computeNote({ "1": 0, "2": 0, "3": 0, "4": 0, "5": 0 }, null, FIVE)).toMatchObject({ value: 0, state: "calculated" });
  });
  it("rien de saisi, ou aucun poids : ligne vide", () => {
    expect(computeNote({}, null, FIVE)).toMatchObject({ state: "empty", detail: "Aucune note saisie" });
    expect(computeNote(full().values, null, FIVE.map((c) => ({ ...c, weight: 0 })))).toMatchObject({ state: "empty", detail: "Aucune pondération définie" });
  });
  it("une note d'une colonne qui n'existe plus est ignorée", () => {
    expect(computeNote({ "1007": 10, "3": 10, "1001": 10, "999": 20 }, null, FREE).value).toBe(10);
  });
  it("un statut spécial l'emporte, en majuscules, sans tenir compte des espaces", () => {
    expect(computeNote(full().values, "NN", FIVE)).toEqual({ value: null, state: "special", detail: "NN" });
    expect(computeNote(full().values, " v ", FIVE)).toMatchObject({ state: "special", detail: "V" });
  });
  it("arrondit à deux décimales à partir des poids (pas d'un diviseur fixe)", () => {
    const w = [{ slot: 1, label: "a", weight: 1 }, { slot: 2, label: "b", weight: 1 }, { slot: 3, label: "c", weight: 1 }];
    expect(computeNote({ "1": 10, "2": 10, "3": 11 }, null, w).value).toBe(10.33);
    expect(computeNote({ "1": 10, "2": 11, "3": 11 }, null, w).value).toBe(10.67);
  });
  it("round2 : la moitié s'arrondit vers le haut", () => {
    expect([round2(1.005), round2(2.675), round2(12.345), round2(7.0049), round2(0)]).toEqual([1.01, 2.68, 12.35, 7, 0]);
  });
});

describe("contrôle avant transmission", () => {
  it("bloque un total faux, avec la virgule française, pour n'importe quelle structure", () => {
    expect(checkSheet([full()], FIVE.map((c) => (c.slot === 5 ? { ...c, weight: 34.5 } : c))).blockers).toEqual(["La pondération totalise 99,5 % au lieu de 100 %."]);
    expect(checkSheet([line()], FREE.map((c) => (c.slot === 1001 ? { ...c, weight: 40 } : c))).blockers[0]).toBe("La pondération totalise 90 % au lieu de 100 %.");
    expect(checkSheet([full()], FIVE).blockers).toEqual([]);
    expect(checkSheet([line({ "1007": 1, "3": 1, "1001": 1 })], FREE).blockers).toEqual([]);
  });
  it("bloque une classe vide", () => {
    expect(checkSheet([], FIVE).blockers).toEqual(["Aucun étudiant inscrit à ce cours pour cette année et ce semestre."]);
  });
  it("compte les notes prêtes (calculées ou statut spécial), liste les incomplètes, avertit des vides", () => {
    const lines = [
      full({ student_id: "A", last_name: "Prêt" }),
      line({}, { student_id: "B", last_name: "Spécial", statut_special: "V" }),
      line({ "1": 10 }, { student_id: "C", last_name: "Moitié" }),
      line({}, { student_id: "D", last_name: "Rien" }),
    ];
    const r = checkSheet(lines, FIVE);
    expect([r.ready, r.total]).toEqual([2, 4]);
    expect(r.blockers).toEqual(["1 étudiant(s) avec une composante pondérée non saisie : Moitié Zo."]);
    expect(r.warnings).toEqual(["1 étudiant(s) sans aucune note : Rien Zo. Ils seront enregistrés « pas encore noté » (NN)."]);
  });
  it("une note au-dessus de 20, même de peu, bloque ; 20 pile est permis", () => {
    const big = (v: number) => line({ "1": v, "2": v, "3": v, "4": v, "5": v }, { student_id: "A", last_name: "Big" });
    expect(checkSheet([big(20.5)], FIVE).blockers[0]).toBe("Note hors barème 0-20 : Big Zo (20.5).");
    expect(checkSheet([big(20)], FIVE)).toMatchObject({ blockers: [], ready: 1 });
  });
  it("raccourcit les longues listes et nomme par matricule un étudiant sans nom", () => {
    const lines = Array.from({ length: 8 }, (_, i) => line({ "1": 1 }, { student_id: `S${i}`, last_name: `N${i}`, first_name: "" }));
    expect(checkSheet(lines, FIVE).blockers[0]).toContain("N0, N1, N2, N3, N4 et 3 autre(s).");
    expect(checkSheet([line({ "1": 1 }, { student_id: "X99", last_name: null, first_name: null })], FIVE).blockers[0]).toContain("X99");
  });
});

describe("lecture des saisies", () => {
  it("note : virgule ou point, de 0 à 20, vide = rien", () => {
    expect([parseComponent("12,5"), parseComponent(" 12.5 "), parseComponent("0"), parseComponent("20"), parseComponent("12.345"), parseComponent(""), parseComponent("  ")]).toEqual([12.5, 12.5, 0, 20, 12.35, null, null]);
    for (const bad of ["21", "20.01", "-1", "abc", "1e3", "12,5,5", "NaN", "Infinity", "--3"]) expect(parseComponent(bad), bad).toBeUndefined();
  });
  it("poids : vide ou illisible = 0, borné de 0 à 100", () => {
    expect(["", "abc", "-5", "150", "  ", "12,345", "25", "7.5"].map(parseWeight)).toEqual([0, 0, 0, 100, 0, 12.35, 25, 7.5]);
  });
  it("statut : V, E, NN ou vide", () => {
    expect([parseSpecial("v"), parseSpecial(" nn "), parseSpecial(""), parseSpecial("X"), parseSpecial("VE")]).toEqual(["V", "NN", null, undefined, undefined]);
  });
  it("affiche avec la virgule française", () => {
    expect([formatCell(12.5), formatCell(null), formatCell(0), formatCell(7.35), formatCell(10)]).toEqual(["12,5", "", "0", "7,35", "10"]);
  });
  it("retrouve le slot d'un champ", () => {
    expect([slotOf("c1"), slotOf("c1007"), slotOf("statut_special"), slotOf("commentaire")]).toEqual([1, 1007, null, null]);
  });
});

describe("application d'une saisie à la feuille affichée", () => {
  it("recalcule la note de la ligne visée et le contrôle, sans toucher aux autres", () => {
    const s = sheet([full({ student_id: "A" }), line({}, { student_id: "B" })]);
    const next = applyCell(s, "B", "c1", 10, true);
    expect(next.lines[1]).toMatchObject({ values: { "1": 10 }, pending: true, note: { state: "incomplete" } });
    expect(next.lines[0]).toBe(s.lines[0]);
    expect(next.check.blockers[0]).toContain("1 étudiant(s) avec une composante pondérée non saisie");
  });
  it("une colonne libre (slot quelconque) se saisit comme une autre", () => {
    const s = sheet([line({ "1007": 10, "3": 15 })], FREE);
    const next = applyCell(s, "E1", "c1001", 12);
    expect(next.lines[0].note).toMatchObject({ value: 12, state: "calculated" });
  });
  it("ne modifie jamais la feuille d'origine", () => {
    const s = sheet([full({ student_id: "A" })]);
    const frozen = JSON.stringify(s);
    applyCell(s, "A", "c1", 1);
    applyComponents(s, FREE);
    expect(JSON.stringify(s)).toBe(frozen);
  });
  it("un étudiant inconnu ne change rien", () => {
    const s = sheet([full({ student_id: "A" })]);
    expect(applyCell(s, "ZZ", "c1", 1).lines[0].values["1"]).toBe(10);
  });
  it("un statut spécial remplace le calcul", () => {
    const next = applyCell(sheet([full({ student_id: "A" })]), "A", "statut_special", "V");
    expect(next.lines[0].note).toMatchObject({ state: "special", detail: "V" });
    expect(next.check.ready).toBe(1);
  });
  it("une nouvelle liste de colonnes recalcule tout et oublie les notes des colonnes supprimées", () => {
    const s = sheet([full({ student_id: "A" })]);
    const next = applyComponents(s, [{ slot: 5, label: "Final", weight: 60 }, { slot: 1, label: "Contrôle continu", weight: 40 }]);
    expect([next.total_weight, next.lines[0].note.value, Object.keys(next.lines[0].values).sort(), next.check.blockers]).toEqual([100, 14.8, ["1", "5"], []]);
    expect(applyComponents(s, FIVE.map((c) => (c.slot === 5 ? { ...c, weight: 20 } : c))).check.blockers[0]).toContain("85 %");
  });
});

describe("adresses", () => {
  it("construisent les adresses de l'API et de l'écran", () => {
    expect(sheetPath(9, "2026 - 2027", 1)).toBe("/api/v1/teacher/grading/courses/9/sheet?year=2026+-+2027&semester=1");
    expect(sheetPath(9, "2026 - 2027", null)).toBe("/api/v1/teacher/grading/courses/9/sheet?year=2026+-+2027");
    expect(sheetUrl(9, "2026 - 2027", 2)).toBe("/notes/feuille/?cours=9&annee=2026+-+2027&semestre=2");
  });
});
