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
import { formatAverage, formatGrade, gradeStateChip, sessionLabel, type GradeCode } from "@/lib/format";

type Course = {
  id: number;
  code: string | null;
  title: string | null;
  credits: number | null;
  category: string;
  status: { code: GradeCode; title: string };
  grade: number | null;
  counts_in_average: boolean;
};
type SessionBlock = {
  session: { id: number; name: string; year: string; semester: number };
  level_label: string | null;
  courses: Course[];
  totals: { courses: number; credits: number };
  averages: { major: number | null; general: number | null };
  promotion: { work_education: number | null; academic_remark: number | null; chapel: number | null } | null;
};
type Grades = {
  sessions: SessionBlock[];
  cumulative: { major: number | null; general: number | null; work_education: number | null; chapel: number | null };
  promotion_visible: boolean;
  pending_sessions: { name: string; year: string; semester: number }[];
};

export function StudentGrades() {
  const scope = useRef<HTMLDivElement>(null);
  const grades = useQuery({
    queryKey: ["me", "grades"],
    queryFn: async () => (await api<{ data: Grades }>("/api/v1/me/grades")).data,
  });
  useEntrance(scope, "[data-enter]", grades.isSuccess);

  return (
    <AppShell title="Mes notes">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
        {grades.isPending && <GradesSkeleton />}
        {grades.isError && <GradesError error={grades.error} onRetry={() => grades.refetch()} />}
        {grades.isSuccess && <GradesContent data={grades.data} />}
      </div>
    </AppShell>
  );
}

