import { beforeEach, describe, expect, it } from "vitest";
import {
  clearDraft, currentYear, cycleOf, emptyValues, firstError, fromPrefill, googleText, loadDraft, mentionsFor, newToken, saveDraft, suggestUrl, toPayload, tracksFor, validateStep, type Prefill, type Values,
} from "@/lib/student-creation";

const now = new Date("2026-11-15T10:00:00");
const filled = (over: Partial<Values> = {}): Values => ({
  ...emptyValues(now), last_name: "Rabe", first_name: "Zo", birth_date: "2005-03-12", birth_place: "Antsirabe", region: "7", address: "Lot 12", mention: "INFO", track: "Réseaux", ...over,
});

describe("année universitaire et niveaux", () => {
  it("l'année commence en juillet", () => {
    expect([currentYear(new Date("2026-06-30T12:00:00")), currentYear(new Date("2026-07-01T12:00:00")), currentYear(new Date("2026-11-15T12:00:00")), currentYear(new Date("2027-01-02T12:00:00"))]).toEqual(["2025 - 2026", "2026 - 2027", "2026 - 2027", "2026 - 2027"]);
  });
  it("la remise à niveau et les trois années de licence sont en licence, le master à partir de 4", () => {
    expect([0, 1, 2, 3, 4, 5].map((l) => cycleOf(l))).toEqual(["L", "L", "L", "L", "M", "M"]);
    expect(cycleOf("4")).toBe("M");
  });
  it("propose les parcours de la mention qui valent pour le cycle", () => {
    const tracks = [
      { code: "a", label: "Réseaux", mention: "INFO", cycles: "L,M" as const }, { code: "b", label: "Dév", mention: "INFO", cycles: "L" as const },
      { code: "c", label: "IA", mention: "INFO", cycles: "M" as const }, { code: "d", label: "Péda", mention: "EDUC", cycles: "L,M" as const },
    ];
    expect(tracksFor({ tracks }, "INFO", "1").map((t) => t.label)).toEqual(["Réseaux", "Dév"]);
    expect(tracksFor({ tracks }, "INFO", "5").map((t) => t.label)).toEqual(["Réseaux", "IA"]);
    expect(tracksFor({ tracks }, "EDUC", "0").map((t) => t.label)).toEqual(["Péda"]);
    expect(tracksFor({ tracks }, "ZZZ", "1")).toEqual([]);
  });
  it("propose les mentions ouvertes, plus celle de la candidature même fermée", () => {
    const mentions = [{ code: "INFO", label: "Informatique", open: true }, { code: "EDUC", label: "Éducation", open: false }, { code: "CPRE", label: "Cours préparatoire", open: false }];
    expect(mentionsFor({ mentions }, "").map((m) => m.code)).toEqual(["INFO"]);
    expect(mentionsFor({ mentions }, "EDUC").map((m) => m.code)).toEqual(["INFO", "EDUC"]);
  });
});

describe("valeurs de départ", () => {
  it("un formulaire neuf : nationalité et pays par défaut, nouveau étudiant, externe", () => {
    const v = emptyValues(now);
    expect([v.nationality, v.origin_country, v.is_new, v.status, v.level, v.academic_year, v.marital_status, v.children]).toEqual(["Malagasy", "Madagascar", "true", "Externe", "1", "2026 - 2027", "Célibataire", "0"]);
  });
  it("une candidature remplit le formulaire, sans son e-mail personnel", () => {
    const p: Prefill = {
      application_id: 5, application_name: "Rabe Zo", last_name: "Rabe", first_name: "Zo", phone: "034 12 345 67", birth_date: "2005-03-12", birth_place: "Antsirabe", nationality: null, origin_country: "",
      address: "Lot 12", father_name: "Papa", mother_name: null, parent_phone: "0321111111", sponsor_name: "Oncle", sponsor_phone: null, track: "Réseaux", mention: "INFO", sex: 0, marital_status: null, level: 4, is_master: true,
    };
    const v = fromPrefill(p, now);
    expect([v.last_name, v.sex, v.mention, v.level, v.track, v.nationality, v.origin_country, v.marital_status, v.sponsor_last_name, v.mother_name, v.father_name]).toEqual(["Rabe", "0", "INFO", "4", "Réseaux", "Malagasy", "Madagascar", "Célibataire", "Oncle", "", "Papa"]);
    expect(v.email).toBe("");
    expect(fromPrefill({ ...p, sex: null }, now).sex).toBe("1");
  });
});

