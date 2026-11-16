import { describe, expect, it } from "vitest";
import { applicationUrl, currentNotices, hasFailure, listUrl, nextStatuses, noticeText, notifyPlan, receivedDate, usableEmail, usablePhone, type Notice } from "@/lib/applications";

const notice = (over: Partial<Notice> = {}): Notice => ({ id: 1, channel: "email", decision: "accepte", state: "envoyee", reason: null, attempts: 1, next_attempt_at: null, handled_at: null, ...over });
const person = { email: "zo@exemple.test", phone: "034 12 345 67", is_master: false };

describe("statuts", () => {
  it("propose les autres statuts, jamais « converti », et rien pour une candidature convertie", () => {
    expect(nextStatuses("nouveau")).toEqual(["en_cours", "accepte", "refuse"]);
    expect(nextStatuses("accepte")).toEqual(["nouveau", "en_cours", "refuse"]);
    expect(nextStatuses("converti")).toEqual([]);
  });
  it("adresse de la liste et de la fiche", () => {
    expect(listUrl("")).toBe("/api/v1/staff/applications");
    expect(listUrl("archive")).toBe("/api/v1/staff/applications?status=archive");
    expect(applicationUrl(7)).toBe("/candidatures/fiche/?id=7");
  });
});

describe("numéros et adresses (mêmes règles que le serveur)", () => {
  it("accepte les formes saisies d'un mobile malgache", () => {
    for (const ok of ["0325855186", "032 58 551 86", "+261325855186", "261325855186", "00261325855186", "(032) 58-551-86"]) expect(usablePhone(ok), ok).toBe(true);
    for (const bad of [null, "", "abc", "12345", "0225855186", "03258551", "032585518612", "+33612345678", "0 2 5"]) expect(usablePhone(bad), String(bad)).toBe(false);
  });
  it("reconnaît une adresse e-mail plausible", () => {
    expect([usableEmail("a@b.mg"), usableEmail(" a@b.mg "), usableEmail("pas-un-mail"), usableEmail(""), usableEmail(null), usableEmail("a b@c.d")]).toEqual([true, true, false, false, false, false]);
  });
});

describe("qui sera prévenu", () => {
  it("un courriel et un SMS quand tout est renseigné", () => {
    expect(notifyPlan(person, "accepte")).toEqual([
      { channel: "email", goes: true, text: "Courriel à zo@exemple.test" },
      { channel: "sms", goes: true, text: "SMS au 034 12 345 67" },
    ]);
  });
  it("chaque canal dit pourquoi il ne part pas", () => {
    expect(notifyPlan({ ...person, email: "", phone: "" }, "refuse").map((p) => [p.goes, p.text])).toEqual([[false, "Courriel : le candidat n’a pas laissé d’adresse"], [false, "SMS : le candidat n’a pas laissé de numéro"]]);
    expect(notifyPlan({ ...person, email: "x", phone: "12" }, "refuse").map((p) => p.text)).toEqual(["Courriel : adresse invalide", "SMS : numéro inexploitable"]);
  });
  it("Master : l'acceptation attend la création de l'étudiant pour le courriel (le SMS part), le refus est notifié", () => {
    const master = { ...person, is_master: true };
    const accept = notifyPlan(master, "accepte");
    expect([accept[0].goes, accept[0].text.includes("création de l’étudiant"), accept[1].goes]).toEqual([false, true, true]);
    expect(notifyPlan(master, "refuse")[0].goes).toBe(true);
  });
});

describe("états des notifications", () => {
  it("dit chaque état en toutes lettres", () => {
    expect(noticeText(notice())).toEqual({ label: "Courriel envoyé", tone: "ok" });
    expect(noticeText(notice({ channel: "sms", state: "en_attente", attempts: 0 }))).toEqual({ label: "SMS en cours d’envoi", tone: "warn" });
    expect(noticeText(notice({ state: "en_attente", attempts: 2 }))).toEqual({ label: "Courriel : nouvel essai prévu", tone: "warn" });
    expect(noticeText(notice({ state: "echec", reason: "Refusé par le fournisseur (HTTP 400)." }))).toEqual({ label: "Courriel non envoyé : Refusé par le fournisseur (HTTP 400).", tone: "bad" });
    expect(noticeText(notice({ channel: "sms", state: "ignoree", reason: "Numéro de téléphone inexploitable." }))).toEqual({ label: "SMS non envoyé : Numéro de téléphone inexploitable.", tone: "info" });
    expect(noticeText(notice({ state: "echec", reason: null })).label).toBe("Courriel non envoyé");
    expect(noticeText(notice({ decision: "identifiants", state: "envoyee" })).label).toBe("Courriel d’acceptation avec l’adresse et le mot de passe envoyé");
  });
  it("ne garde que les notifications de la décision actuelle", () => {
    const old = notice({ id: 1, decision: "refuse" });
    const cur = notice({ id: 2, decision: "accepte" });
    expect(currentNotices({ status: "accepte", notifications: [old, cur] })).toEqual([cur]);
    expect(currentNotices({ status: "en_cours", notifications: [old, cur] })).toEqual([]);
    expect(currentNotices({ status: "converti", notifications: [cur] })).toEqual([]);
    const ids = notice({ id: 3, decision: "identifiants" });
    expect(currentNotices({ status: "converti", notifications: [cur, ids] })).toEqual([ids]);   // courriel Master posé à la création de l’étudiant
    expect(currentNotices({ status: "accepte", notifications: [ids] })).toEqual([]);
  });
  it("signale un échec seulement s'il concerne la décision actuelle", () => {
    expect(hasFailure({ status: "accepte", notifications: [notice({ state: "echec" })] })).toBe(true);
    expect(hasFailure({ status: "refuse", notifications: [notice({ state: "echec", decision: "accepte" })] })).toBe(false);
    expect(hasFailure({ status: "accepte", notifications: [notice()] })).toBe(false);
  });
});

describe("date de réception", () => {
  it("écrit la date en français ; vide si absente ; laisse ce qui n'est pas une date", () => {
    expect([receivedDate("2026-09-10 10:00:00"), receivedDate(null), receivedDate("pas une date")]).toEqual(["10 septembre 2026", "", "pas une date"]);
  });
});
