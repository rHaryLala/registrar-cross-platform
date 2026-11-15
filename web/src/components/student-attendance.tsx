"use client";

import { useQuery } from "@tanstack/react-query";
import { CalendarX2, ChevronDown, OctagonX, TriangleAlert } from "lucide-react";
import { useRef } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import { formatLongDate } from "@/lib/format";
import { cn } from "@/lib/utils";

type State = "ok" | "limit" | "exceeded" | "unknown";
type Summary = {
  recorded: number;
  present: number;
  late: number;
  absent: number;
  no_penalty: number;
  converted_lates: number;
  counted: number;
  late_pending: boolean;
  planned: number | null;
  allowed: number | null;
  rate: number | null;
  state: State;
  gauge: number;
};
type Meeting = {
  date: string;
  time: string | null;
  status: "present" | "retard" | "absent" | null;
  minutes_late: number | null;
  category: "present" | "retard" | "absence" | null;
  label: string;
  no_penalty: boolean;
  justification: "medical" | "autre" | null;
};
type Course = {
  sheet_id: number;
  course_code: string | null;
  course_title: string | null;
  school_year: string;
  semester_label: string;
  summary: Summary;
  sessions: Meeting[];
};
type Attendance = { courses: Course[]; alerts: { sheet_id: number; course_code: string | null; state: "limit" | "exceeded"; late_pending: boolean }[] };

const STATE: Record<State, { label: string; tone: "ok" | "warn" | "bad" | "info"; bar: string }> = {
  ok: { label: "Dans la limite des 15 %", tone: "ok", bar: "bg-success" },
  limit: { label: "À la limite des 15 %", tone: "warn", bar: "bg-warning" },
  exceeded: { label: "Au-delà de 15 %", tone: "bad", bar: "bg-destructive" },
  unknown: { label: "Seuil non calculé", tone: "info", bar: "bg-muted-foreground" },
};

export function StudentAttendance() {
  const scope = useRef<HTMLDivElement>(null);
  const q = useQuery({
    queryKey: ["me", "attendance"],
    queryFn: async () => (await api<{ data: Attendance }>("/api/v1/me/attendance")).data,
  });
  useEntrance(scope, "[data-enter]", q.isSuccess);

  return (
    <AppShell title="Mon assiduité">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        {q.isPending && <AttendanceSkeleton />}
        {q.isError && <AttendanceError error={q.error} onRetry={() => q.refetch()} />}
        {q.isSuccess && <AttendanceContent data={q.data} />}
      </div>
    </AppShell>
  );
}

function AttendanceContent({ data }: { data: Attendance }) {
  const empty = data.courses.length === 0;

  return (
    <>
      {data.alerts.map((a) => (
        <AlertBanner key={a.sheet_id} alert={a} />
      ))}

      <Card data-enter className="p-0">
        <details open={empty} className="group">
          <summary className="flex min-h-12 cursor-pointer items-center gap-2 px-4 py-2 font-bold">
            Les règles d’assiduité (Bulletin académique, §472 à §476)
            <ChevronDown className="ml-auto size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden />
          </summary>
          <ul className="grid gap-2 px-4 pb-4 text-sm text-muted-foreground [&>li]:list-disc [&>li]:ml-4">
            <li>La présence régulière à tous les cours est exigée (§472).</li>
            <li>
              Un retard de moins de 5 minutes n’est pas compté. De 5 à 9 minutes, c’est un retard, et <strong className="text-foreground">deux retards comptent comme une absence</strong>. À partir de
              10 minutes, c’est une absence (§476).
            </li>
            <li>
              Au-delà de <strong className="text-foreground">15 % d’absences</strong> dans un cours, vous devez quitter ce cours, quelle qu’en soit la raison : une inscription tardive ou une maladie
              n’excusent pas l’absence. Avec un certificat médical ou un billet d’hôpital, vous pouvez refaire les tests et examens manqués ; présentez votre justificatif à l’enseignant à votre retour
              (§476).
            </li>
            <li>Si l’enseignant n’est pas là 10 minutes après l’heure et n’a envoyé aucun message, vous pouvez partir sans pénalité : cette séance ne compte pas contre vous (§474).</li>
          </ul>
        </details>
      </Card>

      {empty && (
        <Card data-enter className="items-center gap-2 p-8 text-center">
          <CalendarX2 className="size-8 text-muted-foreground" aria-hidden />
          <p className="font-bold">Aucune présence relevée pour le moment</p>
          <p className="text-sm text-muted-foreground">Vos présences, retards et absences apparaîtront ici dès que vos enseignants auront fait l’appel.</p>
        </Card>
      )}

      {data.courses.map((c) => (
        <CourseCard key={c.sheet_id} course={c} />
      ))}
    </>
  );
}

function AlertBanner({ alert }: { alert: Attendance["alerts"][number] }) {
  const exceeded = alert.state === "exceeded";
  const Icon = exceeded ? OctagonX : TriangleAlert;
  return (
    <Card
      data-enter
      role={exceeded ? "alert" : "status"}
      className={cn("flex-row items-start gap-3 p-4", exceeded ? "border-destructive text-foreground" : "border-warning text-foreground")}
    >
      <Icon className={cn("mt-0.5 size-5 shrink-0", exceeded ? "text-destructive" : "text-warning")} aria-hidden />
      <p className="text-sm leading-relaxed">
        <strong>{alert.course_code}</strong> :{" "}
        {exceeded
          ? "vos absences dépassent 15 % des séances prévues. Selon le Bulletin académique, vous devez quitter ce cours ; le registrariat en a été informé. Présentez-vous au bureau de la scolarité."
          : `vous êtes à la limite des 15 % : une absence de plus${alert.late_pending ? ", ou un retard de plus," : ""} et vous devrez quitter ce cours.`}
      </p>
    </Card>
  );
}

