import { describe, expect, it } from "vitest";
import { EMPTY_QUERY } from "@/lib/directory";
import {
  batchText, bulkActions, BULK_MAX, confirmationOk, dayFr, patchRow, previewUrl, reenrolmentUrl, resetScope, selectedText, selectionProblem, stateOf, statsAfter, toggleUrl, trace, undoUrl, withFlag,
  type ReenrolmentPage, type ReenrolmentRow, type ReenrolmentStats,
} from "@/lib/reenrolment";

const flag = (value: boolean, by: string | null = null, at: string | null = null) => ({ value, by, at });
const row = (id: number, authorization: boolean, file: boolean): ReenrolmentRow => ({
  id, student_id: `E${id}`, last_name: "Andria", first_name: "Zo", mention: "Informatique", track: null, year: "2025-2026", residence: "Externe", level: 1, level_label: "Licence 1",
  authorization: flag(authorization), file: flag(file), state: stateOf(authorization, file),
});
const stats: ReenrolmentStats = { total: 10, prets: 2, attente_caisse: 3, attente_dossier: 1, aucun: 4, autorises: 3, dossiers_ok: 5 };

describe("état d'un étudiant", () => {
  it("les quatre états s'excluent", () => {
    expect(stateOf(true, true)).toBe("prets");
    expect(stateOf(false, true)).toBe("attente_caisse");
    expect(stateOf(true, false)).toBe("attente_dossier");
    expect(stateOf(false, false)).toBe("aucun");
  });
});

describe("trace « qui, quand »", () => {
  it("lit le jour dans le texte du serveur, sans passer par le fuseau du navigateur", () => {
    expect(dayFr("2026-09-12T23:30:00+03:00")).toBe("12/09/2026");
    expect(dayFr("2026-09-12T00:00:00+03:00")).toBe("12/09/2026");
    expect(dayFr(null)).toBeNull();
    expect(dayFr("0000-00-00")).toBeNull();
  });
  it("personne : rien ; un auteur : son nom et le jour", () => {
    expect(trace(flag(false))).toBe("");
    expect(trace(flag(true, "Sata Faneva NALAHATRINIAVO", "2026-11-09T10:00:00+03:00"))).toBe("Sata Faneva NALAHATRINIAVO · 09/11/2026");
    expect(trace(flag(true, "Sata", null))).toBe("Sata");
    expect(trace(flag(true, null, "2026-11-09T10:00:00+03:00"))).toBe("");
  });
});

describe("droits et actions groupées", () => {
  it("la caisse n'a que les actions de sa colonne ; le superadmin a les quatre", () => {
    expect(bulkActions({ authorization: true, file: false, reset: false }).map((a) => a.label)).toEqual(["Autoriser", "Retirer l’autorisation"]);
    expect(bulkActions({ authorization: false, file: true, reset: false }).map((a) => `${a.field}:${a.value}`)).toEqual(["file:true", "file:false"]);
    expect(bulkActions({ authorization: true, file: true, reset: true })).toHaveLength(4);
    expect(bulkActions({ authorization: false, file: false, reset: false })).toEqual([]);
  });
  it("plafond d'une action groupée", () => {
    expect(selectionProblem(0)).toBeNull();
    expect(selectionProblem(BULK_MAX)).toBeNull();
    expect(selectionProblem(BULK_MAX + 3)).toContain("retirez-en 3");
    expect(selectedText(1)).toBe("1 étudiant sélectionné");
    expect(selectedText(12)).toBe("12 étudiants sélectionnés");
  });
});