describe("validation par étape", () => {
  it("l'identité exige nom, prénom, naissance, région et adresse", () => {
    const e = validateStep(0, emptyValues(now), now);
    expect(Object.keys(e).sort()).toEqual(["address", "birth_date", "birth_place", "first_name", "last_name", "region"]);
    expect(validateStep(0, filled(), now)).toEqual({});
  });
  it("refuse une naissance future ou impossible, un e-mail et un nombre d'enfants invalides", () => {
    expect(validateStep(0, filled({ birth_date: "2026-11-15" }), now).birth_date).toMatch(/passée/);
    expect(validateStep(0, filled({ birth_date: "2099-01-01" }), now).birth_date).toBeDefined();
    expect(validateStep(0, filled({ birth_date: "12/03/2005" }), now).birth_date).toMatch(/invalide/);
    expect(validateStep(0, filled({ birth_date: "1899-01-01" }), now).birth_date).toBeDefined();
    expect(validateStep(0, filled({ email: "pas-un-mail" }), now).email).toBeDefined();
    expect(validateStep(0, filled({ email: " Zo.Rabe @zurcher.edu.mg" }), now).email).toBeUndefined();
    expect(validateStep(0, filled({ children: "31" }), now).children).toBeDefined();
    expect(validateStep(0, filled({ children: "-1" }), now).children).toBeDefined();
    expect(validateStep(0, filled({ children: "" }), now).children).toBeDefined();
  });
  it("les études exigent mention et parcours ; le matricule saisi à la main doit être plausible", () => {
    expect(Object.keys(validateStep(1, filled({ mention: "", track: "" }), now)).sort()).toEqual(["mention", "track"]);
    expect(validateStep(1, filled({ student_id: "20481MG" }), now)).toEqual({});
    expect(validateStep(1, filled({ student_id: "abc" }), now).student_id).toBeDefined();
    expect(validateStep(1, filled({ student_id: "1234567890" }), now).student_id).toBeDefined();
  });
  it("le baccalauréat va avec sa série en licence, le diplôme avec sa date en master", () => {
    expect(validateStep(1, filled({ bacc_date: "2022-07-05" }), now).bacc_series).toBeDefined();
    expect(validateStep(1, filled({ bacc_series: "C" }), now).bacc_date).toBeDefined();
    expect(validateStep(1, filled({ bacc_date: "2022-07-05", bacc_series: "C" }), now)).toEqual({});
    expect(validateStep(1, filled({ level: "4", previous_degree: "Licence" }), now).previous_degree_date).toBeDefined();
    expect(validateStep(1, filled({ level: "4", previous_degree_date: "2025-09-01" }), now).previous_degree).toBeDefined();
    expect(validateStep(1, filled({ level: "4", bacc_date: "2022-07-05" }), now)).toEqual({});   // champs de l'autre cycle ignorés
  });
  it("trouve le premier champ en erreur", () => {
    expect(firstError({})).toBeNull();
    expect(firstError({ address: "x", last_name: "y" })).toBe("address");
  });
});

