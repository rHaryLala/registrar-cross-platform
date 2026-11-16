import { beforeEach, describe, expect, it } from "vitest";
import {
  activeChips, activeFilterCount, chooseSort, countText, directoryUrl, EMPTY_QUERY, ficheUrl, flipDirection, isFiltered, moreText, personName, recalledQuery, rememberQuery, removeFilter,
  toggleFilter, type Facet, type Query,
} from "@/lib/directory";

const q = (over: Partial<Query> = {}): Query => ({ ...EMPTY_QUERY, ...over });

describe("adresse de la liste", () => {
  it("n'envoie que ce qui est demandé", () => {
    expect(directoryUrl("students", EMPTY_QUERY)).toBe("/api/v1/staff/lists/students");
    expect(directoryUrl("courses", q({ q: "  algo  " }))).toBe("/api/v1/staff/lists/courses?q=algo");
    expect(directoryUrl("teachers", q({ q: "   " }))).toBe("/api/v1/staff/lists/teachers");
  });
  it("envoie chaque valeur de filtre, le tri, le sens et la page", () => {
    const url = directoryUrl("students", q({ q: "zo", filters: { mention: ["Informatique", "Éducation"], niveau: ["1"] }, sort: "nom", direction: "desc" }), 2);
    const p = new URL(url, "http://x").searchParams;
    expect(p.getAll("mention[]")).toEqual(["Informatique", "Éducation"]);
    expect([p.get("q"), p.getAll("niveau[]"), p.get("tri"), p.get("sens"), p.get("page")]).toEqual(["zo", ["1"], "nom", "desc", "2"]);
  });
  it("encode les caractères spéciaux", () => {
    expect(directoryUrl("students", q({ q: "a&b=c" }))).toBe("/api/v1/staff/lists/students?q=a%26b%3Dc");
  });
  it("la première page n'envoie pas de numéro de page", () => {
    expect(directoryUrl("students", EMPTY_QUERY, 0)).not.toContain("page");
  });
});

describe("filtres", () => {
  it("coche puis décoche une valeur, sans toucher à la requête d'origine", () => {
    const a = q();
    const b = toggleFilter(a, "niveau", "1");
    const c = toggleFilter(b, "niveau", "2");
    expect([a.filters, b.filters, c.filters]).toEqual([{}, { niveau: ["1"] }, { niveau: ["1", "2"] }]);
    expect(toggleFilter(c, "niveau", "1").filters).toEqual({ niveau: ["2"] });
    expect(toggleFilter(b, "niveau", "1").filters).toEqual({});
  });
  it("retirer la dernière mention retire le parcours qui en dépendait ; une mention restante le garde", () => {
    const two = q({ filters: { mention: ["A", "B"], parcours: ["P"] } });
    expect(toggleFilter(two, "mention", "A").filters).toEqual({ mention: ["B"], parcours: ["P"] });
    expect(toggleFilter(q({ filters: { mention: ["A"], parcours: ["P"] } }), "mention", "A").filters).toEqual({});
    expect(removeFilter(two, "mention").filters).toEqual({});
    expect(removeFilter(q({ filters: { niveau: ["1"], parcours: ["P"] } }), "parcours").filters).toEqual({ niveau: ["1"] });
  });
  it("compte les filtres actifs ; le tri seul ne « filtre » pas", () => {
    expect(activeFilterCount(q({ filters: { a: ["1", "2"], b: ["3"] } }))).toBe(3);
    expect([isFiltered(EMPTY_QUERY), isFiltered(q({ sort: "nom" })), isFiltered(q({ q: "x" })), isFiltered(q({ filters: { a: ["1"] } })), isFiltered(q({ q: "  " }))]).toEqual([false, false, true, true, false]);
  });
  it("affiche les filtres actifs avec le libellé de l'option, et « Non renseigné » pour une valeur vide", () => {
    const facets: Record<string, Facet> = { niveau: { label: "Niveau", help: null, options: [{ value: "1", label: "Licence 1", count: 3, on: true }] } };
    expect(activeChips(q({ filters: { niveau: ["1", "9"], parcours: [""] } }), facets)).toEqual([
      { key: "niveau", value: "1", label: "Licence 1", group: "Niveau" },
      { key: "niveau", value: "9", label: "9", group: "Niveau" },
      { key: "parcours", value: "", label: "Non renseigné", group: "parcours" },
    ]);
  });
});

