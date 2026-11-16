import { describe, expect, it } from "vitest";
import type { User } from "@/lib/auth";
import { HOME_ENTRY, MORE_ENTRY, matches, staffBar, staffEntries } from "@/lib/staff-nav";

const base = { teacher_space: false, review_space: false, attendance_space: false, directory: [] as User["directory"], applications: false };

describe("pages du menu du personnel", () => {
  it("n'offre que ce que le compte peut ouvrir, l'accueil en tête", () => {
    expect(staffEntries(null)).toEqual([HOME_ENTRY]);
    expect(staffEntries({ ...base, teacher_space: true }).map((e) => e.label)).toEqual(["Accueil", "Présence", "Notes"]);
    expect(staffEntries({ ...base, review_space: true, directory: ["students"] }).map((e) => e.label)).toEqual(["Accueil", "Validations", "Étudiants"]);
  });
  it("un registraire voit tout, dans l'ordre", () => {
    const all: Parameters<typeof staffEntries>[0] = { teacher_space: false, review_space: true, attendance_space: true, directory: ["students", "courses", "teachers"], applications: true };
    expect(staffEntries(all).map((e) => e.href)).toEqual(["/", "/validations/", "/assiduite/", "/etudiants/", "/cours/", "/enseignants/", "/candidatures/"]);
  });
  it("la finance n'apparaît que pour les comptes qui y ont accès, après les candidatures", () => {
    const rights = { reenrolment: { authorization: true, file: false, reset: false } };
    expect(staffEntries({ ...base, directory: ["students"], finance: rights }).map((e) => e.href)).toEqual(["/", "/etudiants/", "/finance/reinscription/"]);
    expect(staffEntries({ ...base, directory: ["students"], applications: true, finance: rights }).map((e) => e.href)).toEqual(["/", "/etudiants/", "/candidatures/", "/finance/reinscription/"]);
    expect(staffEntries({ ...base, directory: ["students"], finance: null }).map((e) => e.href)).toEqual(["/", "/etudiants/"]);
    expect(staffEntries({ ...base, directory: ["students"] }).map((e) => e.href)).toEqual(["/", "/etudiants/"]);
    expect(matches("/finance/reinscription/", "/finance/reinscription/")).toBe(true);
  });
  it("les pages de finance (soldes, échéances, barème) suivent les droits du compte, dans cet ordre", () => {
    const finance = { reenrolment: { authorization: true, file: true, reset: false }, balances: true, deadlines: true, tariff: true };
    expect(staffEntries({ ...base, directory: ["students"], finance }).map((e) => e.href)).toEqual(["/", "/etudiants/", "/finance/reinscription/", "/finance/soldes/", "/finance/echeances/", "/finance/bareme/"]);
    expect(staffEntries({ ...base, finance: { balances: true, deadlines: true } }).map((e) => e.href)).toEqual(["/", "/finance/soldes/", "/finance/echeances/"]);
    expect(staffEntries({ ...base, finance: { tariff: true } }).map((e) => e.label)).toEqual(["Accueil", "Barème"]);
  });
  it("une liste absente du profil n'a pas d'entrée (jamais de lien mort)", () => {
    expect(staffEntries({ ...base, directory: ["courses"] }).map((e) => e.href)).toEqual(["/", "/cours/"]);
    expect(staffEntries({ ...base, directory: undefined as unknown as User["directory"] }).map((e) => e.href)).toEqual(["/"]);
  });
});

describe("barre du bas : 5 places au plus", () => {
  const entry = (n: number) => ({ href: `/p${n}/`, label: `P${n}`, icon: "home" as const, hint: "" });
  it("tout tient jusqu'à 5 entrées : pas de « Plus »", () => {
    const five = [1, 2, 3, 4, 5].map(entry);
    expect(staffBar(five)).toEqual({ bar: five, more: [] });
  });
  it("au-delà : quatre entrées, « Plus », et le reste rangé dessous, dans l'ordre", () => {
    const seven = [1, 2, 3, 4, 5, 6, 7].map(entry);
    const { bar, more } = staffBar(seven);
    expect(bar).toEqual([...seven.slice(0, 4), MORE_ENTRY]);
    expect(more).toEqual(seven.slice(4));
    expect(bar).toHaveLength(5);
  });
});

describe("entrée allumée", () => {
  it("l'accueil ne correspond qu'à lui-même ; une page garde son entrée sur ses sous-pages", () => {
    expect(matches("/", "/")).toBe(true);
    expect(matches("/", "/etudiants/")).toBe(false);
    expect(matches("/etudiants/", "/etudiants/fiche/")).toBe(true);
    expect(matches("/etudiants/", "/etudiants")).toBe(true);
    expect(matches("/cours/", "/etudiants/")).toBe(false);
    expect(matches("/notes/", "/notesx/")).toBe(false);
  });
});
