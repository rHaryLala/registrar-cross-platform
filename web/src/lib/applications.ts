/**
 * Candidatures de pré-admission : formes des réponses de l'API (/api/v1/staff/applications) et petites fonctions pures.
 *
 * Accepter ou refuser PRÉVIENT le candidat (courriel et SMS). L'écran dit avant de confirmer qui sera prévenu, avec les mêmes règles que le serveur
 * (adresse, numéro, Master) ; le serveur reste seul juge à l'envoi.
 */

export type AppStatus = "nouveau" | "en_cours" | "accepte" | "refuse" | "converti";
export type Decision = "accepte" | "refuse";
export type NoticeDecision = Decision | "identifiants";
export type NoticeState = "en_attente" | "envoyee" | "echec" | "ignoree";

export type Notice = {
  id: number;
  channel: "email" | "sms";
  decision: NoticeDecision;
  state: NoticeState;
  reason: string | null;
  attempts: number;
  next_attempt_at: string | null;
  handled_at: string | null;
};

export type ApplicationRow = {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  mention_code: string | null;
  mention: string | null;
  degree: string | null;
  track: string | null;
  status: AppStatus;
  status_label: string;
  is_master: boolean;
  received_at: string;
  processed_at: string | null;
  processed_by: string | null;
  archived_at: string | null;
  student_id: string | null;
  student_file_id: number | null;
  notified_email_at: string | null;
  notified_sms_at: string | null;
  notifications: Notice[];
};

export type ApplicationFile = ApplicationRow & {
  first_name: string | null;
  last_name: string | null;
  gender: string | null;
  birth_date: string | null;
  birth_year: string | number | null;
  birth_place: string | null;
  marital_status: string | null;
  origin_country: string | null;
  nationality: string | null;
  city: string | null;
  address: string | null;
  father: string | null;
  mother: string | null;
  parent_contact: string | null;
  guardian: string | null;
  guardian_contact: string | null;
  baccalaureate_year: string | number | null;
  message: string | null;
  internal_note: string | null;
};

export type ApplicationList = {
  filter: string;
  total: number;
  archived: number;
  counts: Record<AppStatus, number>;
  rows: ApplicationRow[];
};

export type StatusResult = { id: number; status: AppStatus; changed: boolean; notifications: Notice[] };

export const applicationKeys = {
  list: (filter: string) => ["staff", "applications", "list", filter] as const,
  one: (id: number) => ["staff", "applications", "one", id] as const,
  all: ["staff", "applications"] as const,
};

export const STATUS_TABS: { value: string; label: string }[] = [
  { value: "", label: "Toutes" },
  { value: "nouveau", label: "Nouveau" },
  { value: "en_cours", label: "En cours" },
  { value: "accepte", label: "Accepté" },
  { value: "refuse", label: "Refusé" },
  { value: "converti", label: "Converti" },
  { value: "archive", label: "Archivées" },
];

export const STATUS_LABEL: Record<AppStatus, string> = { nouveau: "Nouveau", en_cours: "En cours", accepte: "Accepté", refuse: "Refusé", converti: "Converti" };
export const STATUS_TONE: Record<AppStatus, "info" | "warn" | "ok" | "bad"> = { nouveau: "info", en_cours: "warn", accepte: "ok", refuse: "bad", converti: "ok" };

export function listUrl(filter: string): string {
  return `/api/v1/staff/applications${filter ? `?status=${encodeURIComponent(filter)}` : ""}`;
}

/** Les statuts qu'on peut poser à la main depuis le statut actuel (jamais « converti », jamais le même). */
export function nextStatuses(current: AppStatus): Exclude<AppStatus, "converti">[] {
  if (current === "converti") return [];
  return (["nouveau", "en_cours", "accepte", "refuse"] as const).filter((s) => s !== current);
}

/** Le texte du bouton d'un statut. */
export const ACTION_LABEL: Record<Exclude<AppStatus, "converti">, string> = { nouveau: "Remettre à « nouveau »", en_cours: "Mettre en cours", accepte: "Accepter", refuse: "Refuser" };

