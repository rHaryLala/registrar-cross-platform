"use client";

/**
 * Les gestes de l'enseignant sur la feuille de notes : chacun montre son effet tout de suite (note et contrôle recalculés), part
 * en direct si le réseau le permet, sinon attend dans la file hors ligne (offline-sync). La transmission, elle, exige le réseau :
 * c'est le serveur qui contrôle la feuille et la fige.
 */
import type { QueryClient } from "@tanstack/react-query";
import { api, ApiError } from "@/lib/api";
import { applyCell, applyComponents, gradingKeys, sheetPath, slotOf, type CellField, type Check, type Component, type Note, type Sheet } from "@/lib/grading";
import { patchData, pendingCount, sync, teacherWrite } from "@/lib/offline-sync";

const BASE = "/api/v1/teacher/grading/courses";

export type SheetCtx = { course: number; year: string; semester: number | null };

function patchSheet(ctx: SheetCtx, update: (s: Sheet) => Sheet) {
  patchData<Sheet>(gradingKeys.sheet(ctx.course, ctx.year, ctx.semester), sheetPath(ctx.course, ctx.year, ctx.semester), update);
}

/** Les listes de cours gardent l'état de la feuille : on les relira à la prochaine ouverture. */
function staleLists(qc: QueryClient) {
  void qc.invalidateQueries({ queryKey: ["teacher", "grading", "courses"], refetchType: "none" });
}

/** Une cellule : composante (0 à 20, ou vide), statut V / E / NN, ou commentaire. `value` est déjà lu et validé par l'écran. */
export async function saveCell(qc: QueryClient, ctx: SheetCtx, student: string, name: string, field: CellField, value: number | string | null, columnLabel?: string) {
  const res = await teacherWrite<{ note: Note; check: Check; modified_at: string }>({
    label: `Note · ${name} : ${slotOf(field) !== null ? (columnLabel ?? "colonne") : field === "statut_special" ? "Statut" : "Commentaire"} = ${value === null ? "vide" : value}`,
    method: "PUT",
    path: `${BASE}/${ctx.course}/cells/${encodeURIComponent(student)}`,
    body: { year: ctx.year, semester: ctx.semester, field, value },
    key: `cell:${ctx.course}:${ctx.year}:${ctx.semester}:${student}:${field}`,
    optimistic: () => patchSheet(ctx, (s) => applyCell(s, student, field, value, false)),
  });
  if (res.queued) patchSheet(ctx, (s) => applyCell(s, student, field, value, true));
  if (res.data) {
    // Le serveur a répondu : sa note et son contrôle font foi.
    const r = res.data;
    patchSheet(ctx, (s) => ({ ...s, check: r.check, lines: s.lines.map((l) => (l.student_id === student ? { ...l, note: r.note, modified_at: r.modified_at, pending: undefined } : l)) }));
    staleLists(qc);
  }
  return res;
}

export type ColumnDraft = { slot: number | null; label: string; weight: number };
export type ColumnsResult = { ok: true } | { ok: false; message: string };

/**
 * Les colonnes de la feuille (titre et pondération de chacune) : la liste complète, dans l'ordre. Réseau exigé : c'est le serveur qui donne
 * son identité à une nouvelle colonne, et supprimer une colonne supprime ses notes (les saisies en attente partent d'abord).
 */
export async function saveColumns(qc: QueryClient, ctx: SheetCtx, columns: ColumnDraft[]): Promise<ColumnsResult> {
  await sync();
  if (pendingCount() > 0) return { ok: false, message: "Des saisies attendent encore d’être envoyées. Rétablissez la connexion, puis réessayez." };
  try {
    const res = await api<{ data: { components: Component[]; check: Check; notes: Record<string, Note> } }>(`${BASE}/${ctx.course}/components`, {
      method: "PUT",
      body: { year: ctx.year, semester: ctx.semester, components: columns.map((c) => ({ slot: c.slot, label: c.label, weight: c.weight })) },
    });
    const r = res.data;
    patchSheet(ctx, (s) => ({ ...applyComponents(s, r.components), check: r.check }));
    staleLists(qc);
    return { ok: true };
  } catch (e) {
    if (e instanceof ApiError) {
      if (e.isOffline) return { ok: false, message: "Vous êtes hors ligne : modifier les colonnes demande le réseau (la saisie des notes, elle, fonctionne sans réseau)." };
      return { ok: false, message: e.detail ?? e.title };
    }
    throw e;
  }
}

export type SubmitResult = { ok: true } | { ok: false; message: string; check?: Check };

/** Transmet la feuille au registrariat (elle se fige). Réseau exigé : le serveur contrôle, puis fige. */
export async function submitSheet(qc: QueryClient, ctx: SheetCtx): Promise<SubmitResult> {
  await sync(); // les saisies faites hors ligne partent d'abord : le contrôle porte sur ce que le serveur connaît
  if (pendingCount() > 0) return { ok: false, message: "Des saisies attendent encore d’être envoyées. Rétablissez la connexion, puis réessayez." };
  try {
    await api(`${BASE}/${ctx.course}/submit`, { method: "POST", body: { year: ctx.year, semester: ctx.semester } });
  } catch (e) {
    if (e instanceof ApiError) {
      if (e.isOffline) return { ok: false, message: "Vous êtes hors ligne : la transmission demande le réseau." };
      return { ok: false, message: e.detail ?? e.title, check: (e.extra?.check as Check | undefined) ?? undefined };
    }
    throw e;
  }
  await qc.invalidateQueries({ queryKey: gradingKeys.sheet(ctx.course, ctx.year, ctx.semester) });
  staleLists(qc);
  return { ok: true };
}