describe("tri", () => {
  const sorts = { nom: { label: "Nom", direction: "asc" as const }, recent: { label: "Plus récents", direction: "desc" as const } };
  it("un nouveau tri prend son sens naturel ; le même tri deux fois s'inverse", () => {
    expect(chooseSort(q(), "recent", { sort: "nom", direction: "asc" }, sorts)).toMatchObject({ sort: "recent", direction: "desc" });
    expect(chooseSort(q(), "nom", { sort: "nom", direction: "asc" }, sorts)).toMatchObject({ sort: "nom", direction: "desc" });
    expect(chooseSort(q(), "nom", { sort: "nom", direction: "desc" }, sorts)).toMatchObject({ direction: "asc" });
  });
  it("inverse le sens du tri en cours, et garde la recherche", () => {
    expect(flipDirection(q({ q: "zo" }), { sort: "recent", direction: "desc" })).toEqual(q({ q: "zo", sort: "recent", direction: "asc" }));
  });
});

describe("textes", () => {
  it("compte en bon français", () => {
    expect([countText("students", 0), countText("students", 1), countText("students", 2280), countText("courses", 1), countText("courses", 757), countText("teachers", 0)]).toEqual([
      "Aucun étudiant", "1 étudiant", "2 280 étudiants", "1 cours", "757 cours", "Aucun enseignant",
    ]);
  });
  it("annonce la suite avec ce qu'elle ajoute", () => {
    expect(moreText(5, 200)).toBe("Afficher la suite (5 de plus)");
    expect(moreText(1000, 200)).toBe("Afficher la suite (200 sur 1 000 de plus)");
  });
  it("écrit le nom en majuscules, sans trou si une partie manque", () => {
    expect([personName("Andria", "Zo"), personName(null, "Zo"), personName("Andria", null), personName(null, null), personName("rabe", " ")]).toEqual(["ANDRIA Zo", "Zo", "ANDRIA", "", "RABE"]);
  });
  it("adresse de la fiche selon la liste", () => {
    expect([ficheUrl("students", 5), ficheUrl("courses", 6), ficheUrl("teachers", 7)]).toEqual(["/etudiants/fiche/?id=5", "/cours/fiche/?id=6", "/enseignants/fiche/?id=7"]);
  });
});

describe("recherche gardée le temps de la session", () => {
  beforeEach(() => sessionStorage.clear());
  it("retrouve la requête gardée, séparément pour chaque liste", () => {
    rememberQuery("students", q({ q: "zo", filters: { niveau: ["1"] }, sort: "nom", direction: "asc" }));
    expect(recalledQuery("students")).toEqual(q({ q: "zo", filters: { niveau: ["1"] }, sort: "nom", direction: "asc" }));
    expect(recalledQuery("courses")).toEqual(EMPTY_QUERY);
  });
  it("ignore un contenu abîmé ou d'un autre format", () => {
    sessionStorage.setItem("registrar:list:students", "{pas du json");
    expect(recalledQuery("students")).toEqual(EMPTY_QUERY);
    sessionStorage.setItem("registrar:list:students", JSON.stringify({ q: 5, filters: { a: "x", b: [1, "ok"] }, sort: 3, direction: "up" }));
    expect(recalledQuery("students")).toEqual({ q: "", filters: { b: ["ok"] }, sort: null, direction: null });
  });
});

import { fileDate, fileUrl, parseFileId } from "@/lib/directory";

describe("fiches", () => {
  it("lit l'identifiant de l'adresse : un entier positif seulement", () => {
    expect([parseFileId("12"), parseFileId("0"), parseFileId("-3"), parseFileId("1.5"), parseFileId("abc"), parseFileId(null), parseFileId(""), parseFileId("1234567890")]).toEqual([12, null, null, null, null, null, null, null]);
  });
  it("adresse de l'API de la fiche", () => {
    expect(fileUrl("students", 9)).toBe("/api/v1/staff/students/9");
    expect(fileUrl("teachers", 3)).toBe("/api/v1/staff/teachers/3");
  });
  it("écrit les dates en français, sans décalage, et laisse le reste", () => {
    expect([fileDate("2001-03-12"), fileDate("2001-03-12 08:00:00"), fileDate(null), fileDate("0000-00-00"), fileDate("2001-02-31"), fileDate("n/a")]).toEqual(["12 mars 2001", "12 mars 2001", null, "0000-00-00", "2001-02-31", "n/a"]);
  });
});