function GradesContent({ data }: { data: Grades }) {
  // Plus récent d'abord : c'est ce que l'étudiant vient chercher. (L'ancienne page montrait le plus ancien en premier.)
  const sessions = [...data.sessions].reverse();
  const c = data.cumulative;

  return (
    <>
      <Card data-enter className="gap-4 p-4" aria-labelledby="cumul">
        <h2 id="cumul" className="text-xs font-bold tracking-wider text-accent-text uppercase">
          Moyenne cumulative
        </h2>
        <p className="text-5xl font-black tracking-tight tabular-nums">
          {formatAverage(c.general)}
          {c.general !== null && <span className="ml-1 text-lg font-semibold text-muted-foreground">/20</span>}
        </p>
        <dl className="grid grid-cols-2 gap-4 text-sm md:grid-cols-3">
          <Figure label="Moyenne majeure" value={formatAverage(c.major)} />
          {data.promotion_visible && <Figure label="Work Education" value={formatAverage(c.work_education)} />}
          {data.promotion_visible && <Figure label="Chapelle et prière" value={formatAverage(c.chapel)} />}
        </dl>
        <p className="text-xs text-muted-foreground">Calculée sur les sessions publiées, pondérée par les crédits.</p>
      </Card>

      {sessions.length === 0 && (
        <Card data-enter className="p-6 text-center text-muted-foreground">
          Aucune note publiée pour le moment. Elles apparaissent ici dès que le Sénat publie une session.
        </Card>
      )}

      {sessions.map((s) => (
        <SessionCard key={s.session.id} block={s} showPromotion={data.promotion_visible} />
      ))}

      {data.pending_sessions.length > 0 && (
        <Card data-enter className="gap-3 p-4">
          <h2 className="font-bold">En attente de publication</h2>
          <p className="text-sm text-muted-foreground">
            Vos notes de {data.pending_sessions.length > 1 ? "ces sessions sont enregistrées" : "cette session sont enregistrées"} ; elles seront visibles dès leur
            publication par le Sénat.
          </p>
          <ul className="flex flex-wrap gap-2">
            {data.pending_sessions.map((p) => (
              <li key={`${p.name}-${p.year}`}>
                <StatusChip tone="warn">{sessionLabel(p)}</StatusChip>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </>
  );
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="text-xl font-extrabold tabular-nums">{value}</dd>
    </div>
  );
}

function SessionCard({ block, showPromotion }: { block: SessionBlock; showPromotion: boolean }) {
  const { session, courses } = block;
  const promo = showPromotion ? block.promotion : null;
  const headingId = `session-${session.id}`;

  return (
    <section data-enter aria-labelledby={headingId}>
      <div className="mb-2 flex flex-wrap items-baseline justify-between gap-x-4">
        <h2 id={headingId} className="text-lg font-extrabold tracking-tight">
          {[block.level_label, session.name].filter(Boolean).join(" · ")}
        </h2>
        <p className="text-sm text-muted-foreground">
          {session.year} · {block.totals.courses} cours · {block.totals.credits} crédits
        </p>
      </div>

      <Card className="gap-0 p-0">
        <ul className="divide-y">
          {courses.map((course) => (
            <CourseRow key={course.id} course={course} />
          ))}
        </ul>

        {promo && (
          <dl className="grid grid-cols-3 gap-2 border-t bg-muted/40 px-4 py-3 text-sm">
            <Figure label="Work Education" value={formatAverage(promo.work_education)} />
            <Figure label="Remarque académique" value={formatAverage(promo.academic_remark)} />
            <Figure label="Chapelle et prière" value={formatAverage(promo.chapel)} />
          </dl>
        )}

        <dl className="grid grid-cols-2 gap-2 border-t px-4 py-3 text-sm">
          <Figure label="Moyenne majeure" value={formatAverage(block.averages.major)} />
          <div>
            <dt className="font-bold text-accent-text">Moyenne générale</dt>
            <dd className="text-2xl font-black tabular-nums">{formatAverage(block.averages.general)}</dd>
          </div>
        </dl>
      </Card>
    </section>
  );
}

function CourseRow({ course }: { course: Course }) {
  const chip = gradeStateChip(course.status.code);
  return (
    <li className="grid min-h-16 grid-cols-[1fr_auto] items-center gap-x-3 px-4 py-3 md:grid-cols-[6.5rem_1fr_4.5rem_6.5rem_5rem_7.5rem]">
      <p className="text-xs font-bold tracking-wide text-muted-foreground uppercase md:text-sm md:normal-case md:tracking-normal md:font-mono">{course.code}</p>
      <p className="col-start-1 font-bold md:col-start-auto">{course.title}</p>
      <p className="col-start-1 text-sm text-muted-foreground md:col-start-auto md:text-center">
        {course.credits ?? "—"} cr.<span className="md:hidden"> · {course.category}</span>
      </p>
      <p className="hidden text-center text-sm text-muted-foreground md:block">{course.category}</p>
      <p className="col-start-2 row-span-3 row-start-1 self-center text-right text-2xl font-extrabold tabular-nums md:col-start-auto md:row-span-1 md:row-start-auto md:text-center md:text-xl">
        {course.grade !== null ? formatGrade(course.grade) : <span className="sr-only">Pas de note chiffrée</span>}
      </p>
      <div className="col-span-2 mt-1 md:col-span-1 md:mt-0 md:text-right">
        <StatusChip tone={chip.tone}>{chip.label}</StatusChip>
      </div>
    </li>
  );
}

function GradesSkeleton() {
  return (
    <div className="grid gap-6" aria-busy="true" aria-label="Chargement de vos notes">
      <Skeleton className="h-44 w-full rounded-xl" />
      <Skeleton className="h-72 w-full rounded-xl" />
    </div>
  );
}

function GradesError({ error, onRetry }: { error: Error; onRetry: () => void }) {
  const offline = error instanceof ApiError && error.isOffline;
  return (
    <Card role="alert" className="gap-3 p-4">
      <p className="font-bold">{offline ? "Vous êtes hors ligne" : "Vos notes n’ont pas pu se charger"}</p>
      <p className="text-muted-foreground">{error instanceof ApiError && error.detail ? error.detail : "Réessayez dans un instant ; si cela continue, contactez le registrariat."}</p>
      <div>
        <Button onClick={onRetry}>Réessayer</Button>
      </div>
    </Card>
  );
}