describe("affichage immédiat d'une bascule", () => {
  it("la ligne change d'état, les compteurs suivent, le total ne bouge pas", () => {
    const r = row(1, false, true); // attente_caisse
    const after = withFlag(r, "authorization", true);
    expect(after.state).toBe("prets");
    expect(r.authorization.value).toBe(false); // l'original n'est pas modifié
    const s = statsAfter(stats, r, "authorization", true);
    expect(s).toMatchObject({ total: 10, attente_caisse: 2, prets: 3, autorises: 4, dossiers_ok: 5 });
    expect(s.prets + s.attente_caisse + s.attente_dossier + s.aucun).toBe(s.total);
  });
  it("retirer un dossier d'un étudiant prêt le remet « en attente du dossier »", () => {
    const s = statsAfter(stats, row(2, true, true), "file", false);
    expect(s).toMatchObject({ prets: 1, attente_dossier: 2, dossiers_ok: 4, autorises: 3 });
  });
  it("déjà dans l'état demandé : rien ne change", () => {
    expect(statsAfter(stats, row(3, true, true), "authorization", true)).toBe(stats);
  });
  it("la réponse du serveur remplace la ligne et les compteurs", () => {
    const page = { rows: [row(1, false, false), row(2, false, false)], stats } as unknown as ReenrolmentPage;
    const server = { ...row(2, true, false), authorization: flag(true, "Caissier Un", "2026-11-16T09:00:00+03:00") };
    const next = patchRow(page, server, { ...stats, aucun: 3 });
    expect(next.rows[1].authorization.by).toBe("Caissier Un");
    expect(next.rows[0]).toBe(page.rows[0]);
    expect(next.stats.aucun).toBe(3);
    expect(patchRow(page, server).stats).toBe(stats);
  });
});

describe("réinitialisation globale", () => {
  it("la phrase de confirmation tolère casse, accents et espaces", () => {
    for (const ok of ["RÉINITIALISER", "réinitialiser", " Reinitialiser ", "REINITIALISER"]) expect(confirmationOk(ok)).toBe(true);
    for (const ko of ["", "REINITIALISE", "REINITIALISER TOUT", "oui"]) expect(confirmationOk(ko)).toBe(false);
  });
  it("dit la portée en clair", () => {
    expect(resetScope({ by_field: { authorization: 457, file: 460 }, students: 917, blocked: 400, available: true })).toBe(
      "457 autorisations de la caisse et 460 validations de dossier, pour 917 étudiants. 400 ne pourront plus se réinscrire.",
    );
    expect(resetScope({ by_field: { file: 1 }, students: 1, blocked: 1, available: true })).toBe("1 validation de dossier, pour 1 étudiant. 1 ne pourra plus se réinscrire.");
    expect(resetScope({ by_field: { file: 3 }, students: 3, blocked: 0, available: true })).toBe("3 validations de dossier, pour 3 étudiants.");
    expect(resetScope({ by_field: {}, students: 0, blocked: 0, available: true })).toBe("Choisissez au moins une colonne à réinitialiser.");
  });
  it("décrit le lot annulable", () => {
    expect(batchText({ lot: "a".repeat(16), at: "2026-11-16T14:00:07+03:00", by: "Hary Lala", rows: 917, columns: ["authorization", "file"] })).toBe(
      "Réinitialisation du 16/11/2026 à 14:00 par Hary Lala : 917 cases remises à « non coché » (autorisations et dossiers).",
    );
    expect(batchText({ lot: "a".repeat(16), at: "2026-11-16T14:00:07+03:00", by: null, rows: 1, columns: ["file"] })).toBe("Réinitialisation du 16/11/2026 à 14:00 : 1 case remise à « non coché » (dossiers).");
  });
});

describe("adresses de l'API", () => {
  it("ne demandent que ce qui est choisi", () => {
    expect(reenrolmentUrl(EMPTY_QUERY)).toBe("/api/v1/staff/finance/reenrolment");
    expect(reenrolmentUrl({ q: " rak ", filters: { etat: ["prets", "aucun"] }, sort: "nom", direction: "asc" }, 2)).toBe(
      "/api/v1/staff/finance/reenrolment?q=rak&etat%5B%5D=prets&etat%5B%5D=aucun&tri=nom&sens=asc&page=2",
    );
    expect(toggleUrl(12, "file")).toBe("/api/v1/staff/finance/reenrolment/students/12/file");
    expect(previewUrl([])).toBe("/api/v1/staff/finance/reenrolment/reset/preview");
    expect(previewUrl(["authorization", "file"])).toBe("/api/v1/staff/finance/reenrolment/reset/preview?columns[]=authorization&columns[]=file");
    expect(undoUrl("0123456789abcdef")).toBe("/api/v1/staff/finance/reenrolment/reset/0123456789abcdef/undo");
  });
});
