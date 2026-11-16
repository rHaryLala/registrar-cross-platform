"use client";

/**
 * Les gestes de l'enseignant sur un registre : chacun montre son effet tout de suite, part en direct si le réseau le permet,
 * sinon attend dans la file hors ligne (offline-sync). Aucun bilan n'est inventé hors ligne : il reste « en attente » jusqu'à
 * la réponse du serveur, seul juge des 15 %.
 */
import type { QueryClient } from "@tanstack/react-query";
import { tmpRef } from "@/lib/offline-queue";
import { dropLocal, localSessionPath, newTmpId, patchData, saveLocal, serverNow, teacherWrite, type Fetched } from "@/lib/offline-sync";
import {
  addSessionRow,
  applyAllPresent,
  applyJustification,
  applyMark,
  applySessionEdit,
  blankSession,
  keys,
  recordLabel,
  removeSessionRow,
  setStale,
  type Justification,
  type RegisterData,
  type SessionData,
  type SessionView,
  type Status,
  type Summary,
} from "@/lib/teacher-presence";

const BASE = "/api/v1/teacher";

/** Dans une adresse : le vrai identifiant d'une séance, ou « @tmp-N » tant qu'elle n'existe que sur cet appareil. */
const ref = (id: number) => (id < 0 ? tmpRef(id) : String(id));

/** Où est gardée la lecture d'une séance (copie pour le hors ligne). */
export const sessionPath = (id: number) => (id < 0 ? localSessionPath(id) : `${BASE}/sessions/${id}`);

type RegCtx = { course: number; year: string; semester: number | null };

function patchSession(id: number, update: (d: SessionData) => SessionData) {
  patchData<SessionData>(keys.session(id), sessionPath(id), update);
}

/** Les bilans du registre sont périmés : on les relira à la prochaine ouverture (sans recharger maintenant). */
function staleRegister(qc: QueryClient) {
  void qc.invalidateQueries({ queryKey: ["teacher", "register"], refetchType: "none" });
  void qc.invalidateQueries({ queryKey: ["teacher", "courses"], refetchType: "none" });
}

const nameOf = (d: SessionData | undefined, id: string) => {
  const l = d?.lines.find((x) => x.student_id === id);
  return l ? [l.last_name, l.first_name].filter(Boolean).join(" ") || id : id;
};

/** Pointe un étudiant (état voulu : present, retard + minutes, absent, ou null pour effacer). */
export async function markStudent(qc: QueryClient, session: number, student: string, status: Status | null, minutes: number | null) {
  const cached = qc.getQueryData<Fetched<SessionData>>(keys.session(session))?.data;
  const mins = status === "retard" ? (minutes ?? 5) : null;
  const res = await teacherWrite<{ summary: Summary; label: string; category: SessionData["lines"][number]["category"]; newly_flagged: boolean }>({
    label: `Pointer · ${nameOf(cached, student)} : ${recordLabel(status, mins)}`,
    method: "PUT",
    path: `${BASE}/sessions/${ref(session)}/marks/${encodeURIComponent(student)}`,
    body: { status, minutes_late: mins },
    key: `mark:${ref(session)}:${student}`,
    optimistic: () => patchSession(session, (d) => setStale(applyMark(d, student, status, mins, null), [student], false)),
  });
  if (res.queued) patchSession(session, (d) => setStale(d, [student], true));
  if (res.data) {
    // Le serveur a répondu : son bilan (15 %, absences comptées) remplace l'affichage provisoire.
    const s = res.data;
    patchSession(session, (d) => ({ ...d, lines: d.lines.map((l) => (l.student_id === student ? { ...l, summary: s.summary, stale: false } : l)) }));
    staleRegister(qc);
  }
  return res;
}

export async function justifyStudent(qc: QueryClient, session: number, student: string, justification: Justification | null) {
  const cached = qc.getQueryData<Fetched<SessionData>>(keys.session(session))?.data;
  const res = await teacherWrite({
    label: `Justificatif · ${nameOf(cached, student)} : ${justification === "medical" ? "certificat médical" : justification === "autre" ? "autre justificatif" : "aucun"}`,
    method: "PUT",
    path: `${BASE}/sessions/${ref(session)}/marks/${encodeURIComponent(student)}/justification`,
    body: { justification },
    key: `just:${ref(session)}:${student}`,
    optimistic: () => patchSession(session, (d) => applyJustification(d, student, justification)),
  });
  return res;
}

/** Tous les étudiants non encore pointés sont présents. */
export async function markAllPresent(qc: QueryClient, session: number) {
  const res = await teacherWrite({
    label: "Tous présents (étudiants non encore pointés)",
    method: "POST",
    path: `${BASE}/sessions/${ref(session)}/mark-all-present`,
    body: {},
    optimistic: () => patchSession(session, (d) => setStale(applyAllPresent(d, null), "all", false)),
  });
  if (res.queued) patchSession(session, (d) => setStale(d, d.lines.filter((l) => l.status === "present").map((l) => l.student_id), true));
  if (res.data) {
    // Les bilans de tous les étudiants ont pu bouger : on relit la séance.
    await qc.invalidateQueries({ queryKey: keys.session(session) });
    staleRegister(qc);
  }
  return res;
}

