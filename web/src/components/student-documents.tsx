"use client";

import { useQuery } from "@tanstack/react-query";
import { Download, FileText } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useMe } from "@/lib/auth";
import {
  canPrintEnrollment, coursesText, documentKeys, enrollmentSheetPdfUrl, enrollmentSheetsUrl, feesText, groupByYear, parseScope, scopeChoices, sheetFallbackName, transcriptFallbackName,
  transcriptKeys, transcriptOptionsUrl, transcriptPdfUrl, type EnrollmentSession, type EnrollmentSheets, type TranscriptOptions,
} from "@/lib/documents";
import { downloadFile } from "@/lib/download";
import { fileDate } from "@/lib/directory";
import { cn } from "@/lib/utils";

/**
 * « Documents » de la fiche d'un étudiant : une fiche d'inscription par session, en PDF fabriqué par le serveur.
 * Réservé aux niveaux 1 à 4 (la fiche porte des montants) : pour les autres comptes, rien n'est affiché, ni appelé.
 */
export function StudentDocuments({ studentRowId }: { studentRowId: number }) {
  const me = useMe();
  const allowed = canPrintEnrollment(me.data?.level);
  const q = useQuery({
    queryKey: documentKeys.enrollmentSheets(studentRowId),
    queryFn: async () => (await api<{ data: EnrollmentSheets }>(enrollmentSheetsUrl(studentRowId))).data,
    enabled: allowed,
  });
  if (!allowed) return null;

  return (
    <Card className="gap-3 p-4" data-slot="documents">
      <div>
        <h3 className="text-lg font-extrabold tracking-tight">Documents</h3>
        <p className="text-sm text-muted-foreground">Documents officiels en PDF, fabriqués par le serveur.</p>
      </div>
      <TranscriptBlock studentRowId={studentRowId} />
      <h4 className="-mb-1 text-sm font-bold tracking-wide text-muted-foreground uppercase">Fiches d’inscription</h4>
      <p className="-mt-2 text-sm text-muted-foreground">Une par session. Même document que celui de l’espace de l’étudiant.</p>
      {q.isPending ? (
        <Skeleton className="h-14 w-full" />
      ) : q.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {q.error instanceof ApiError && q.error.detail ? q.error.detail : "Les inscriptions n’ont pas pu se charger."}{" "}
          <button type="button" onClick={() => q.refetch()} className="font-bold underline">
            Réessayer
          </button>
        </p>
      ) : q.data.sessions.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucune inscription à une session : il n’y a pas de fiche à imprimer.</p>
      ) : (
        <div className="grid gap-4">
          {groupByYear(q.data.sessions).map((g) => (
            <section key={g.year} aria-label={`Année ${g.year}`} className="grid gap-2">
              <h4 className="text-sm font-bold tracking-wide text-muted-foreground uppercase">{g.year}</h4>
              <ul className="grid gap-2">
                {g.sessions.map((s) => (
                  <SessionRow key={s.session_id} studentRowId={studentRowId} studentId={q.data.student_id} s={s} />
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </Card>
  );
}

function SessionRow({ studentRowId, studentId, s }: { studentRowId: number; studentId: string; s: EnrollmentSession }) {
  const [state, setState] = useState<{ kind: "idle" | "busy" | "done" | "error"; message?: string }>({ kind: "idle" });

  const run = async () => {
    setState({ kind: "busy" });
    try {
      const name = await downloadFile(enrollmentSheetPdfUrl(studentRowId, s.session_id), sheetFallbackName(studentId, s));
      setState({ kind: "done", message: `PDF téléchargé : ${name}` });
    } catch (e) {
      setState({ kind: "error", message: e instanceof ApiError && e.detail ? e.detail : "Le PDF n’a pas pu être créé. Réessayez." });
    }
  };

  const details = [s.level_label, s.mention, s.enrolled_on ? `inscrit le ${fileDate(s.enrolled_on)}` : null, coursesText(s.courses), feesText(s.has_fees)].filter(Boolean).join(" · ");

  return (
    <li className="grid gap-1 rounded-lg border p-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="flex items-center gap-2 font-bold">
            <FileText className="size-4 shrink-0 text-accent-text" aria-hidden /> {s.session_name}
          </p>
          <p className={cn("text-sm text-muted-foreground", !s.has_fees && "text-warning")}>{details}</p>
        </div>
        <Button variant="outline" disabled={state.kind === "busy"} onClick={run} aria-label={`Télécharger la fiche d’inscription, ${s.session_name} ${s.year}`}>
          <Download aria-hidden /> {state.kind === "busy" ? "Préparation…" : "PDF"}
        </Button>
      </div>
      <p role={state.kind === "error" ? "alert" : "status"} aria-live="polite" className={cn("text-sm", state.kind === "error" ? "text-destructive" : "text-muted-foreground")}>
        {state.message}
      </p>
    </li>
  );
}

/** Le relevé semestriel des notes : tout l'historique, une année ou une session. Réservé aux mêmes comptes que les fiches. */
function TranscriptBlock({ studentRowId }: { studentRowId: number }) {
  const q = useQuery({
    queryKey: transcriptKeys.options(studentRowId),
    queryFn: async () => (await api<{ data: TranscriptOptions }>(transcriptOptionsUrl(studentRowId))).data,
  });
  const [choice, setChoice] = useState("all");
  const [state, setState] = useState<{ kind: "idle" | "busy" | "done" | "error"; message?: string }>({ kind: "idle" });

  const run = async () => {
    if (!q.data) return;
    const scope = parseScope(choice);
    setState({ kind: "busy" });
    try {
      const name = await downloadFile(transcriptPdfUrl(studentRowId, scope), transcriptFallbackName(q.data.student_id, scope));
      setState({ kind: "done", message: `PDF téléchargé : ${name}` });
    } catch (e) {
      setState({ kind: "error", message: e instanceof ApiError && e.detail ? e.detail : "Le PDF n’a pas pu être créé. Réessayez." });
    }
  };

  return (
    <section aria-label="Relevé semestriel des notes" className="grid gap-2 rounded-lg border p-3" data-slot="transcript">
      <div>
        <p className="flex items-center gap-2 font-bold">
          <FileText className="size-4 shrink-0 text-accent-text" aria-hidden /> Relevé semestriel des notes
        </p>
        <p className="text-sm text-muted-foreground">Toutes les sessions de l’étudiant, publiées ou non : un document pour le personnel.</p>
      </div>
      {q.isPending ? (
        <Skeleton className="h-12 w-full" />
      ) : q.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {q.error instanceof ApiError && q.error.detail ? q.error.detail : "Les sessions n’ont pas pu se charger."}{" "}
          <button type="button" onClick={() => q.refetch()} className="font-bold underline">
            Réessayer
          </button>
        </p>
      ) : q.data.sessions.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucune note enregistrée : il n’y a pas de relevé à imprimer.</p>
      ) : (
        <div className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end">
          <label className="grid gap-1">
            <span className="text-xs font-bold tracking-wide text-muted-foreground uppercase">Période</span>
            <select
              value={choice}
              onChange={(e) => {
                setChoice(e.target.value);
                setState({ kind: "idle" });
              }}
              className="h-12 w-full rounded-xl border border-input bg-background px-3 text-base font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {groupedChoices(q.data).map(({ group, items }) =>
                group === "" ? (
                  items.map((c) => (
                    <option key={c.value} value={c.value}>
                      {c.label}
                    </option>
                  ))
                ) : (
                  <optgroup key={group} label={group}>
                    {items.map((c) => (
                      <option key={c.value} value={c.value}>
                        {c.label}
                      </option>
                    ))}
                  </optgroup>
                ),
              )}
            </select>
          </label>
          <Button variant="outline" disabled={state.kind === "busy"} onClick={run} aria-label="Télécharger le relevé semestriel des notes">
            <Download aria-hidden /> {state.kind === "busy" ? "Préparation…" : "PDF"}
          </Button>
        </div>
      )}
      <p role={state.kind === "error" ? "alert" : "status"} aria-live="polite" className={cn("text-sm", state.kind === "error" ? "text-destructive" : "text-muted-foreground")}>
        {state.message}
      </p>
    </section>
  );
}

function groupedChoices(data: TranscriptOptions): { group: string; items: ReturnType<typeof scopeChoices> }[] {
  const out: { group: string; items: ReturnType<typeof scopeChoices> }[] = [];
  for (const c of scopeChoices(data.sessions)) {
    const last = out[out.length - 1];
    if (last && last.group === c.group) last.items.push(c);
    else out.push({ group: c.group, items: [c] });
  }
  return out;
}
