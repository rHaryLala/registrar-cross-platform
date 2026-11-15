"use client";

import { useQuery } from "@tanstack/react-query";
import { useRef } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import type { User } from "@/lib/auth";
import { formatAverage, formatGrade, gradeStateChip, sessionLabel, type GradeCode } from "@/lib/format";

type Session = { name: string; year: string; semester: number };
type Grade = {
  id: number;
  course_code: string | null;
  course_title: string | null;
  credits: number | null;
  status: { code: GradeCode; title: string };
  grade: number | null;
  session: Session;
};
type Home = {
  student: { student_id: string; first_name: string; last_name: string; school_year: string } | null;
  average: number | null;
  recent_grades: Grade[];
  pending_sessions: Session[];
};

export function StudentHome({ user }: { user: User }) {
  const scope = useRef<HTMLDivElement>(null);
  const home = useQuery({
    queryKey: ["me", "home"],
    queryFn: async () => (await api<{ data: Home }>("/api/v1/me/home")).data,
  });

  useEntrance(scope, "[data-enter]", home.isSuccess);

  return (
    <AppShell title="Accueil">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-8 p-4 md:p-6">
        <section data-enter aria-labelledby="bonjour">
          <h2 id="bonjour" className="text-2xl font-extrabold tracking-tight md:text-3xl">
            Bonjour {user.first_name || user.display_name}
          </h2>
          {home.data?.student && (
            <p className="mt-1 text-muted-foreground">
              Matricule <span className="font-mono tabular-nums">{home.data.student.student_id}</span> · {home.data.student.school_year}
            </p>
          )}
        </section>

        {home.isPending && <HomeSkeleton />}
        {home.isError && <HomeError error={home.error} onRetry={() => home.refetch()} />}
        {home.isSuccess && <HomeContent data={home.data} />}
      </div>
    </AppShell>
  );
}

function HomeContent({ data }: { data: Home }) {
  return (
    <>
      <section data-enter aria-labelledby="nouveautes">
        <h2 id="nouveautes" className="mb-3 text-sm font-bold tracking-wide text-muted-foreground">
          Nouveautés
        </h2>
        <Card className="px-4 py-1">
          {data.recent_grades.length === 0 ? (
            <p className="py-6 text-center text-muted-foreground">
              Aucune note publiée pour le moment. Elles apparaissent ici dès que le Sénat publie une session.
            </p>
          ) : (
            <ul className="divide-y">
              {data.recent_grades.map((g) => (
                <GradeRow key={g.id} grade={g} />
              ))}
            </ul>
          )}
        </Card>
      </section>

      <section data-enter aria-labelledby="suivi">
        <h2 id="suivi" className="mb-3 text-sm font-bold tracking-wide text-muted-foreground">
          Mon suivi
        </h2>
        <Card className="p-4">
          <p className="text-xs font-bold tracking-wider text-accent-text uppercase">Moyenne des sessions publiées</p>
          {data.average === null ? (
            <p className="mt-2 text-muted-foreground">Pas encore de moyenne : aucune note chiffrée n’est publiée.</p>
          ) : (
            <p className="mt-1 text-4xl font-black tracking-tight tabular-nums">
              {formatAverage(data.average)}
              <span className="ml-1 text-base font-semibold text-muted-foreground">/20</span>
            </p>
          )}
        </Card>
      </section>

      {data.pending_sessions.length > 0 && (
        <section data-enter aria-labelledby="attente">
          <h2 id="attente" className="mb-3 text-sm font-bold tracking-wide text-muted-foreground">
            En attente de publication
          </h2>
          <Card className="gap-3 p-4">
            <p>
              Vos notes de {data.pending_sessions.length > 1 ? "ces sessions sont enregistrées" : "cette session sont enregistrées"} ; elles seront visibles dès
              leur publication par le Sénat.
            </p>
            <ul className="flex flex-wrap gap-2">
              {data.pending_sessions.map((s) => (
                <li key={`${s.name}-${s.year}`}>
                  <StatusChip tone="warn">{sessionLabel(s)}</StatusChip>
                </li>
              ))}
            </ul>
          </Card>
        </section>
      )}
    </>
  );
}

function GradeRow({ grade }: { grade: Grade }) {
  const chip = gradeStateChip(grade.status.code);
  return (
    <li className="flex min-h-16 items-center gap-3 py-3">
      <div className="min-w-0 flex-1">
        <p className="truncate font-bold">{grade.course_title ?? grade.course_code ?? "Cours"}</p>
        <p className="truncate text-sm text-muted-foreground">{[grade.course_code, sessionLabel(grade.session)].filter(Boolean).join(" · ")}</p>
      </div>
      {grade.grade !== null ? (
        <span className="text-2xl font-extrabold tracking-tight tabular-nums">{formatGrade(grade.grade)}</span>
      ) : (
        <StatusChip tone={chip.tone}>{chip.label}</StatusChip>
      )}
    </li>
  );
}

function HomeSkeleton() {
  return (
    <div className="grid gap-8" aria-busy="true" aria-label="Chargement de votre accueil">
      <Skeleton className="h-64 w-full rounded-xl" />
      <Skeleton className="h-28 w-full rounded-xl" />
    </div>
  );
}

function HomeError({ error, onRetry }: { error: Error; onRetry: () => void }) {
  const offline = error instanceof ApiError && error.isOffline;
  return (
    <Card role="alert" className="gap-3 p-4">
      <p className="font-bold">{offline ? "Vous êtes hors ligne" : "Votre accueil n’a pas pu se charger"}</p>
      <p className="text-muted-foreground">
        {error instanceof ApiError && error.detail ? error.detail : "Réessayez dans un instant ; si cela continue, contactez le registrariat."}
      </p>
      <div>
        <Button onClick={onRetry}>Réessayer</Button>
      </div>
    </Card>
  );
}