export type SessionEdit = { planned_time: string | null; teacher_arrival: string | null; warned: boolean; comment: string | null };

export async function editSession(qc: QueryClient, session: number, edit: SessionEdit) {
  const res = await teacherWrite({
    label: "Modifier la séance (heures et message)",
    method: "PATCH",
    path: `${BASE}/sessions/${ref(session)}`,
    body: { ...edit },
    key: `edit:${ref(session)}`,
    optimistic: () => patchSession(session, (d) => setStale(applySessionEdit(d, edit), "all", false)),
  });
  if (res.queued) patchSession(session, (d) => setStale(d, "all", true));
  if (res.data) {
    await qc.invalidateQueries({ queryKey: keys.session(session) });
    staleRegister(qc);
  }
  return res;
}

export async function deleteSession(qc: QueryClient, session: number, reg: RegCtx) {
  const res = await teacherWrite({
    label: "Supprimer la séance",
    method: "DELETE",
    path: `${BASE}/sessions/${ref(session)}`,
    body: {},
    optimistic: () => patchData<RegisterData>(keys.register(reg.course, reg.year, reg.semester), registerSnapshotPath(reg), (r) => removeSessionRow(r, session)),
  });
  if (session < 0) await dropLocal(localSessionPath(session));
  staleRegister(qc);
  return res;
}

/** Base des 15 % : le nombre de séances prévues (vide = non indiqué). */
export async function setPlanned(qc: QueryClient, reg: RegCtx, planned: number | null) {
  const res = await teacherWrite({
    label: planned === null ? "Séances prévues : non indiqué" : `Séances prévues : ${planned}`,
    method: "PUT",
    path: `${BASE}/courses/${reg.course}/planned`,
    body: { year: reg.year, semester: reg.semester, planned },
    key: `planned:${reg.course}:${reg.year}:${reg.semester}`,
    optimistic: () =>
      patchData<RegisterData>(keys.register(reg.course, reg.year, reg.semester), registerSnapshotPath(reg), (r) => ({
        ...r,
        sheet: { ...r.sheet, planned_sessions: planned, allowed_absences: planned === null ? null : Math.floor((planned * 15) / 100 + 1e-9) },
      })),
  });
  if (res.data) await qc.invalidateQueries({ queryKey: keys.register(reg.course, reg.year, reg.semester) });
  staleRegister(qc);
  return res;
}

export const registerSnapshotPath = (reg: RegCtx) => {
  const q = new URLSearchParams({ year: reg.year });
  if (reg.semester !== null) q.set("semester", String(reg.semester));
  return `${BASE}/courses/${reg.course}/register?${q}`;
};

export type NewSession = { date: string; planned_time: string | null; teacher_arrival: string | null; warned: boolean };

/**
 * Ouvre une séance. En ligne : le serveur la crée (ou renvoie celle qui existe déjà : même date et même heure).
 * Hors ligne : elle existe d'abord sur cet appareil (identifiant provisoire), avec la liste d'appel du registre.
 *
 * @returns l'identifiant de la séance à ouvrir (négatif si elle n'existe encore que sur cet appareil)
 */
export async function openSession(qc: QueryClient, reg: RegisterData, input: NewSession): Promise<number> {
  const tmp = newTmpId();
  const res = await teacherWrite<{ session_id: number; existed: boolean }>({
    label: `Ouvrir la séance du ${input.date}${input.planned_time ? ` à ${input.planned_time}` : ""}`,
    method: "POST",
    path: `${BASE}/courses/${reg.course.id}/sessions`,
    body: { year: reg.year, semester: reg.semester, ...input },
    key: `open:${reg.course.id}:${input.date}:${input.planned_time ?? ""}`,
    tmp,
  });
  if (res.data) {
    staleRegister(qc);
    return res.data.session_id;
  }

  // Hors ligne : la séance et sa liste d'appel existent localement, en attendant l'envoi.
  const blank = blankSession(reg, tmp, input);
  await saveLocal(localSessionPath(tmp), blank);
  qc.setQueryData<Fetched<SessionData>>(keys.session(tmp), { data: blank, copyAt: null });
  const ctx = { course: reg.course.id, year: reg.year, semester: reg.semester };
  patchData<RegisterData>(keys.register(ctx.course, ctx.year, ctx.semester), registerSnapshotPath(ctx), (r) => addSessionRow(r, blank.session as SessionView));
  return tmp;
}

/** Heure locale « HH:MM » corrigée de l'horloge du serveur (pré-remplit « Votre arrivée »). */
export function nowHHMM(): string {
  const d = new Date(serverNow());
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}