describe("charge utile", () => {
  it("convertit les types et omet les champs facultatifs vides", () => {
    const p = toPayload(filled({ email: " Zo.Rabe @Zurcher.edu.mg", phone: "034 12 345 67", cafeteria: "1", is_new: "false", children: "2" }), "a".repeat(32), null);
    expect(p).toMatchObject({ token: "a".repeat(32), sex: 1, region: 7, level: 1, children: 2, cafeteria: 1, is_new: false, email: "zo.rabe@zurcher.edu.mg", phone: "034 12 345 67" });
    for (const k of ["spouse_name", "cin_number", "visa_number", "father_name", "student_id", "size", "application_id", "bacc_date", "previous_degree", "cin_region"]) expect(p, k).not.toHaveProperty(k);
  });
  it("garde le diplôme du bon cycle seulement", () => {
    const lic = toPayload(filled({ bacc_date: "2022-07-05", bacc_series: "D", previous_degree: "X", previous_degree_date: "2025-01-01" }), "t", null);
    expect(lic).toMatchObject({ bacc_date: "2022-07-05", bacc_series: "D" });
    expect(lic).not.toHaveProperty("previous_degree");
    const mas = toPayload(filled({ level: "5", bacc_date: "2022-07-05", bacc_series: "D", previous_degree: "Licence", previous_degree_date: "2025-01-01" }), "t", null);
    expect(mas).toMatchObject({ previous_degree: "Licence", previous_degree_date: "2025-01-01" });
    expect(mas).not.toHaveProperty("bacc_series");
    expect(toPayload(filled({ bacc_date: "2022-07-05" }), "t", null)).not.toHaveProperty("bacc_date");
  });
  it("porte la candidature d'origine et la région de la CIN", () => {
    expect(toPayload(filled({ cin_region: "3", cin_number: "101 202" }), "t", 42)).toMatchObject({ application_id: 42, cin_region: 3, cin_number: "101 202" });
  });
  it("un jeton fait 32 caractères hexadécimaux, différent à chaque fois", () => {
    const a = newToken();
    expect(a).toMatch(/^[a-f0-9]{32}$/);
    expect(newToken()).not.toBe(a);
    expect(newToken(() => new Uint8Array(16))).toBe("0".repeat(32));
  });
  it("adresse de la proposition de matricule et d'adresse", () => {
    expect(suggestUrl("INFO", "4", "Rabé", "Zo Lala")).toBe("/api/v1/staff/student-creation/suggest?mention=INFO&level=4&last_name=Rab%C3%A9&first_name=Zo+Lala");
    expect(suggestUrl("INFO", "1", " ", "")).toBe("/api/v1/staff/student-creation/suggest?mention=INFO&level=1");
  });
});

describe("compte Google", () => {
  it("dit où on en est, avec un ton, et ne propose de réessayer qu'après un échec", () => {
    expect(googleText({ status: "cree", reason: null })).toMatchObject({ tone: "ok", retry: false });
    expect(googleText({ status: "en_attente", reason: null })).toMatchObject({ tone: "warn", retry: false });
    expect(googleText({ status: "echec", reason: "Google est momentanément indisponible." })).toEqual({ label: "Compte Google non créé : Google est momentanément indisponible.", tone: "bad", retry: true });
    expect(googleText({ status: "echec", reason: null }).label).toBe("Compte Google non créé");
    expect(googleText({ status: "existait", reason: "x existe déjà dans Google." }).label).toBe("x existe déjà dans Google.");
    expect(googleText({ status: "non_configure", reason: null })).toMatchObject({ tone: "info", retry: false });
  });
});

describe("brouillon gardé sur l'appareil", () => {
  beforeEach(() => sessionStorage.clear());
  it("retrouve la saisie et l'étape après un rechargement", () => {
    saveDraft(filled({ last_name: "Rabe" }), 2);
    const d = loadDraft();
    expect([d?.values.last_name, d?.step]).toEqual(["Rabe", 2]);
    clearDraft();
    expect(loadDraft()).toBeNull();
  });
  it("ignore un contenu abîmé et complète les champs absents", () => {
    sessionStorage.setItem("registrar:creation-draft", "{pas du json");
    expect(loadDraft()).toBeNull();
    sessionStorage.setItem("registrar:creation-draft", JSON.stringify({ v: { last_name: "Zo", first_name: 5 }, step: 99 }));
    const d = loadDraft();
    expect([d?.values.last_name, d?.values.first_name, d?.values.nationality, d?.step]).toEqual(["Zo", "", "Malagasy", 0]);
    sessionStorage.setItem("registrar:creation-draft", JSON.stringify({ step: 1 }));
    expect(loadDraft()).toBeNull();
  });
});
