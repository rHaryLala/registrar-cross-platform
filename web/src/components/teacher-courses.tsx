"use client";

import { ChevronDown, ChevronRight, Inbox, UserRoundX } from "lucide-react";
import Link from "next/link";
import { useRef, useState } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { CopyBanner, LoadError } from "@/components/teacher-common";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { useCourses } from "@/hooks/use-teacher-queries";
import { plural } from "@/lib/format";
import { registerUrl, type CoursesData } from "@/lib/teacher-presence";

/** Présences : mes cours de l'année, et l'état de leur registre. */
export function TeacherCourses() {
  const scope = useRef<HTMLDivElement>(null);
  const [year, setYear] = useState<string | null>(null);
  const q = useCourses(year);
  useEntrance(scope, "[data-enter]", q.isSuccess);

  return (
    <AppShell title="Présences">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        {q.isPending && (
          <div className="grid gap-4" aria-busy="true" aria-label="Chargement de vos cours">
            <Skeleton className="h-12 w-full rounded-xl" />
            <Skeleton className="h-28 w-full rounded-xl" />
            <Skeleton className="h-28 w-full rounded-xl" />
          </div>
        )}
        {q.isError && <LoadError error={q.error} onRetry={() => q.refetch()} what="Vos cours" />}
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

function Content({ data, onYear }: { data: CoursesData; onYear: (y: string) => void }) {
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
          {plural(data.courses.length, "cours", "cours")} en {data.year} — un registre par cours et par semestre.
        </p>
        <label className="grid gap-1 text-sm font-semibold">
          Année scolaire
          <select
            value={data.year}
            onChange={(e) => onYear(e.target.value)}
            className="h-11 rounded-lg border border-input bg-background px-3 text-base md:h-10 md:text-sm"
          >
            {data.years.map((y) => (
              <option key={y} value={y}>
                {y}
              </option>
            ))}
          </select>
        </label>
      </div>

      <Card data-enter className="p-0">
        <details className="group">
          <summary className="flex min-h-12 cursor-pointer items-center gap-2 px-4 py-2 font-bold">
            Les règles d’assiduité (Bulletin académique, §472 à §476)
            <ChevronDown className="ml-auto size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden />
          </summary>
          <ul className="grid gap-2 px-4 pb-4 text-sm text-muted-foreground [&>li]:ml-4 [&>li]:list-disc">
            <li>Faites l’appel à chaque séance (§472). Deux retards de 5 minutes comptent pour une absence ; un retard de 10 minutes est une absence.</li>
            <li>
              Au-delà de <strong className="text-foreground">15 % des séances prévues</strong>, l’étudiant doit quitter le cours : le registrariat en est informé automatiquement (§476).
            </li>
            <li>Si vous arrivez plus de 10 minutes en retard sans avoir prévenu, les étudiants peuvent partir sans pénalité : les absences de cette séance ne comptent pas (§474).</li>
          </ul>
        </details>
      </Card>

      {data.courses.length === 0 && (
        <Card data-enter className="items-center gap-2 p-8 text-center">
          <Inbox className="size-8 text-muted-foreground" aria-hidden />
          <p className="font-bold">Aucun étudiant inscrit à vos cours pour {data.year}</p>
          <p className="text-sm text-muted-foreground">Choisissez une autre année scolaire si vos cours sont rattachés à une autre.</p>
        </Card>
      )}

      <ul className="grid gap-3">
        {data.courses.map((c) => (
          <li key={`${c.id}-${c.semester}`} data-enter>
            <Link
              href={registerUrl(c.id, data.year, c.semester)}
              className="flex items-center gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
            >
              <div className="min-w-0 flex-1">
                <p className="font-mono text-sm font-semibold text-accent-text">{c.code}</p>
                <p className="text-lg leading-snug font-extrabold tracking-tight">{c.title}</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  {c.semester_label} · {plural(c.students, "étudiant")} · {c.held_sessions} / {c.planned_sessions ?? "?"} séances
                </p>
                {c.flagged > 0 && (
                  <div className="mt-2">
                    <StatusChip tone="bad">{plural(c.flagged, "étudiant au-delà de 15 %", "étudiants au-delà de 15 %")}</StatusChip>
                  </div>
                )}
              </div>
              <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
              <span className="sr-only">Ouvrir le registre</span>
            </Link>
          </li>
        ))}
      </ul>
    </>
  );
}
