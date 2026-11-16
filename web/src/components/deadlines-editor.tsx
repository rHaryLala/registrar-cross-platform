"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CalendarPlus, ChevronLeft, ChevronRight, Check, Users } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { LoadError } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import {
  changedCount, changesText, deadlinesKeys, deadlinesUrl, draftFrom, errors, hint, optionText, ordinal, payload, position, saveUrl, shifted, shortDate, stateText, dayNumber,
  type DeadlinesPage, type Draft, type SaveResult,
} from "@/lib/deadlines";
import { cn } from "@/lib/utils";

function problem(err: unknown): string | undefined {
  return err instanceof ApiError ? (err.isOffline ? "Pas de réseau : rien n’a été modifié. Réessayez avec une connexion." : err.detail) : undefined;
}

const TONE = { empty: "text-muted-foreground", error: "text-destructive", alert: "text-warning", ok: "text-muted-foreground" } as const;

/** Échéances de paiement : la date limite de chaque tranche, session par session. Elle s'imprime sur la fiche d'inscription et s'affiche au guichet. */
export function DeadlinesEditor() {
  const [session, setSession] = useState<number | null>(null);
  const query = useQuery({
    queryKey: deadlinesKeys.one(session),
    queryFn: async () => (await api<{ data: DeadlinesPage }>(deadlinesUrl(session))).data,
    retry: 1,
  });

  return (
    <AppShell title="Échéances de paiement">
      <div className="mx-auto grid w-full max-w-4xl gap-4 p-4 md:p-6">
        <p className="text-sm text-muted-foreground">
          La date limite de chaque tranche, session par session. Elle s’imprime sur la fiche d’inscription et s’affiche au guichet, à l’étape « Mode de paiement ».
        </p>
        {query.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement des échéances">
            <Skeleton className="h-28 w-full rounded-xl" />
            <Skeleton className="h-40 w-full rounded-xl" />
          </div>
        )}
        {query.isError && <LoadError error={query.error} onRetry={() => void query.refetch()} what="Les échéances" />}
        {query.data && <Editor key={`${query.data.session?.id ?? 0}:${query.dataUpdatedAt}`} page={query.data} onSession={setSession} />}
      </div>
    </AppShell>
  );
}

