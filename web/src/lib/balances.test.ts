import { describe, expect, it } from "vitest";
import { EMPTY_QUERY } from "@/lib/directory";
import { balancesUrl, countText, editText, formatBalance, parseAmount, saveUrl, sessionOptionText, toggleState } from "@/lib/balances";

describe("montant affiché", () => {
  it("une dette s'écrit en négatif, une avance en positif, comme l'ancienne page", () => {
    expect(formatBalance(150000)).toBe("-150 000 Ar");
    expect(formatBalance(-25000)).toBe("+25 000 Ar");
    expect(formatBalance(0)).toBe("0 Ar");
    expect(formatBalance(1234567.4)).toBe("-1 234 567 Ar");
    expect(formatBalance(-0.4)).toBe("+0 Ar"); // comme number_format de l'ancienne page : le signe vient du solde, l'arrondi ne le change pas
  });
  it("espace ordinaire entre les milliers (jamais d'insécable : la recherche et la copie restent simples)", () => {
    expect(formatBalance(1000000)).not.toMatch(/[  ]/);
  });
  it("le champ de saisie reprend la valeur exacte, avec une virgule", () => {
    expect(editText(-25000.5)).toBe("-25000,5");
    expect(editText(150000)).toBe("150000");
    expect(editText(0)).toBe("0");
  });
});

describe("montant saisi", () => {
  it("lit comme le serveur : espaces et insécables retirés, virgule décimale", () => {
    expect(parseAmount("1 500")).toBe(1500);
    expect(parseAmount("2 500,75")).toBe(2500.75);
    expect(parseAmount("-25 000,5")).toBe(-25000.5);
    expect(parseAmount("  42  ")).toBe(42);
    expect(parseAmount("1,005")).toBe(1.01);
    expect(parseAmount("+7")).toBe(7);
    expect(parseAmount(".5")).toBe(0.5);
    expect(parseAmount("0")).toBe(0);
  });
  it("refuse ce qui n'est pas un nombre de la plage de la colonne", () => {
    for (const bad of ["", "abc", "12 abc", "1,2,3", "--5", "0x1A", "1e999", "99999999999999.99", "-10000000000000", "Infinity", "NaN"]) expect(parseAmount(bad), bad).toBeNull();
    expect(parseAmount("9999999999999,99")).toBe(9999999999999.99);
  });
});

describe("adresses et filtres", () => {
  it("n'envoient que ce qui est choisi", () => {
    expect(balancesUrl(null, EMPTY_QUERY)).toBe("/api/v1/staff/finance/balances");
    expect(balancesUrl(488, EMPTY_QUERY)).toBe("/api/v1/staff/finance/balances?session=488");
    expect(balancesUrl(488, { q: " rak ", filters: { etat: ["debitaire", "creditaire"] }, sort: "solde", direction: "asc" }, 1)).toBe(
      "/api/v1/staff/finance/balances?session=488&q=rak&etat%5B%5D=debitaire&etat%5B%5D=creditaire&tri=solde&sens=asc&page=1",
    );
    expect(balancesUrl(null, { ...EMPTY_QUERY, q: "x" })).toBe("/api/v1/staff/finance/balances?q=x");
    expect(saveUrl(488, "40476")).toBe("/api/v1/staff/finance/balances/488/40476");
  });
  it("un état se coche et se décoche ; plusieurs états s'additionnent", () => {
    const a = toggleState(EMPTY_QUERY, "debitaire");
    expect(a.filters.etat).toEqual(["debitaire"]);
    const b = toggleState(a, "solde");
    expect(b.filters.etat).toEqual(["debitaire", "solde"]);
    const c = toggleState(toggleState(b, "debitaire"), "solde");
    expect(c.filters).toEqual({});
    expect(a.filters.etat).toEqual(["debitaire"]); // l'original n'est pas modifié
  });
  it("textes", () => {
    expect(countText(0)).toBe("Aucun étudiant");
    expect(countText(1)).toBe("1 étudiant");
    expect(countText(2249)).toMatch(/^2.249 étudiants$/);
    expect(sessionOptionText({ id: 1, label: "Semestre d'hiver (2025 - 2026 - Semestre 4)", year: "", semester: "", selected: true })).toBe("Semestre d'hiver (2025 - 2026 - Semestre 4) · en cours");
    expect(sessionOptionText({ id: 2, label: "Premier semestre", year: "", semester: "", selected: false })).toBe("Premier semestre");
  });
});