function CourseCard({ course }: { course: Course }) {
  const s = course.summary;
  const st = STATE[s.state];
  const headingId = `cours-${course.sheet_id}`;

  return (
    <section data-enter aria-labelledby={headingId}>
      <Card className="gap-0 p-0">
        <div className="flex flex-wrap items-start justify-between gap-3 border-b p-4">
          <div className="min-w-0">
            <p className="font-mono text-sm font-semibold text-accent-text">{course.course_code}</p>
            <h2 id={headingId} className="text-lg font-extrabold tracking-tight">
              {course.course_title}
            </h2>
            <p className="text-sm text-muted-foreground">
              {course.school_year} · {course.semester_label}
            </p>
          </div>
          <StatusChip tone={st.tone}>{st.label}</StatusChip>
        </div>

        <dl className="grid grid-cols-2 gap-px bg-border md:grid-cols-4">
          <Figure value={s.present} label="présence(s)" />
          <Figure value={s.late} label="retard(s) de 5 à 9 min" />
          <Figure value={s.absent} label="absence(s)" />
          <Figure value={s.counted} suffix={s.allowed !== null ? ` / ${s.allowed}` : undefined} label="absences comptées / permises" />
        </dl>

        <div className="border-t p-4">
          {s.allowed === null ? (
            <p className="text-sm text-muted-foreground">Votre enseignant n’a pas encore indiqué le nombre de séances prévues : le seuil des 15 % n’est pas encore calculé.</p>
          ) : (
            <>
              <p className="text-sm text-muted-foreground">
                {s.planned} séances prévues : {s.allowed} absence(s) permise(s).
                {s.late_pending && " Un retard est en attente : le prochain comptera comme une absence."}
                {s.no_penalty > 0 && ` ${s.no_penalty} absence(s) non comptée(s) (enseignant en retard, §474).`}
              </p>
              <div
                role="progressbar"
                aria-label={`${s.counted} absence(s) comptée(s) sur ${s.allowed} permise(s)`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={s.gauge}
                className="mt-2 h-2 overflow-hidden rounded-full bg-muted"
              >
                <div className={cn("h-full rounded-full", st.bar)} style={{ width: `${s.gauge}%` }} />
              </div>
            </>
          )}
        </div>

        <details className="group border-t">
          <summary className="flex min-h-12 cursor-pointer items-center gap-2 px-4 text-sm text-muted-foreground">
            {course.sessions.length} séance(s) relevée(s) — voir le détail
            <ChevronDown className="ml-auto size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden />
          </summary>
          <ul className="divide-y border-t">
            {course.sessions.map((m, i) => (
              <MeetingRow key={`${m.date}-${i}`} meeting={m} />
            ))}
          </ul>
        </details>
      </Card>
    </section>
  );
}

function Figure({ value, suffix, label }: { value: number; suffix?: string; label: string }) {
  return (
    <div className="bg-card px-4 py-3">
      <dd className="text-2xl font-extrabold tabular-nums">
        {value}
        {suffix && <span className="text-sm font-semibold text-muted-foreground">{suffix}</span>}
      </dd>
      <dt className="text-xs text-muted-foreground">{label}</dt>
    </div>
  );
}

function MeetingRow({ meeting: m }: { meeting: Meeting }) {
  const tone = m.category === "absence" ? "text-destructive" : m.category === "retard" ? "text-warning" : m.category === "present" ? "text-success" : "text-muted-foreground";
  return (
    <li className="flex flex-col gap-0.5 px-4 py-3 text-sm md:flex-row md:justify-between md:gap-4">
      <span>
        {formatLongDate(m.date)}
        {m.time ? ` · ${m.time}` : ""}
      </span>
      <span className={cn("font-semibold", tone)}>
        {m.label}
        {m.no_penalty && m.category !== null && m.category !== "present" && <span className="font-normal text-muted-foreground"> · non compté (§474)</span>}
        {m.justification === "medical" && <span className="font-normal text-muted-foreground"> · certificat médical</span>}
        {m.justification === "autre" && <span className="font-normal text-muted-foreground"> · justificatif</span>}
      </span>
    </li>
  );
}

function AttendanceSkeleton() {
  return (
    <div className="grid gap-5" aria-busy="true" aria-label="Chargement de votre assiduité">
      <Skeleton className="h-14 w-full rounded-xl" />
      <Skeleton className="h-64 w-full rounded-xl" />
    </div>
  );
}

function AttendanceError({ error, onRetry }: { error: Error; onRetry: () => void }) {
  const offline = error instanceof ApiError && error.isOffline;
  return (
    <Card role="alert" className="gap-3 p-4">
      <p className="font-bold">{offline ? "Vous êtes hors ligne" : "Votre assiduité n’a pas pu se charger"}</p>
      <p className="text-muted-foreground">{error instanceof ApiError && error.detail ? error.detail : "Réessayez dans un instant ; si cela continue, contactez le registrariat."}</p>
      <div>
        <Button onClick={onRetry}>Réessayer</Button>
      </div>
    </Card>
  );
}
