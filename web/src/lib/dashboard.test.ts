import { describe, expect, it } from "vitest";
import { accord, courseLink, dashboardUrl, nombre, share, shortYear, since, split, toDoText, trend, type ToDoItem } from "@/lib/dashboard";

const now = new Date("2026-11-15T12:00:00");

describe("nombres et accords", () => {
  it("écrit les milliers à la française et accorde dès 2", () => {
    expect(nombre(2280)).toBe("2 280");
    expect([accord(0, "remise", "remises"), accord(1, "remise", "remises"), accord(2, "remise", "remises"), accord(1280, "note", "notes")]).toEqual(["0 remise", "1 remise", "2 remises", "1 280 notes"]);
  });
  it("raccourcit l'année dans les textes", () => {
    expect(shortYear("2025 - 2026")).toBe("2025-2026");
  });
  it("adresse du tableau de bord", () => {
    expect(dashboardUrl(null)).toBe("/api/v1/staff/dashboard");
    expect(dashboardUrl("2025 - 2026")).toBe("/api/v1/staff/dashboard?year=2025%20-%202026");
  });
});

describe("depuis quand", () => {
  it("aujourd'hui, hier, il y a N jours", () => {
    expect([since("2026-11-15 08:00:00", now), since("2026-11-14 08:00:00", now), since("2026-11-10 08:00:00", now), since("2026-11-16 08:00:00", now), since("pas une date", now)]).toEqual([
      "aujourd’hui", "hier", "il y a 5 jours", "aujourd’hui", "",
    ]);
  });
});

describe("textes des éléments à traiter", () => {
  const t = (i: ToDoItem) => toDoText(i, now);
  it("remises à valider, avec l'ancienneté de la plus ancienne", () => {
    expect(t({ key: "reviews", tone: "attention", count: 2, since: "2026-11-10 08:00:00", link: "/validations/" })).toEqual({ title: "2 remises de notes à valider", detail: "la plus ancienne transmise il y a 5 jours" });
    expect(t({ key: "reviews", tone: "attention", count: 1, since: null, link: "/validations/" })).toEqual({ title: "1 remise de notes à valider", detail: "" });
  });
  it("signalements d'absences", () => {
    expect(t({ key: "flags", tone: "attention", count: 3, link: "/assiduite/" })).toEqual({ title: "3 signalements d’absences (15 %)", detail: "à examiner avant les examens" });
  });
  it("candidatures : le détail dit les autres en cours, ou « à instruire »", () => {
    expect(t({ key: "applications", tone: "attention", count: 25, in_progress: 1, link: "/candidatures/" })).toEqual({ title: "25 nouvelles candidatures", detail: "1 autre en cours d’instruction" });
    expect(t({ key: "applications", tone: "attention", count: 1, in_progress: 0, link: "/candidatures/" })).toEqual({ title: "1 nouvelle candidature", detail: "à instruire" });
    expect(t({ key: "applications", tone: "attention", count: 2, in_progress: 4, link: "/candidatures/" }).detail).toBe("4 autres en cours d’instruction");
  });
  it("notes non saisies : dit combien de cours, et que seuls les plus en retard sont listés", () => {
    const list = Array.from({ length: 8 }, (_, i) => ({ course_id: i, code: "C", title: "T", teacher: null, count: 1 }));
    expect(t({ key: "ungraded", tone: "attention", count: 732, courses: 243, year: "2025 - 2026", list })).toEqual({ title: "732 notes non saisies en 2025-2026", detail: "243 cours concernés · les 8 plus en retard ci-dessous" });
    expect(t({ key: "ungraded", tone: "attention", count: 3, courses: 2, year: "2025 - 2026", list: list.slice(0, 2) }).detail).toBe("2 cours concernés");
    expect(t({ key: "ungraded", tone: "attention", count: 1, courses: 1, year: "2025 - 2026", list: list.slice(0, 1) })).toEqual({ title: "1 note non saisie en 2025-2026", detail: "1 cours concerné" });
  });
  it("cours sans enseignant et sigles en double", () => {
    expect(t({ key: "no_teacher", tone: "info", count: 54, year: "2026 - 2027", list: [] })).toEqual({ title: "54 cours offerts sans enseignant", detail: "2026-2027" });
    expect(t({ key: "duplicate_codes", tone: "info", count: 1, list: [] })).toEqual({ title: "1 sigle affiché par deux cours", detail: "renommez l’un des cours pour les distinguer" });
  });
});

describe("chiffres", () => {
  it("l'écart avec l'an dernier : signe, valeur, texte", () => {
    expect(trend(14, 10)).toEqual({ value: 4, sign: "up", text: "+4" });
    expect(trend(10, 1310)).toEqual({ value: -1300, sign: "down", text: "−1 300" });
    expect(trend(5, 5)).toEqual({ value: 0, sign: "flat", text: "0" });
  });
  it("une part en pourcentage entier, sans division par zéro", () => {
    expect([share(1, 3), share(2, 3), share(0, 0), share(5, 0), share(50, 200)]).toEqual([33, 67, 0, 0, 25]);
  });
  it("une répartition : les parts non nulles, plus le reste « non renseigné »", () => {
    expect(split(10, [["Femmes", 4, "a"], ["Hommes", 5, "b"], ["Autre", 0, "c"]])).toEqual([
      { label: "Femmes", n: 4, tone: "a", pct: 40 }, { label: "Hommes", n: 5, tone: "b", pct: 50 }, { label: "Non renseigné", n: 1, tone: "x", pct: 10 },
    ]);
    expect(split(4, [["Femmes", 4, "a"]])).toEqual([{ label: "Femmes", n: 4, tone: "a", pct: 100 }]);
    expect(split(0, [])).toEqual([]);
  });
  it("lien d'un cours", () => {
    expect([courseLink(12), courseLink(null)]).toEqual(["/cours/fiche/?id=12", null]);
  });
});
