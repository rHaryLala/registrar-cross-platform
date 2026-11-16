"use client";

import { ChevronRight, Inbox, UserRoundX } from "lucide-react";
import Link from "next/link";
import { useRef, useState } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { CopyBanner, LoadError } from "@/components/teacher-common";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { useGradingCourses } from "@/hooks/use-teacher-queries";
import { plural } from "@/lib/format";
import { levelLabel, sheetUrl, STATUS_INFO, type GradingCourses as Data } from "@/lib/grading";

/** Remise des notes : mes feuilles de l'année et leur état. */
export function GradingCourses() {
  const scope = useRef<HTMLDivElement>(null);
  const [year, setYear] = useState<string | null>(null);
  const q = useGradingCourses(year);
  useEntrance(scope, "[data-enter]", q.isSuccess);

  return (
    <AppShell title="Remise des notes">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        {q.isPending && (
          <div className="grid gap-4" aria-busy="true" aria-label="Chargement de vos feuilles">
            <Skeleton className="h-12 w-full rounded-xl" />
            <Skeleton className="h-28 w-full rounded-xl" />
            <Skeleton className="h-28 w-full rounded-xl" />
          </div>
        )}
        {q.isError && <LoadError error={q.error} onRetry={() => q.refetch()} what="Vos feuilles" />}
        {q.isSuccess && (
          <>
            <CopyBanner copyAt={q.data.copyAt} />
            <Content data={q.data.data} onYear={setYear} />
          </>
        )}
      </div>
    </AppShell>
  );
}

function Content({ data, onYear }: { data: Data; onYear: (y: string) => void }) {
  if (!data.linked) {
    return (
      <Card role="alert" data-enter className="flex-row items-start gap-3 border-warning p-4">
        <UserRoundX className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
        <div className="grid gap-1 text-sm">
          <p className="font-bold">Votre compte n’est relié à aucune fiche d’enseignant</p>
          <p className="text-muted-foreground">Sans ce lien, l’application ne sait pas quels cours sont les vôtres. Demandez au registrariat de relier votre compte à votre fiche.</p>
        </div>
      </Card>
    );
  }

  return (
    <>
      <div data-enter className="flex flex-wrap items-end justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          {plural(data.courses.length, "feuille", "feuilles")} à remettre pour {data.year}.
        </p>
        <label className="grid gap-1 text-sm font-semibold">
          Année scolaire
          <select value={data.year} onChange={(e) => onYear(e.target.value)} className="h-11 rounded-lg border border-input bg-background px-3 text-base md:h-10 md:text-sm">
            {data.years.map((y) => (
              <option key={y} value={y}>
                {y}
              </option>
            ))}
          </select>
        </label>
      </div>

      <Card data-enter className="p-4 text-sm text-muted-foreground">
        Vos notes restent dans votre feuille tant que vous ne l’avez pas transmise. Une fois transmise, le registrariat la contrôle : c’est sa validation qui reporte les notes dans le bulletin de l’étudiant. Vous pouvez saisir sans réseau : tout est envoyé au retour de la connexion.
      </Card>

      {data.courses.length === 0 && (
        <Card data-enter className="items-center gap-2 p-8 text-center">
          <Inbox className="size-8 text-muted-foreground" aria-hidden />
          <p className="font-bold">Aucun étudiant inscrit à vos cours pour {data.year}</p>
          <p className="text-sm text-muted-foreground">Choisissez une autre année scolaire si vos cours sont rattachés à une autre.</p>
        </Card>
      )}

      <ul className="grid gap-3">
        {data.courses.map((c) => {
          const st = STATUS_INFO[c.status];
          return (
            <li key={`${c.id}-${c.semester}`} data-enter>
              <Link
                href={sheetUrl(c.id, data.year, c.semester)}
                className="flex items-center gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
              >
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-mono text-sm font-semibold text-accent-text">{c.code}</p>
                    <StatusChip tone={st.tone}>{st.label}</StatusChip>
                  </div>
                  <p className="text-lg leading-snug font-extrabold tracking-tight">{c.title}</p>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {levelLabel(c.level)} · {c.credits ?? "?"} cr. · {c.semester_label} · {plural(c.students, "étudiant")}
                  </p>
                  {c.status === "rejetee" && c.reject_reason && <p className="mt-1 text-sm text-destructive">Renvoyée : {c.reject_reason}</p>}
                  {c.modified_at && <p className="mt-0.5 text-xs text-muted-foreground">modifiée le {new Date(c.modified_at.replace(" ", "T")).toLocaleString("fr-FR", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" })}</p>}
                </div>
                <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                <span className="sr-only">{c.status === "brouillon" || c.status === "rejetee" ? "Saisir" : "Consulter"}</span>
              </Link>
            </li>
          );
        })}
      </ul>
    </>
  );
}