/** Un numéro malgache exploitable : 9 chiffres commençant par 3, avec ou sans indicatif (mêmes formes que le serveur). */
export function usablePhone(raw: string | null): boolean {
  const cleaned = (raw ?? "").replace(/[^0-9+]/g, "");
  if (cleaned === "") return false;
  let digits = cleaned.replace(/^\++/, "");
  if (digits.startsWith("00261")) digits = digits.slice(5);
  else if (digits.startsWith("261")) digits = digits.slice(3);
  else if (digits.startsWith("0")) digits = digits.slice(1);
  return /^3[0-9]{8}$/.test(digits);
}

export function usableEmail(raw: string | null): boolean {
  const v = (raw ?? "").trim();
  return v !== "" && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
}

export type Plan = { channel: "email" | "sms"; goes: boolean; text: string };

/** Qui sera prévenu si on prend cette décision : un courriel et un SMS, chacun avec la raison quand il ne part pas. */
export function notifyPlan(app: Pick<ApplicationRow, "email" | "phone" | "is_master">, decision: Decision): Plan[] {
  let email: Plan;
  if (decision === "accepte" && app.is_master) email = { channel: "email", goes: false, text: "Courriel : partira à la création de l’étudiant, avec son adresse et son mot de passe (Master)" };
  else if ((app.email ?? "").trim() === "") email = { channel: "email", goes: false, text: "Courriel : le candidat n’a pas laissé d’adresse" };
  else if (!usableEmail(app.email)) email = { channel: "email", goes: false, text: "Courriel : adresse invalide" };
  else email = { channel: "email", goes: true, text: `Courriel à ${app.email}` };

  let sms: Plan;
  if ((app.phone ?? "").trim() === "") sms = { channel: "sms", goes: false, text: "SMS : le candidat n’a pas laissé de numéro" };
  else if (!usablePhone(app.phone)) sms = { channel: "sms", goes: false, text: "SMS : numéro inexploitable" };
  else sms = { channel: "sms", goes: true, text: `SMS au ${app.phone}` };

  return [email, sms];
}

/** L'état d'une notification en toutes lettres, avec un ton. */
export function noticeText(n: Pick<Notice, "channel" | "state" | "reason" | "attempts"> & { decision?: NoticeDecision }): { label: string; tone: "ok" | "warn" | "bad" | "info" } {
  const what = n.decision === "identifiants" ? "Courriel d’acceptation avec l’adresse et le mot de passe" : n.channel === "email" ? "Courriel" : "SMS";
  switch (n.state) {
    case "envoyee":
      return { label: `${what} envoyé`, tone: "ok" };
    case "en_attente":
      return { label: n.attempts > 0 ? `${what} : nouvel essai prévu` : `${what} en cours d’envoi`, tone: "warn" };
    case "echec":
      return { label: `${what} non envoyé${n.reason ? ` : ${n.reason}` : ""}`, tone: "bad" };
    case "ignoree":
      return { label: `${what} non envoyé${n.reason ? ` : ${n.reason}` : ""}`, tone: "info" };
  }
}

/** Les notifications qui valent la peine d'être signalées dans la liste : on ne montre que celles de la décision actuelle. */
export function currentNotices(app: Pick<ApplicationRow, "status" | "notifications">): Notice[] {
  if (app.status === "converti") return app.notifications.filter((n) => n.decision === "identifiants");   // Master : le courriel part à la création de l’étudiant
  return app.status === "accepte" || app.status === "refuse" ? app.notifications.filter((n) => n.decision === app.status) : [];
}

export function hasFailure(app: Pick<ApplicationRow, "status" | "notifications">): boolean {
  return currentNotices(app).some((n) => n.state === "echec");
}

/** « 2026-09-10 10:00:00 » → « 10 septembre 2026 » (heure du serveur, sans fuseau). */
export function receivedDate(stamp: string | null): string {
  if (!stamp) return "";
  const d = new Date(stamp.replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? stamp : d.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" });
}

export function applicationUrl(id: number): string {
  return `/candidatures/fiche/?id=${id}`;
}