function Editor({ page, onSession }: { page: DeadlinesPage; onSession: (id: number) => void }) {
  const qc = useQueryClient();
  const session = page.session;
  const types = page.types ?? {};
  const saved = useMemo(() => page.dates ?? {}, [page.dates]);
  const [draft, setDraft] = useState<Draft>(() => draftFrom(saved, types));
  const problems = errors(draft);
  const changes = changedCount(draft, saved);
  const blocked = Object.keys(problems).length > 0;

  // Quitter la page avec des dates non enregistrées : le navigateur demande confirmation.
  useEffect(() => {
    if (changes === 0) return;
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [changes]);

  const save = useMutation({
    mutationFn: async () => (await api<{ data: SaveResult }>(saveUrl(session?.id ?? 0), { method: "PUT", body: { dates: payload(draft) } })).data,
    onSuccess: async (r) => {
      toast.success(r.message);
      await qc.invalidateQueries({ queryKey: deadlinesKeys.root });
    },
    onError: (e) => toast.error("Échéances non enregistrées", { description: problem(e) }),
  });

  if (!session) {
    return (
      <Card className="items-center gap-2 p-8 text-center">
        <p className="font-bold">Aucune session</p>
        <p className="text-sm text-muted-foreground">Créez d’abord les sessions de l’année dans les paramètres.</p>
      </Card>
    );
  }

  const go = (id: number | null | undefined) => {
    if (id == null) return;
    if (changes > 0 && !window.confirm("Des dates ne sont pas enregistrées : les abandonner et changer de session ?")) return;
    onSession(id);
  };
  const state = stateText(page.filled ?? 0, page.expected ?? 0);
  const years = [...new Set(page.sessions.map((s) => s.year))].reverse();
  const copy = page.copy;
  const filledNow = Object.values(draft).some((slots) => Object.values(slots).some((v) => v !== ""));

  return (
    <>
      <Card className="gap-3 p-4">
        <div className="flex items-center gap-2">
          <Button variant="outline" size="icon" aria-label="Session précédente" disabled={page.previous == null} onClick={() => go(page.previous)}>
            <ChevronLeft aria-hidden />
          </Button>
          <label className="min-w-0 flex-1">
            <span className="sr-only">Session</span>
            <select
              value={session.id}
              onChange={(e) => go(Number(e.target.value))}
              className="h-12 w-full rounded-xl border border-input bg-background px-3 text-base font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {years.map((y) => (
                <optgroup key={y} label={y !== "" ? y : "Année non précisée"}>
                  {page.sessions
                    .filter((s) => s.year === y)
                    .map((s) => (
                      <option key={s.id} value={s.id}>
                        {optionText(s, s.id === session.id)}
                      </option>
                    ))}
                </optgroup>
              ))}
            </select>
          </label>
          <Button variant="outline" size="icon" aria-label="Session suivante" disabled={page.next == null} onClick={() => go(page.next)}>
            <ChevronRight aria-hidden />
          </Button>
        </div>
        <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
          {session.start && session.end ? (
            <span>
              {shortDate(dayNumber(session.start)!)} → {shortDate(dayNumber(session.end)!)}
            </span>
          ) : (
            <span>Période de la session non renseignée</span>
          )}
          <span className="inline-flex items-center gap-1.5">
            <Users className="size-4" aria-hidden />
            {(page.enrolled ?? 0) === 0
              ? "Aucune inscription pour l’instant"
              : `${page.enrolled} inscription${(page.enrolled ?? 0) > 1 ? "s" : ""}${(page.without_type ?? 0) > 0 ? `, dont ${page.without_type} sans type de paiement choisi` : ""}`}
          </span>
          <span className={cn("font-bold", state.tone === "ok" ? "text-success" : state.tone === "partial" ? "text-warning" : "text-destructive")}>{state.text}</span>
        </p>
        {copy && (
          <div>
            <Button
              variant="outline"
              onClick={() => {
                if (filledNow && !window.confirm(`Les dates déjà saisies pour cette session seront remplacées par celles de ${copy.label}, 52 semaines plus tard.`)) return;
                setDraft(shifted(copy.dates, types));
              }}
            >
              <CalendarPlus aria-hidden /> Reprendre les dates de {copy.label}, 52 semaines plus tard
            </Button>
          </div>
        )}
      </Card>

      {!page.table_exists && (
        <p role="alert" className="rounded-xl border border-destructive p-3 text-sm font-semibold text-destructive">
          La table des échéances n’existe pas encore sur ce serveur : rien ne peut être enregistré.
        </p>
      )}

      <form
        id="deadlines-form"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          if (!blocked && changes > 0) save.mutate();
        }}
        className={cn("grid gap-3", changes > 0 && "pb-28")}
      >
        {Object.entries(types).map(([code, type]) => (
          <Card key={code} className={cn("gap-3 p-4", !type.proposed && "border-dashed")}>
            <div className="flex items-center gap-3">
              <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent text-lg font-black text-accent-text">
                {code}
              </span>
              <div className="min-w-0 flex-1">
                <h2 className="font-extrabold">Type {code}</h2>
                <p className="text-sm text-muted-foreground">
                  {type.label}
                  {type.proposed ? "" : " · plus proposé au guichet"}
                </p>
              </div>
              <span className={cn("text-sm font-semibold", (page.counts?.[code] ?? 0) === 0 && "text-muted-foreground")}>
                {(page.counts?.[code] ?? 0) === 0 ? "Aucun étudiant" : `${page.counts?.[code]} étudiant${(page.counts?.[code] ?? 0) > 1 ? "s" : ""}`}
              </span>
            </div>

            <Rail values={type.parts.map((_, i) => draft[code]?.[i + 1] ?? "")} parts={type.parts} start={session.start} end={session.end} />

            <div className="grid gap-3 sm:grid-cols-[repeat(auto-fit,minmax(11rem,1fr))]">
              {type.parts.map((part, i) => {
                const rank = i + 1;
                const value = draft[code]?.[rank] ?? "";
                const error = problems[`${code}:${rank}`];
                const h = hint(value, session.start, session.end);
                const changed = value !== (saved[code]?.[rank] ?? "");
                return (
                  <label key={rank} className={cn("grid gap-1 rounded-lg p-2", changed && "bg-accent/60")}>
                    <span className="text-sm font-bold">
                      {type.parts.length === 1 ? "En une fois" : `${ordinal(rank)} tranche`} <em className="font-normal text-muted-foreground not-italic">{part}&nbsp;%</em>
                    </span>
                    <input
                      type="date"
                      value={value}
                      onChange={(e) => setDraft((d) => ({ ...d, [code]: { ...d[code], [rank]: e.target.value } }))}
                      aria-invalid={error !== undefined}
                      aria-describedby={`hint-${code}-${rank}`}
                      className="h-12 rounded-lg border border-input bg-background px-3 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring aria-invalid:border-destructive"
                    />
                    <small id={`hint-${code}-${rank}`} role={error ? "alert" : undefined} className={cn("min-h-4 text-xs", error ? "font-semibold text-destructive" : TONE[h.tone])}>
                      {error ?? h.text}
                    </small>
                  </label>
                );
              })}
            </div>
          </Card>
        ))}
      </form>

      {changes > 0 && (
        <div role="region" aria-label="Enregistrer les échéances" className="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-20 border-t bg-background/95 p-3 shadow-lg backdrop-blur md:bottom-0">
          <div className="mx-auto flex max-w-4xl flex-wrap items-center gap-2">
            <p aria-live="polite" className="min-w-0 flex-1 text-sm">
              <b>{changesText(changes)}</b>
              {blocked ? <span className="font-semibold text-destructive"> · {Object.keys(problems).length} à corriger</span> : " · pas encore enregistrée" + (changes > 1 ? "s" : "")}
            </p>
            <Button variant="outline" onClick={() => setDraft(draftFrom(saved, types))} disabled={save.isPending}>
              Annuler les changements
            </Button>
            <Button type="submit" form="deadlines-form" disabled={blocked || save.isPending || !page.table_exists}>
              <Check aria-hidden /> {save.isPending ? "Enregistrement…" : "Enregistrer les échéances"}
            </Button>
          </div>
        </div>
      )}
    </>
  );
}

/** La frise d'un type : la période de la session et, dessus, un repère par tranche datée (décoratif : les champs portent l'information). */
function Rail({ values, parts, start, end }: { values: string[]; parts: number[]; start: string | null; end: string | null }) {
  if (dayNumber(start) === null || dayNumber(end) === null) return null;
  return (
    <div aria-hidden className="relative mx-2 h-6">
      <div className="absolute inset-x-0 top-1/2 h-1 -translate-y-1/2 rounded-full bg-border" />
      {values.map((v, i) => {
        const p = position(v, start, end);
        const out = dayNumber(v) !== null && (dayNumber(v)! < dayNumber(start)! || dayNumber(v)! > dayNumber(end)!);
        return p === null ? null : (
          <span
            key={i}
            style={{ left: `${p}%` }}
            className={cn("absolute top-1/2 grid size-6 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full text-[10px] font-black text-primary-foreground", out ? "bg-warning" : "bg-primary")}
          >
            {parts.length === 1 ? "" : i + 1}
          </span>
        );
      })}
    </div>
  );
}
