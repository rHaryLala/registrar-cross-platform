import { describe, expect, it } from "vitest";
import {
  adjustedText, cellKey, dirtyCount, draftErrors, EMPTY_DRAFT, feeUrl, inputText, modifiedText, parseTariffAmount, savePayload, semestersOf, semestersText, shownExtra, shownText, stateText, tariffUrl,
  withAmount, withExtra, type MentionCard,
} from "@/lib/tariff";

const card: MentionCard = {
  sigle: "INFO", label: "Informatique", adjusted: true, no_tariff: false,
  fields: [{ column: "frais_generaux", label: "Frais généraux" }, { column: "ecolage", label: "Écolage" }],
  grid: {
    "1|Interne": { source: "session", values: { frais_generaux: 150000, ecolage: 12.5 } },
    "1|Externe": { source: "base", values: { frais_generaux: 90000, ecolage: 0 } },
    "2|Interne": { source: "none", values: null },
  },
  extra: { "7": { "1|Interne": { amount: 5000, inherited: true }, "1|Externe": { amount: null, inherited: false } } },
};

describe("saisie d'un montant", () => {
  it("lit à la française, refuse le négatif et l'illisible", () => {
    expect(parseTariffAmount("150 000")).toBe(150000);
    expect(parseTariffAmount("12,5")).toBe(12.5);
    expect(parseTariffAmount("0")).toBe(0);
    for (const bad of ["", "abc", "-1", "1e999", "9999999999999"]) expect(parseTariffAmount(bad), bad).toBeNull();
  });
  it("écrit une valeur enregistrée avec une virgule, vide si la case n'a pas de tarif", () => {
    expect(inputText(12.5)).toBe("12,5");
    expect(inputText(150000)).toBe("150000");
    expect(inputText(0)).toBe("0");
    expect(inputText(null)).toBe("");
    expect(inputText(undefined)).toBe("");
  });
});

describe("brouillon", () => {
  it("affiche la valeur enregistrée, ou ce qui a été saisi", () => {
    expect(shownText(EMPTY_DRAFT, card, "1|Interne", "frais_generaux")).toBe("150000");
    expect(shownText(EMPTY_DRAFT, card, "2|Interne", "ecolage")).toBe("");
    const d = withAmount(EMPTY_DRAFT, card, "1|Interne", "frais_generaux", "160000");
    expect(shownText(d, card, "1|Interne", "frais_generaux")).toBe("160000");
    expect(shownExtra(EMPTY_DRAFT, card, 7, "1|Interne")).toBe("5000");
    expect(shownExtra(EMPTY_DRAFT, card, 7, "1|Externe")).toBe("");
  });
  it("rétablir la valeur enregistrée retire la modification ; l'original n'est pas modifié", () => {
    const d1 = withAmount(EMPTY_DRAFT, card, "1|Interne", "frais_generaux", "160000");
    expect(dirtyCount(d1)).toBe(1);
    expect(dirtyCount(EMPTY_DRAFT)).toBe(0);
    const d2 = withAmount(d1, card, "1|Interne", "frais_generaux", "150000");
    expect(dirtyCount(d2)).toBe(0);
    expect(d2.amounts).toEqual({});
    const d3 = withExtra(withAmount(d1, card, "1|Externe", "ecolage", "5"), card, 7, "1|Interne", "");
    expect(dirtyCount(d3)).toBe(3);
    expect(dirtyCount(withExtra(d3, card, 7, "1|Interne", "5000"))).toBe(2);
  });
  it("signale les montants illisibles, mais pas un champ de barème vidé", () => {
    let d = withAmount(EMPTY_DRAFT, card, "1|Interne", "frais_generaux", "abc");
    d = withAmount(d, card, "1|Interne", "ecolage", "");
    d = withExtra(d, card, 7, "1|Externe", "-3");
    expect(draftErrors(d)).toEqual({ "1|Interne|frais_generaux": "Montant invalide", "extra|7|1|Externe": "Montant invalide" });
    expect(draftErrors(withExtra(EMPTY_DRAFT, card, 7, "1|Interne", ""))).toEqual({});
  });
  it("n'envoie que les champs modifiés et lisibles ; une case de frais vidée part vide", () => {
    let d = withAmount(EMPTY_DRAFT, card, "1|Interne", "frais_generaux", "160 000");
    d = withAmount(d, card, "1|Interne", "ecolage", "");   // vidé : non envoyé (le barème n'efface jamais un montant)
    d = withAmount(d, card, "2|Interne", "ecolage", "abc");   // illisible : non envoyé
    d = withExtra(d, card, 7, "1|Interne", "");
    d = withExtra(d, card, 7, "1|Externe", "0");
    expect(savePayload(d, 488, "INFO", ["GEST"], 1)).toEqual({
      session_id: 488, mention: "INFO", mentions: ["GEST"], semester: 1,
      amounts: { "1|Interne": { frais_generaux: 160000 } }, extra: { "7": { "1|Interne": "", "1|Externe": 0 } },
    });
  });
});

describe("textes et adresses", () => {
  it("état d'une filière", () => {
    expect(stateText({ ...card, no_tariff: true }, false)?.tone).toBe("alert");
    expect(stateText(card, false)).toEqual({ text: "Tarif propre à cette session", tone: "own" });
    expect(stateText({ ...card, adjusted: false }, false)).toEqual({ text: "Hérite du barème de base", tone: "base" });
    expect(stateText(card, true)).toBeNull();
    expect(adjustedText(0, 7)).toBe("Aucun ajustement : hérite du barème de base");
    expect(adjustedText(3, 7)).toBe("3 / 7 filières ajustées");
    expect(adjustedText(1, 1)).toBe("1 / 1 filière ajustée");
    expect(modifiedText(1)).toBe("1 montant modifié");
    expect(modifiedText(4)).toBe("4 montants modifiés");
  });
  it("adresses", () => {
    expect(tariffUrl(null, null, false)).toBe("/api/v1/staff/finance/tariff");
    expect(tariffUrl("2026 - 2027", 2, false)).toBe("/api/v1/staff/finance/tariff?annee=2026+-+2027&semestre=2");
    expect(tariffUrl("2026 - 2027", 1, true)).toBe("/api/v1/staff/finance/tariff?annee=2026+-+2027&semestre=1&base=1");
    expect(feeUrl(3)).toBe("/api/v1/staff/finance/extra-fees/3");
    expect(cellKey(4, "Externe")).toBe("4|Externe");
  });
  it("semestres d'un frais", () => {
    expect(semestersOf("")).toEqual([]);
    expect(semestersOf("1,2")).toEqual([1, 2]);
    expect(semestersOf(" 2 , 9")).toEqual([2]);
    expect(semestersText([2, 1, 2])).toBe("1,2");
    expect(semestersText([])).toBe("");
  });
});
