/**
 * Création d'un étudiant : formes des réponses de l'API (/api/v1/staff/student-creation, /api/v1/staff/students) et logique du formulaire (valeurs par défaut,
 * validation par étape, charge utile). Le serveur revalide tout ; ici, on évite seulement un aller-retour inutile et on dit tout de suite ce qui manque.
 */

export type Options = {
  mentions: { code: string; label: string; open: boolean }[];
  tracks: { code: string; label: string; mention: string; cycles: "L" | "M" | "L,M" }[];
  regions: { id: number; name: string }[];
  years: string[];
  statuses: string[];
  marital: string[];
  religions: string[];
  series: string[];
  sizes: string[];
};

export type Suggestion = { student_id: string; email: string; email_usual: string };

export type GoogleState = "non_configure" | "en_attente" | "cree" | "existait" | "echec";

export type Created = {
  id: number;
  student_id: string;
  email: string | null;
  email_notice: string | null;
  initial_password: string | null;
  already: boolean;
  application_converted: boolean;
  application_id: number | null;
  google: { status: GoogleState; reason: string | null };
};

export type Prefill = {
  application_id: number;
  application_name: string;
  last_name: string;
  first_name: string;
  phone: string | null;
  birth_date: string | null;
  birth_place: string | null;
  nationality: string | null;
  origin_country: string | null;
  address: string | null;
  father_name: string | null;
  mother_name: string | null;
  parent_phone: string | null;
  sponsor_name: string | null;
  sponsor_phone: string | null;
  track: string | null;
  mention: string;
  sex: number | null;
  marital_status: string | null;
  level: number;
  is_master: boolean;
};

export type Values = {
  last_name: string; first_name: string; sex: string; birth_date: string; birth_place: string; nationality: string; origin_country: string;
  marital_status: string; religion: string; spouse_name: string; children: string; cin_number: string; cin_date: string; cin_region: string; visa_number: string;
  phone: string; email: string; region: string; address: string;
  mention: string; track: string; level: string; academic_year: string; status: string; is_new: string; cafeteria: string; size: string; student_id: string;
  bacc_date: string; bacc_series: string; previous_degree: string; previous_degree_date: string;
  father_name: string; father_job: string; mother_name: string; mother_job: string; parent_phone: string; parent_address: string;
  sponsor_last_name: string; sponsor_first_name: string; sponsor_phone: string; sponsor_address: string;
};

export const STEPS = [
  { id: "identity", title: "Identité et contact" },
  { id: "studies", title: "Études" },
  { id: "family", title: "Famille et garant" },
  { id: "review", title: "Vérifier et créer" },
] as const;

export const creationKeys = {
  options: ["staff", "creation", "options"] as const,
  prefill: (id: number) => ["staff", "creation", "prefill", id] as const,
  google: (id: number) => ["staff", "creation", "google", id] as const,
};

/** L'année universitaire proposée : celle qui commence en juillet de l'année en cours (ou l'an dernier avant juillet). */
export function currentYear(now: Date = new Date()): string {
  const start = now.getMonth() >= 6 ? now.getFullYear() : now.getFullYear() - 1;
  return `${start} - ${start + 1}`;
}

export function emptyValues(now: Date = new Date()): Values {
  return {
    last_name: "", first_name: "", sex: "1", birth_date: "", birth_place: "", nationality: "Malagasy", origin_country: "Madagascar", marital_status: "Célibataire", religion: "Adventiste",
    spouse_name: "", children: "0", cin_number: "", cin_date: "", cin_region: "", visa_number: "", phone: "", email: "", region: "", address: "",
    mention: "", track: "", level: "1", academic_year: currentYear(now), status: "Externe", is_new: "true", cafeteria: "0", size: "", student_id: "",
    bacc_date: "", bacc_series: "", previous_degree: "", previous_degree_date: "",
    father_name: "", father_job: "", mother_name: "", mother_job: "", parent_phone: "", parent_address: "",
    sponsor_last_name: "", sponsor_first_name: "", sponsor_phone: "", sponsor_address: "",
  };
}

/** Les valeurs du formulaire à partir d'une candidature acceptée (l'e-mail personnel n'est pas repris : l'adresse étudiante est dérivée du nom). */
export function fromPrefill(p: Prefill, now: Date = new Date()): Values {
  const base = emptyValues(now);
  const take = (v: string | null | undefined, fallback: string) => (v && v.trim() !== "" ? v.trim() : fallback);
  return {
    ...base,
    last_name: take(p.last_name, ""), first_name: take(p.first_name, ""), sex: p.sex === null ? base.sex : String(p.sex), birth_date: take(p.birth_date, ""), birth_place: take(p.birth_place, ""),
    nationality: take(p.nationality, base.nationality), origin_country: take(p.origin_country, base.origin_country), marital_status: take(p.marital_status, base.marital_status),
    phone: take(p.phone, ""), address: take(p.address, ""), mention: p.mention, track: take(p.track, ""), level: String(p.level),
    father_name: take(p.father_name, ""), mother_name: take(p.mother_name, ""), parent_phone: take(p.parent_phone, ""), sponsor_last_name: take(p.sponsor_name, ""), sponsor_phone: take(p.sponsor_phone, ""),
  };
}

