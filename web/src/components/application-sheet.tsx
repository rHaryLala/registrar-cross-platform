"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Archive, ArchiveRestore, ArrowLeft, Check, RotateCcw } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { LoadError } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import {
  ACTION_LABEL, applicationKeys, currentNotices, hasFailure, nextStatuses, noticeText, notifyPlan, receivedDate, STATUS_TONE, type ApplicationFile, type Decision, type StatusResult,
} from "@/lib/applications";
import { ficheUrl, fileDate, parseFileId } from "@/lib/directory";
import { cn } from "@/lib/utils";

const BASE = "/api/v1/staff/applications";

/** Une candidature : sa fiche complète, son instruction (statut), son archivage et l'état des messages envoyés au candidat. */
export function ApplicationSheet() {
  const id = parseFileId(useSearchParams().get("id"));
  const q = useQuery({
    queryKey: applicationKeys.one(id ?? 0),
    queryFn: async () => (await api<{ data: ApplicationFile }>(`${BASE}/${id}`)).data,
    enabled: id !== null,
    retry: (n, e) => !(e instanceof ApiError && e.status >= 400 && e.status < 500) && n < 1,
    // Les messages partent dans la minute : on suit leur état tant qu'ils attendent.
    refetchInterval: (query) => (query.state.data && currentNotices(query.state.data).some((n) => n.state === "en_attente") ? 10_000 : false),
  });

  return (
    <AppShell title="Candidature">
      <div className="mx-auto grid w-full max-w-3xl gap-4 p-4 md:p-6">
        <Link href="/candidatures/" className="inline-flex min-h-11 w-fit items-center gap-1.5 text-sm font-bold text-accent-text hover:underline">
          <ArrowLeft className="size-4" aria-hidden /> Les candidatures
        </Link>
        {id === null && <LoadError error={new ApiError(404, "applications.not_found", "Candidature introuvable", "Adresse incomplète : ouvrez la candidature depuis la liste.")} onRetry={() => undefined} what="La candidature" />}
        {id !== null && q.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement de la candidature">
            <Skeleton className="h-28 w-full rounded-xl" />
            <Skeleton className="h-40 w-full rounded-xl" />
          </div>
        )}
        {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} what="La candidature" />}
        {q.data && <Body f={q.data} />}
      </div>
    </AppShell>
  );
}

