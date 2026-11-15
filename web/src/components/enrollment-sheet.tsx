"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Download, FileX2, TriangleAlert } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useRef, useState } from "react";
import { AppShell } from "@/components/app-shell";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { StudentPhotoImage } from "@/components/student-photo";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/download";
import type { AmountRow, EnrollmentSheetData } from "@/lib/enrollments";
import { formatAr, formatLongDate, plural } from "@/lib/format";
import { cn } from "@/lib/utils";

/** La fiche d'une session. L'identifiant vient de l'adresse (?session=12) : le site est statique, sans route dynamique. */
export function EnrollmentSheet() {
  const raw = useSearchParams().get("session");
  const sessionId = raw && /^\d+$/.test(raw) ? Number(raw) : null;

  return (
    <AppShell title="Fiche d’inscription">
      <div className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        <Link href="/inscriptions/" className="inline-flex min-h-11 w-fit items-center gap-2 text-sm font-bold text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" aria-hidden /> Mes inscriptions
        </Link>
        {sessionId === null ? <NotFound /> : <SheetLoader sessionId={sessionId} />}
      </div>
    </AppShell>
  );
}

function SheetLoader({ sessionId }: { sessionId: number }) {
  const scope = useRef<HTMLDivElement>(null);
  const q = useQuery({
    queryKey: ["me", "enrollments", sessionId],
    queryFn: async () => (await api<{ data: EnrollmentSheetData }>(`/api/v1/me/enrollments/${sessionId}`)).data,
    // Une fiche introuvable (404) ne se corrige pas en réessayant.
    retry: (count, error) => !(error instanceof ApiError && error.status === 404) && count < 2,
  });
  useEntrance(scope, "[data-enter]", q.isSuccess);

  if (q.isPending) {
    return (
      <div className="grid gap-5" aria-busy="true" aria-label="Chargement de la fiche">
        <Skeleton className="h-40 w-full rounded-xl" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }
  if (q.isError) {
    if (q.error instanceof ApiError && q.error.status === 404) return <NotFound />;
    const offline = q.error instanceof ApiError && q.error.isOffline;
    return (
      <Card role="alert" className="gap-3 p-4">
        <p className="font-bold">{offline ? "Vous êtes hors ligne" : "La fiche n’a pas pu se charger"}</p>
        <p className="text-muted-foreground">{q.error instanceof ApiError && q.error.detail ? q.error.detail : "Réessayez dans un instant."}</p>
        <div>
          <Button onClick={() => q.refetch()}>Réessayer</Button>
        </div>
      </Card>
    );
  }
  return (
    <div ref={scope} className="grid gap-5">
      <Sheet data={q.data} />
    </div>
  );
}

function NotFound() {
  return (
    <Card role="alert" className="items-center gap-2 p-8 text-center">
      <FileX2 className="size-8 text-muted-foreground" aria-hidden />
      <p className="font-bold">Fiche introuvable</p>
      <p className="text-sm text-muted-foreground">Cette session ne figure pas parmi vos inscriptions, ou les fiches ne sont pas consultables pour le moment.</p>
      <Link href="/inscriptions/" className={cn(buttonVariants(), "mt-2")}>
        Voir mes inscriptions
      </Link>
    </Card>
  );
}

function Sheet({ data }: { data: EnrollmentSheetData }) {
  const { student: s, fees } = data;
  return (
    <>
      <Card data-enter className="gap-4 p-5">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-xs font-bold tracking-wider text-accent-text uppercase">Fiche d’inscription</p>
            <h2 className="text-xl font-extrabold tracking-tight">
              {data.session.name} · {data.session.year}
            </h2>
          </div>
          {s.has_photo && <StudentPhotoImage />}
        </div>
        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
          <Info label="Nom" value={s.name} wide />
          <Info label="Matricule" value={s.student_id} mono />
          <Info label="Mention" value={s.mention} wide />
          <Info label="Parcours" value={s.track} />
          <Info label="Niveau" value={s.level_label} />
          {s.sponsor && <Info label="Boursier(e) par" value={s.sponsor} wide />}
          {!data.is_master && <Info label="Résidence" value={s.residence} />}
          {!data.is_master && <Info label="Cafétéria" value={s.cafeteria ? "Abonné(e)" : "Non abonné(e)"} />}
          {!data.is_master && <Info label="Work education" value={data.work_education} wide />}
        </dl>
        <DownloadPdf sessionId={data.session.id} fallbackName={data.filename} />
      </Card>

      {fees === null && (
        <Card data-enter role="status" className="flex-row items-start gap-3 border-warning p-4">
          <TriangleAlert className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
          <p className="text-sm leading-relaxed">
            <strong>Frais de scolarité non disponibles.</strong> Aucune ligne financière n’existe pour cette session : la fiche ne peut pas servir de justificatif de paiement.
          </p>
        </Card>
      )}

      <Courses data={data} />

      {fees && (
        <>
          <Card data-enter className="gap-0 p-0">
            <h3 className="border-b p-4 font-extrabold">Frais divers</h3>
            <Rows rows={fees.general_rows} />
            <p className="border-t px-4 py-3 text-xs text-muted-foreground">Les frais généraux se paient le jour de l’inscription.</p>
          </Card>

          {fees.tuition && (
            <Card data-enter className="gap-0 p-0">
              <h3 className="border-b p-4 font-extrabold">Frais de scolarité</h3>
              <Rows rows={fees.tuition.rows} />
              <div className="flex items-baseline justify-between gap-3 border-t bg-muted/50 px-4 py-3 font-extrabold">
                <span>Sous-total</span>
                <span className="tabular-nums">{formatAr(fees.tuition.subtotal)}</span>
              </div>
            </Card>
          )}

          {fees.payment && (
            <Card data-enter className="gap-0 p-0">
              <h3 className="border-b p-4 font-extrabold">Mode de paiement</h3>
              <p className="px-4 pt-3 text-sm">
                {fees.payment.mode ? (
                  <>
                    <strong>Tranche {fees.payment.mode}</strong> — {fees.payment.mode_label}
                  </>
                ) : (
                  <span className="text-muted-foreground">Mode de paiement non encore choisi.</span>
                )}
              </p>
              {fees.payment.installments.length > 0 && (
                <ol className="mt-2 divide-y border-t">
                  {fees.payment.installments.map((i, n) => (
                    <li key={n} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-3 text-sm">
                      <span className="font-bold tabular-nums">{i.percent} %</span>
                      <span className="tabular-nums">{formatAr(i.amount)}</span>
                      <span className={cn("w-full text-muted-foreground sm:w-auto", !i.due_label && "italic")}>{i.due_label ? `avant le ${i.due_label}` : "date à confirmer"}</span>
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          )}

          <Card data-enter className="gap-1 bg-primary p-5 text-primary-foreground">
            <p className="text-sm font-bold">Total général ({fees.grand_total_includes_tuition ? "frais divers + frais de scolarité" : "frais divers"})</p>
            <p className="text-3xl font-extrabold tabular-nums">{formatAr(fees.grand_total)}</p>
          </Card>
        </>
      )}

      <p data-enter className="text-xs text-muted-foreground">
        Fiche établie le {formatLongDate(data.issued_on)}. Le PDF comprend en plus l’engagement de l’étudiant et les cases de signature.
      </p>
    </>
  );
}

function Info({ label, value, mono, wide }: { label: string; value: string | null; mono?: boolean; wide?: boolean }) {
  return (
    <div className={cn(wide && "col-span-2")}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={cn("font-bold", mono && "font-mono")}>{value ?? "—"}</dd>
    </div>
  );
}

function Rows({ rows }: { rows: AmountRow[] }) {
  return (
    <ul className="divide-y">
      {rows.map((r, i) => (
        <li key={`${r.label}-${i}`} className="flex items-baseline justify-between gap-3 px-4 py-3 text-sm">
          <span>{r.label}</span>
          <span className="tabular-nums">{formatAr(r.amount)}</span>
        </li>
      ))}
    </ul>
  );
}

function Courses({ data }: { data: EnrollmentSheetData }) {
  const t = data.course_totals;
  if (data.courses.length === 0) {
    return (
      <Card data-enter className="p-5 text-sm text-muted-foreground">
        Aucun cours enregistré pour cette session.
      </Card>
    );
  }
  return (
    <Card data-enter className="gap-0 p-0">
      <h3 className="border-b p-4 font-extrabold">Cours ajoutés</h3>
      <ul className="divide-y">
        {data.courses.map((c, i) => (
          <li key={`${c.code}-${i}`} className="grid gap-1 px-4 py-3 text-sm">
            <div className="flex items-baseline justify-between gap-3">
              <span className="min-w-0">
                <span className="font-mono text-xs font-semibold text-accent-text">{c.code}</span>
                <span className="block font-bold">{c.title}</span>
              </span>
              <span className="shrink-0 font-bold tabular-nums">{formatAr(c.cost)}</span>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 text-xs text-muted-foreground">
              <span>
                {plural(c.credits, "crédit")} · {c.category}
              </span>
              {c.lab && (
                <span className={cn(!c.lab.counted && "text-warning")}>
                  Laboratoire <span className={cn("tabular-nums", !c.lab.counted && "line-through")}>{formatAr(c.lab.cost)}</span>
                  {!c.lab.counted && " — offert"}
                </span>
              )}
            </div>
          </li>
        ))}
      </ul>
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t bg-muted/50 px-4 py-3 text-sm font-extrabold">
        <span>
          {plural(t.count, "cours", "cours")} · {plural(t.credits, "crédit")}
        </span>
        <span className="tabular-nums">
          {formatAr(t.cost)}
          {t.lab > 0 && <span className="font-semibold text-muted-foreground"> + labo {formatAr(t.lab)}</span>}
        </span>
      </div>
      <p className="border-t px-4 py-3 text-xs text-muted-foreground">Laboratoire : seuls les deux premiers cours à laboratoire sont facturés, les suivants sont offerts.</p>
    </Card>
  );
}

function DownloadPdf({ sessionId, fallbackName }: { sessionId: number; fallbackName: string }) {
  const [state, setState] = useState<{ kind: "idle" | "busy" | "done" | "error"; message?: string }>({ kind: "idle" });

  const run = async () => {
    setState({ kind: "busy" });
    try {
      const name = await downloadFile(`/api/v1/me/enrollments/${sessionId}/pdf`, fallbackName);
      setState({ kind: "done", message: `PDF téléchargé : ${name}` });
    } catch (e) {
      setState({ kind: "error", message: e instanceof ApiError && e.detail ? e.detail : "Le PDF n’a pas pu être créé. Réessayez." });
    }
  };

  return (
    <div className="grid gap-2">
      <div>
        <Button size="lg" disabled={state.kind === "busy"} onClick={run} className="w-full md:w-auto">
          <Download aria-hidden /> {state.kind === "busy" ? "Préparation du PDF…" : "Télécharger le PDF"}
        </Button>
      </div>
      <p role={state.kind === "error" ? "alert" : "status"} aria-live="polite" className={cn("text-sm", state.kind === "error" ? "text-destructive" : "text-muted-foreground")}>
        {state.message}
      </p>
    </div>
  );
}