/** Le cycle d'un niveau : « M » à partir du Master 1, « L » sinon (la remise à niveau est en licence). */
export function cycleOf(level: string | number): "L" | "M" {
  return Number(level) >= 4 ? "M" : "L";
}

/** Les parcours proposés pour une mention et un niveau. */
export function tracksFor(options: Pick<Options, "tracks">, mention: string, level: string): Options["tracks"] {
  return options.tracks.filter((t) => t.mention === mention && t.cycles.includes(cycleOf(level)));
}

/** Les mentions proposées : les ouvertes, plus celle de la candidature même fermée (sinon le choix du candidat serait perdu). */
export function mentionsFor(options: Pick<Options, "mentions">, keep: string): Options["mentions"] {
  return options.mentions.filter((m) => m.open || m.code === keep);
}

export type Errors = Partial<Record<keyof Values, string>>;

const required = (v: string) => v.trim() === "";

/** Ce qui manque ou est faux à une étape (0 : identité et contact, 1 : études, 2 : famille). */
export function validateStep(step: number, v: Values, now: Date = new Date()): Errors {
  const e: Errors = {};
  if (step === 0) {
    if (required(v.last_name)) e.last_name = "Indiquez le nom.";
    if (required(v.first_name)) e.first_name = "Indiquez le prénom.";
    if (required(v.birth_date)) e.birth_date = "Indiquez la date de naissance.";
    else if (!/^\d{4}-\d{2}-\d{2}$/.test(v.birth_date) || v.birth_date < "1900-01-02" || v.birth_date >= now.toISOString().slice(0, 10)) e.birth_date = "Date de naissance invalide (elle doit être passée).";
    if (required(v.birth_place)) e.birth_place = "Indiquez le lieu de naissance.";
    if (required(v.nationality)) e.nationality = "Indiquez la nationalité.";
    if (required(v.origin_country)) e.origin_country = "Indiquez le pays d’origine.";
    if (required(v.region)) e.region = "Choisissez la région.";
    if (required(v.address)) e.address = "Indiquez l’adresse actuelle.";
    if (!/^\d{1,2}$/.test(v.children) || Number(v.children) > 30) e.children = "Nombre d’enfants invalide (0 à 30).";
    if (v.email.trim() !== "" && !/^[A-Za-z0-9._'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(v.email.replace(/\s+/g, ""))) e.email = "Adresse e-mail invalide.";
  }
  if (step === 1) {
    if (required(v.mention)) e.mention = "Choisissez la mention.";
    if (required(v.track)) e.track = "Choisissez le parcours.";
    if (!/^\d{4} - \d{4}$/.test(v.academic_year)) e.academic_year = "Choisissez l’année universitaire.";
    if (v.student_id.trim() !== "" && !/^\d{1,9}[A-Za-z]{0,3}$/.test(v.student_id.trim())) e.student_id = "Matricule invalide (chiffres, puis au plus 3 lettres).";
    if (cycleOf(v.level) === "L") {
      if (v.bacc_date !== "" && v.bacc_series === "") e.bacc_series = "Indiquez la série du baccalauréat.";
      if (v.bacc_series !== "" && v.bacc_date === "") e.bacc_date = "Indiquez la date d’obtention.";
    } else {
      if (v.previous_degree.trim() !== "" && v.previous_degree_date === "") e.previous_degree_date = "Indiquez la date d’obtention.";
      if (v.previous_degree_date !== "" && v.previous_degree.trim() === "") e.previous_degree = "Indiquez le diplôme précédent.";
    }
  }
  return e;
}

export function firstError(e: Errors): keyof Values | null {
  const keys = Object.keys(e) as (keyof Values)[];
  return keys.length > 0 ? keys[0] : null;
}

/** La charge utile envoyée au serveur : les champs facultatifs vides sont omis, le diplôme précédent suit le cycle. */
export function toPayload(v: Values, token: string, applicationId: number | null): Record<string, unknown> {
  const out: Record<string, unknown> = {
    token, last_name: v.last_name.trim(), first_name: v.first_name.trim(), sex: Number(v.sex), birth_date: v.birth_date, birth_place: v.birth_place.trim(), nationality: v.nationality.trim(),
    origin_country: v.origin_country.trim(), marital_status: v.marital_status, religion: v.religion, children: Number(v.children), region: Number(v.region), address: v.address.trim(),
    mention: v.mention, track: v.track, level: Number(v.level), academic_year: v.academic_year, status: v.status, is_new: v.is_new === "true", cafeteria: Number(v.cafeteria),
  };
  const optional: [keyof Values, string][] = [
    ["spouse_name", "spouse_name"], ["cin_number", "cin_number"], ["cin_date", "cin_date"], ["visa_number", "visa_number"], ["phone", "phone"], ["size", "size"], ["student_id", "student_id"],
    ["father_name", "father_name"], ["father_job", "father_job"], ["mother_name", "mother_name"], ["mother_job", "mother_job"], ["parent_phone", "parent_phone"], ["parent_address", "parent_address"],
    ["sponsor_last_name", "sponsor_last_name"], ["sponsor_first_name", "sponsor_first_name"], ["sponsor_phone", "sponsor_phone"], ["sponsor_address", "sponsor_address"],
  ];
  for (const [from, to] of optional) if (v[from].trim() !== "") out[to] = v[from].trim();
  if (v.cin_region !== "") out.cin_region = Number(v.cin_region);
  if (v.email.trim() !== "") out.email = v.email.replace(/\s+/g, "").toLowerCase();
  if (cycleOf(v.level) === "L") {
    if (v.bacc_date !== "" && v.bacc_series !== "") {
      out.bacc_date = v.bacc_date;
      out.bacc_series = v.bacc_series;
    }
  } else if (v.previous_degree.trim() !== "" && v.previous_degree_date !== "") {
    out.previous_degree = v.previous_degree.trim();
    out.previous_degree_date = v.previous_degree_date;
  }
  if (applicationId !== null) out.application_id = applicationId;
  return out;
}

/** Un jeton par formulaire affiché : le même formulaire reçu deux fois ne crée qu'un étudiant. 32 caractères hexadécimaux. */
export function newToken(random: (n: number) => Uint8Array = (n) => crypto.getRandomValues(new Uint8Array(n))): string {
  return Array.from(random(16), (b) => b.toString(16).padStart(2, "0")).join("");
}

export function suggestUrl(mention: string, level: string, lastName: string, firstName: string): string {
  const p = new URLSearchParams({ mention, level });
  if (lastName.trim() !== "") p.set("last_name", lastName.trim());
  if (firstName.trim() !== "") p.set("first_name", firstName.trim());
  return `/api/v1/staff/student-creation/suggest?${p.toString()}`;
}

/** L'état du compte Google en toutes lettres. */
export function googleText(g: { status: GoogleState; reason: string | null }): { label: string; tone: "ok" | "warn" | "bad" | "info"; retry: boolean } {
  switch (g.status) {
    case "cree":
      return { label: "Compte Google créé : l’adresse fonctionne dès maintenant.", tone: "ok", retry: false };
    case "existait":
      return { label: g.reason ?? "Ce compte existe déjà dans Google.", tone: "warn", retry: false };
    case "en_attente":
      return { label: "Compte Google en cours de création (dans la minute qui suit).", tone: "warn", retry: false };
    case "echec":
      return { label: `Compte Google non créé${g.reason ? ` : ${g.reason}` : ""}`, tone: "bad", retry: true };
    case "non_configure":
      return { label: g.reason ?? "Compte Google non créé : l’API Google Admin n’est pas configurée.", tone: "info", retry: false };
  }
}

const DRAFT = "registrar:creation-draft";

/** Un brouillon gardé sur l'appareil : une page rechargée ou une coupure ne fait pas perdre la saisie (jamais envoyé au serveur). */
export function saveDraft(v: Values, step: number): void {
  try {
    sessionStorage.setItem(DRAFT, JSON.stringify({ v, step }));
  } catch {
    /* stockage indisponible : le formulaire fonctionne sans */
  }
}

export function loadDraft(): { values: Values; step: number } | null {
  try {
    const raw = sessionStorage.getItem(DRAFT);
    if (!raw) return null;
    const d = JSON.parse(raw) as { v?: Partial<Values>; step?: number };
    if (!d.v || typeof d.v !== "object") return null;
    const base = emptyValues();
    const values = { ...base };
    for (const k of Object.keys(base) as (keyof Values)[]) if (typeof d.v[k] === "string") values[k] = d.v[k] as string;
    return { values, step: typeof d.step === "number" && d.step >= 0 && d.step < STEPS.length ? d.step : 0 };
  } catch {
    return null;
  }
}

export function clearDraft(): void {
  try {
    sessionStorage.removeItem(DRAFT);
  } catch {
    /* sans effet */
  }
}