function Body({ f }: { f: ApplicationFile }) {
  const qc = useQueryClient();
  const [pending, setPending] = useState<Decision | null>(null);
  const confirmRef = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (pending) confirmRef.current?.focus();
  }, [pending]);

  const refresh = () => qc.invalidateQueries({ queryKey: applicationKeys.all });
  const fail = (title: string) => (e: unknown) => toast.error(title, { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." });

  const status = useMutation({
    mutationFn: async (next: string) => (await api<{ data: StatusResult }>(`${BASE}/${f.id}/status`, { method: "POST", body: { status: next } })).data,
    onSuccess: async (r) => {
      setPending(null);
      if (!r.changed) toast("Rien n’a changé", { description: "La candidature avait déjà ce statut : le candidat n’est pas prévenu une seconde fois." });
      else if (r.notifications.some((n) => n.state === "en_attente")) toast.success("Décision enregistrée", { description: "Le candidat sera prévenu dans la minute qui suit." });
      else toast.success("Statut mis à jour");
      await refresh();
    },
    onError: fail("Changement refusé"),
  });
  const archive = useMutation({
    mutationFn: async (on: boolean) => (await api<{ data: { archived: boolean } }>(`${BASE}/${f.id}/archive`, { method: "POST", body: { archive: on } })).data,
    onSuccess: async (r) => {
      toast.success(r.archived ? "Candidature archivée" : "Candidature sortie des archives");
      await refresh();
    },
    onError: fail("Archivage impossible"),
  });
  const retry = useMutation({
    mutationFn: async () => (await api<{ data: { requeued: number } }>(`${BASE}/${f.id}/notifications/retry`, { method: "POST" })).data,
    onSuccess: async (r) => {
      toast.success(r.requeued > 0 ? "Nouvel envoi demandé" : "Rien à renvoyer");
      await refresh();
    },
    onError: fail("Relance impossible"),
  });

  const notices = currentNotices(f);
  const actions = nextStatuses(f.status);
  const busy = status.isPending || archive.isPending || retry.isPending;

  return (
    <>
      <Card className="gap-1 p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm text-muted-foreground">Reçue le {receivedDate(f.received_at)}</p>
          <span className="flex flex-wrap gap-1.5">
            {f.archived_at && <StatusChip tone="info">Archivée</StatusChip>}
            <StatusChip tone={STATUS_TONE[f.status]}>{f.status_label}</StatusChip>
          </span>
        </div>
        <h2 className="text-2xl leading-tight font-extrabold tracking-tight">{f.name}</h2>
        <p className="text-muted-foreground">{[f.mention, f.degree ? `${f.degree.charAt(0).toUpperCase()}${f.degree.slice(1)}` : null, f.track].filter(Boolean).join(" · ")}</p>
        {f.processed_by && (
          <p className="text-xs text-muted-foreground">
            Dernière décision par {f.processed_by}
            {f.processed_at ? `, le ${receivedDate(f.processed_at)}` : ""}
          </p>
        )}
        {f.student_id && (
          <p className="text-sm">
            Converti en étudiant :{" "}
            {f.student_file_id ? (
              <Link href={ficheUrl("students", f.student_file_id)} className="font-mono font-semibold text-accent-text hover:underline">
                {f.student_id}
              </Link>
            ) : (
              <span className="font-mono font-semibold">{f.student_id}</span>
            )}
          </p>
        )}
      </Card>

      <Card className="gap-3 p-4">
        <h3 className="text-lg font-extrabold tracking-tight">Instruction</h3>
        {f.status === "accepte" && (
          <div className="rounded-xl border border-success/40 bg-success/10 p-3">
            <p className="mb-2 text-sm font-semibold">Candidature acceptée : l’étape suivante est l’inscription.</p>
            <Button render={<Link href={`/etudiants/nouveau/?candidature=${f.id}`} />}>Créer l’étudiant</Button>
          </div>
        )}
        {f.status === "converti" ? (
          <p className="text-sm text-muted-foreground">Cette candidature est convertie en étudiant : son statut ne change plus.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {actions.map((s) => {
              const decision = s === "accepte" || s === "refuse";
              return (
                <Button key={s} variant={s === "accepte" ? "default" : "outline"} disabled={busy} onClick={() => (decision ? setPending(s) : status.mutate(s))}>
                  {ACTION_LABEL[s]}
                </Button>
              );
            })}
          </div>
        )}
        {pending && (
          <div role="group" aria-label={`Confirmer : ${ACTION_LABEL[pending]}`} className="grid gap-3 rounded-xl border border-warning/50 bg-warning/10 p-4">
            <p className="font-bold">{pending === "accepte" ? "Accepter" : "Refuser"} cette candidature ?</p>
            <p className="text-sm">Le candidat en sera informé :</p>
            <ul className="grid gap-1 text-sm">
              {notifyPlan(f, pending).map((p) => (
                <li key={p.channel} className={p.goes ? "font-semibold" : "text-muted-foreground"}>
                  {p.goes ? "✓ " : "✗ "}
                  {p.text}
                </li>
              ))}
            </ul>
            <div className="flex flex-wrap gap-2">
              <Button ref={confirmRef} disabled={status.isPending} onClick={() => status.mutate(pending)}>
                <Check aria-hidden /> {status.isPending ? "Enregistrement…" : `Confirmer : ${pending === "accepte" ? "accepter" : "refuser"}`}
              </Button>
              <Button variant="outline" disabled={status.isPending} onClick={() => setPending(null)}>
                Annuler
              </Button>
            </div>
          </div>
        )}
        <div>
          <Button variant="ghost" disabled={busy} onClick={() => archive.mutate(!f.archived_at)}>
            {f.archived_at ? <ArchiveRestore aria-hidden /> : <Archive aria-hidden />}
            {f.archived_at ? "Sortir des archives" : "Archiver"}
          </Button>
          <p className="text-xs text-muted-foreground">Ranger n’est pas décider : l’archivage ne change pas le statut.</p>
        </div>
      </Card>

      {notices.length > 0 && (
        <Card className="gap-2 p-4">
          <h3 className="text-lg font-extrabold tracking-tight">Messages au candidat</h3>
          <ul className="grid gap-1.5">
            {notices.map((n) => {
              const t = noticeText(n);
              return (
                <li key={n.id} className={cn("text-sm", t.tone === "ok" ? "text-success" : t.tone === "bad" ? "font-semibold text-destructive" : t.tone === "warn" ? "text-warning" : "text-muted-foreground")}>
                  {t.label}
                </li>
              );
            })}
          </ul>
          {hasFailure(f) && (
            <div>
              <Button variant="outline" disabled={busy} onClick={() => retry.mutate()}>
                <RotateCcw aria-hidden /> Réessayer l’envoi
              </Button>
            </div>
          )}
        </Card>
      )}

      <Section title="Contact" rows={[["Téléphone", f.phone], ["E-mail", f.email], ["Ville", f.city], ["Adresse", f.address]]} />
      <Section
        title="Identité"
        rows={[
          ["Nom", f.last_name], ["Prénom", f.first_name], ["Genre", f.gender], ["Né(e) le", fileDate(f.birth_date) ?? (f.birth_year ? String(f.birth_year) : null)], ["Lieu de naissance", f.birth_place],
          ["Situation familiale", f.marital_status], ["Pays d’origine", f.origin_country], ["Nationalité", f.nationality],
        ]}
      />
      <Section title="Famille et tuteur" rows={[["Père", f.father], ["Mère", f.mother], ["Contact des parents", f.parent_contact], ["Tuteur", f.guardian], ["Contact du tuteur", f.guardian_contact]]} />
      <Section title="Études visées" rows={[["Mention", f.mention], ["Diplôme visé", f.degree], ["Parcours", f.track], ["Année du baccalauréat", f.baccalaureate_year ? String(f.baccalaureate_year) : null]]} />
      {f.message && (
        <Card className="gap-2 p-4">
          <h3 className="text-lg font-extrabold tracking-tight">Message du candidat</h3>
          <p className="break-words whitespace-pre-wrap">{f.message}</p>
        </Card>
      )}
      {f.internal_note && (
        <Card className="gap-2 p-4">
          <h3 className="text-lg font-extrabold tracking-tight">Note interne</h3>
          <p className="break-words whitespace-pre-wrap">{f.internal_note}</p>
        </Card>
      )}
    </>
  );
}

function Section({ title, rows }: { title: string; rows: [string, ReactNode][] }) {
  const shown = rows.filter(([, v]) => v !== null && v !== undefined && v !== "");
  if (shown.length === 0) return null;
  return (
    <Card className="gap-3 p-4">
      <h3 className="text-lg font-extrabold tracking-tight">{title}</h3>
      <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
        {shown.map(([label, value]) => (
          <div key={label} className="min-w-0">
            <dt className="text-xs font-bold tracking-wide text-muted-foreground uppercase">{label}</dt>
            <dd className="break-words">{value}</dd>
          </div>
        ))}
      </dl>
    </Card>
  );
}
