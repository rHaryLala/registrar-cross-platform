import { describe, expect, it } from "vitest";
import { formatAr, formatAverage, formatClock, formatDate, formatDuration, formatGrade, formatLongDate, formatRank, gradeStateChip, maskTail, plural, sessionLabel } from "@/lib/format";

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

describe("formatDuration", () => {
  it("écrit les secondes seules sous une minute", () => {
    expect(formatDuration(42_000)).toBe("42 s");
  });
  it("écrit minutes et secondes", () => {
    expect(formatDuration(72_000)).toBe("1 min 12 s");
  });
  it("n'écrit pas « 0 s » après une minute pile", () => {
    expect(formatDuration(120_000)).toBe("2 min");
  });
  it("arrondit à la seconde la plus proche", () => {
    expect(formatDuration(41_600)).toBe("42 s");
    expect(formatDuration(59_600)).toBe("1 min");
  });
  it("ne renvoie jamais « 0 s »", () => {
    expect(formatDuration(0)).toBe("1 s");
    expect(formatDuration(120)).toBe("1 s");
  });
});

describe("formatRank", () => {
  it("1er puis 2e, 3e…", () => {
    expect(formatRank(1)).toBe("1er");
    expect(formatRank(2)).toBe("2e");
    expect(formatRank(11)).toBe("11e");
  });
});

describe("plural", () => {
  it("0 et 1 au singulier, le reste au pluriel", () => {
    expect(plural(0, "partie")).toBe("0 partie");
    expect(plural(1, "partie")).toBe("1 partie");
    expect(plural(2, "partie")).toBe("2 parties");
  });
  it("accepte un pluriel irrégulier", () => {
    expect(plural(3, "cheval", "chevaux")).toBe("3 chevaux");
  });
});

describe("formatAr", () => {
  it("groupe les milliers et ajoute l'unité, avec des espaces insécables", () => {
    expect(formatAr(1500000)).toBe("1\u00a0500\u00a0000\u00a0Ar");
    expect(formatAr(0)).toBe("0\u00a0Ar");
  });
  it("n'affiche jamais de décimales (l'ariary n'a pas de centimes)", () => {
    expect(formatAr(1234.6)).toBe("1\u00a0235\u00a0Ar");
  });
  it("ne contient aucun espace ordinaire ni fine : le montant ne se coupe pas en fin de ligne", () => {
    expect(formatAr(2335000)).not.toMatch(/[ \u202f]/);
  });
});

describe("formatDate", () => {
  it("écrit la date en français, sans jour de la semaine", () => {
    expect(formatDate("2001-03-12")).toBe("12 mars 2001");
    expect(formatDate("2026-05-01")).toBe("1 mai 2026");
  });
  it("lit la date en heure locale : jamais décalée d'un jour", () => {
    expect(formatDate("2026-01-01")).toBe("1 janvier 2026");
    expect(formatDate("2026-12-31")).toBe("31 décembre 2026");
  });
  it("laisse telle quelle une valeur qui n'est pas une vraie date", () => {
    expect(formatDate("2001-02-30")).toBe("2001-02-30");
    expect(formatDate("hier")).toBe("hier");
    expect(formatDate("")).toBe("");
  });
});

describe("maskTail", () => {
  it("cache tout sauf les 4 derniers caractères", () => {
    expect(maskTail("101 202 303 404")).toBe("•••• •••• 3404");
  });
  it("ne révèle jamais plus que la fin", () => {
    expect(maskTail("123456789012345")).not.toContain("1234");
    expect(maskTail("123456789012345").endsWith("2345")).toBe(true);
  });
  it("cache entièrement un numéro trop court", () => {
    expect(maskTail("1234")).toBe("••••");
    expect(maskTail("12")).toBe("••");
    expect(maskTail("")).toBe("");
  });
});

describe("formatClock", () => {
  it("écrit minutes et secondes sur deux chiffres", () => {
    expect(formatClock(0)).toBe("00:00");
    expect(formatClock(83_000)).toBe("01:23");
    expect(formatClock(59_999)).toBe("00:59");
  });
  it("passe aux heures au-delà de 60 minutes", () => {
    expect(formatClock(3_723_000)).toBe("1:02:03");
  });
  it("ne renvoie jamais de valeur absurde", () => {
    expect(formatClock(-5)).toBe("00:00");
    expect(formatClock(Number.NaN)).toBe("00:00");
    expect(formatClock(Number.POSITIVE_INFINITY)).toBe("00:00");
  });
});
