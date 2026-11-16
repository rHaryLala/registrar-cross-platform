import { describe, expect, it } from "vitest";
import { BULK_MAX, bulkOutcome, gradeLabel, isSelectable, pruneSelection, reviewUrl, secondsLeft, selectAll, toggleSelection, willChange, type BulkSkipped, type QueueItem } from "@/lib/review";

describe("libellé d'une note stockée", () => {
  it("une note chiffrée s'écrit avec la virgule française, un code en mot", () => {
    expect([gradeLabel("15.1"), gradeLabel("12"), gradeLabel("8.50"), gradeLabel("0"), gradeLabel("12,5")]).toEqual(["15,1", "12", "8,5", "0", "12,5"]);
    expect([gradeLabel("-2"), gradeLabel("-3"), gradeLabel("-4")]).toEqual(["Validé", "Échec", "Pas encore noté"]);
  });
  it("rien en base : un tiret ; une ancienne saisie libre reste lisible telle quelle", () => {
    expect([gradeLabel(null), gradeLabel(""), gradeLabel("abandon")]).toEqual(["—", "—", "abandon"]);
  });
});

describe("ce que la validation change", () => {
  it("compare la note actuelle à celle qui serait écrite", () => {
    expect(willChange({ current_grade: null, new_grade: "15.1" })).toBe(true);
    expect(willChange({ current_grade: "12", new_grade: "12" })).toBe(false);
    expect(willChange({ current_grade: "12", new_grade: "-2" })).toBe(true);
    expect(willChange({ current_grade: "", new_grade: "-4" })).toBe(true);
  });
  it("une ligne sans inscription liée ne change rien", () => {
    expect(willChange({ current_grade: null, new_grade: null })).toBe(false);
  });
});

describe("compte à rebours", () => {
  it("arrondit vers le haut et ne descend jamais sous zéro", () => {
    expect([secondsLeft(10_000), secondsLeft(9_001), secondsLeft(1), secondsLeft(0), secondsLeft(-500)]).toEqual([10, 10, 1, 0, 0]);
  });
});

describe("adresse", () => {
  it("passe l'identifiant dans la chaîne de requête (site statique)", () => {
    expect(reviewUrl(42)).toBe("/validations/feuille/?id=42");
  });
});

describe("validation groupée : sélection", () => {
  const item = (id: number, over: Partial<QueueItem> = {}): QueueItem => ({
    id, course_id: id, code: `C${id}`, title: "T", year: "2026 - 2027", semester: 1, status: "soumise", teacher: null, submitted_at: null, processed_at: null, reject_reason: null,
    modified_at: null, pending_until: null, summary: { ready: 3, total: 3, blockers: 0, warnings: 0 }, ...over,
  });

  it("n'offre que les feuilles transmises, sans point bloquant et pas déjà en cours", () => {
    expect(isSelectable(item(1))).toBe(true);
    expect(isSelectable(item(2, { summary: { ready: 1, total: 3, blockers: 2, warnings: 0 } }))).toBe(false);
    expect(isSelectable(item(3, { status: "brouillon", summary: null }))).toBe(false);
    expect(isSelectable(item(4, { status: "validee" }))).toBe(false);
    expect(isSelectable(item(5, { pending_until: "2026-11-16 10:00:10" }))).toBe(false);
    expect(isSelectable(item(6, { summary: null }))).toBe(false);
  });
  it("bascule une feuille, sans modifier l'ancienne sélection", () => {
    const a = new Set([1]);
    const b = toggleSelection(a, 2);
    expect([[...a], [...b]]).toEqual([[1], [1, 2]]);
    expect([...toggleSelection(b, 1)]).toEqual([2]);
  });
  it("s'arrête au plafond de 25 feuilles", () => {
    const full = new Set(Array.from({ length: BULK_MAX }, (_, i) => i + 1));
    expect(toggleSelection(full, 99).size).toBe(BULK_MAX);
    expect(toggleSelection(full, 99).has(99)).toBe(false);
    expect(toggleSelection(full, 1).size).toBe(BULK_MAX - 1);
  });
  it("« tout sélectionner » prend les feuilles sélectionnables, dans l'ordre, jusqu'au plafond", () => {
    const items = [item(1), item(2, { summary: { ready: 0, total: 1, blockers: 1, warnings: 0 } }), item(3)];
    expect([...selectAll(items)]).toEqual([1, 3]);
    const many = Array.from({ length: 30 }, (_, i) => item(i + 1));
    expect(selectAll(many).size).toBe(BULK_MAX);
    expect(selectAll(many).has(26)).toBe(false);
  });
  it("retire de la sélection ce qui a disparu ou n'est plus sélectionnable", () => {
    const items = [item(1), item(2, { pending_until: "x" })];
    expect([...pruneSelection(new Set([1, 2, 3]), items)]).toEqual([1]);
  });
});

describe("validation groupée : message de fin", () => {
  const skip = (id: number, detail: string | null = "Incohérences à lever avant validation."): BulkSkipped => ({ id, code: "review.not_ready", title: "Incohérences à lever", detail });
  it("tout est validé", () => {
    expect(bulkOutcome(1, [])).toEqual({ title: "1 feuille validée", tone: "success" });
    expect(bulkOutcome(3, [])).toEqual({ title: "3 feuilles validées", tone: "success" });
  });
  it("une partie seulement : dit combien, et pourquoi pas les autres", () => {
    const o = bulkOutcome(2, [skip(7), skip(9, null)]);
    expect(o.tone).toBe("warning");
    expect(o.title).toBe("2 feuilles validées, 2 non validées");
    expect(o.description).toBe("#7 : Incohérences à lever avant validation. · #9 : Incohérences à lever");
    expect(bulkOutcome(1, [skip(7)]).title).toBe("1 feuille validée, 1 non validée");
  });
  it("aucune feuille validée : c'est une erreur", () => {
    expect(bulkOutcome(0, [skip(7)])).toMatchObject({ title: "Aucune feuille validée", tone: "error" });
  });
});
