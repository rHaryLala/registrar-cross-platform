"use client";

import { useQuery } from "@tanstack/react-query";
import { FileLock2, FileText, TriangleAlert } from "lucide-react";
import Link from "next/link";
import { useRef } from "react";
import { AppShell } from "@/components/app-shell";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import type { EnrollmentList, EnrollmentSession } from "@/lib/enrollments";
import { plural } from "@/lib/format";
import { cn } from "@/lib/utils";

const enrolledOn = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", year: "numeric" });

function formatIsoDate(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  return m ? enrolledOn.format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))) : iso;
}

export function StudentEnrollments() {
  const scope = useRef<HTMLDivElement>(null);
  const q = useQuery({
    queryKey: ["me", "enrollments"],
    queryFn: async () => (await api<{ data: EnrollmentList }>("/api/v1/me/enrollments")).data,
  });
  useEntrance(scope, "[data-enter]", q.isSuccess);

  return (
    <AppShell title="Mes inscriptions">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        {q.isPending && <ListSkeleton />}
        {q.isError && <ListError error={q.error} onRetry={() => q.refetch()} />}
        {q.isSuccess && <ListContent data={q.data} />}
      </div>
    </AppShell>
  );
}

function ListContent({ data }: { data: EnrollmentList }) {
  if (!data.visible) {
    return (
      <Card data-enter className="items-center gap-2 p-8 text-center">
        <FileLock2 className="size-8 text-muted-foreground" aria-hidden />
        <p className="font-bold">Les fiches d’inscription ne sont pas consultables pour le moment</p>
        <p className="text-sm text-muted-foreground">Pour obtenir la vôtre, adressez-vous au bureau de la registraire.</p>
      </Card>
    );
  }

  if (data.years.length === 0) {
    return (
      <Card data-enter className="items-center gap-2 p-8 text-center">
        <FileText className="size-8 text-muted-foreground" aria-hidden />
        <p className="font-bold">Aucune fiche d’inscription à afficher pour le moment</p>
        <p className="text-sm text-muted-foreground">
          Votre fiche apparaîtra ici après votre passage au guichet d’inscription. Les fiches des années précédentes (avant {data.sheets_from.L} en Licence, avant {data.sheets_from.M} en Master)
          s’obtiennent au bureau de la registraire.
        </p>
      </Card>
    );
  }

  return (
    <>
      <p data-enter className="text-sm text-muted-foreground">
        Une fiche par session : vos cours, vos frais et le mode de paiement enregistrés à l’inscription. Ouvrez-la pour la lire, ou téléchargez-la en PDF pour la garder ou l’imprimer.
      </p>
      {data.years.map((y) => (
        <section key={y.year} data-enter aria-labelledby={`annee-${y.year}`} className="grid gap-2">
          <h2 id={`annee-${y.year}`} className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
            Année {y.year}
          </h2>
          <Card className="gap-0 divide-y p-0">
            {y.sessions.map((s) => (
              <SessionRow key={s.session_id} session={s} />
            ))}
          </Card>
        </section>
      ))}
    </>
  );
}

function SessionRow({ session: s }: { session: EnrollmentSession }) {
  const details = [s.level_label, s.mention, s.enrolled_on ? `inscrit le ${formatIsoDate(s.enrolled_on)}` : null, plural(s.courses, "cours", "cours")].filter(Boolean);

  return (
    <div className="grid gap-3 p-4 md:grid-cols-[1fr_auto] md:items-center">
      <div className="min-w-0">
        <h3 className="font-extrabold">{s.session_name}</h3>
        <p className="text-sm text-muted-foreground">{details.join(" · ")}</p>
        {!s.has_fees && (
          <p className="mt-2 flex items-start gap-2 text-xs leading-relaxed text-warning">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            Les frais de cette session ne sont pas encore enregistrés : la fiche ne vaut pas justificatif de paiement.
          </p>
        )}
      </div>
      <Link href={`/inscriptions/fiche/?session=${s.session_id}`} className={cn(buttonVariants({ size: "lg" }), "w-full md:w-auto")} aria-label={`Ouvrir la fiche ${s.session_name}`}>
        <FileText aria-hidden /> Ouvrir la fiche
      </Link>
    </div>
  );
}

function ListSkeleton() {
  return (
    <div className="grid gap-5" aria-busy="true" aria-label="Chargement de vos inscriptions">
      <Skeleton className="h-10 w-full rounded-xl" />
      <Skeleton className="h-44 w-full rounded-xl" />
    </div>
  );
}

function ListError({ error, onRetry }: { error: Error; onRetry: () => void }) {
  const offline = error instanceof ApiError && error.isOffline;
  return (
    <Card role="alert" className="gap-3 p-4">
      <p className="font-bold">{offline ? "Vous êtes hors ligne" : "Vos inscriptions n’ont pas pu se charger"}</p>
      <p className="text-muted-foreground">{error instanceof ApiError && error.detail ? error.detail : "Réessayez dans un instant ; si cela continue, contactez le registrariat."}</p>
      <div>
        <Button onClick={onRetry}>Réessayer</Button>
      </div>
    </Card>
  );
}
