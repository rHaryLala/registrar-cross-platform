"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, CircleCheck, Hourglass, OctagonAlert, SearchX } from "lucide-react";
import { useRef, useState } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { fullName, LoadError } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import {
  attendanceKeys,
  clockText,
  countedText,
  delayText,
  flagStanding,
  markProcessed,
  overviewUrl,
  stampDate,
  type AttendanceOverview,
  type Delay,
  type Flag,
  type WithoutBase,
} from "@/lib/attendance-review";
import { formatLongDate, plural } from "@/lib/format";

/** Assiduité (registrariat) : qui dépasse 15 % d'absences, quels enseignants arrivent en retard, quels registres n'ont pas de base. */
export function AttendanceReview() {
  const scope = useRef<HTMLDivElement>(null);
  const [year, setYear] = useState<string | null>(null);
  const [processed, setProcessed] = useState(false);
  const q = useQuery({
    queryKey: attendanceKeys.overview(year, processed),
    queryFn: async () => (await api<{ data: AttendanceOverview }>(overviewUrl(year, processed))).data,
    retry: 1,
  });
  useEntrance(scope, "[data-enter]", q.isSuccess);
  const d = q.data;

  return (
    <AppShell title="Assiduité">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        <div data-enter className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 className="text-2xl font-extrabold tracking-tight">Assiduité</h2>
            {d && (
              <p className="text-sm text-muted-foreground" aria-live="polite">
                {d.pending_total === 0 ? "Aucun signalement à traiter." : `${plural(d.pending_total, "signalement")} à traiter, toutes années confondues.`}
              </p>
            )}
          </div>
          <div className="flex flex-wrap items-end gap-3">
            {d && (
              <label className="grid gap-1 text-sm font-semibold">
                Année scolaire
                <select value={d.year} onChange={(e) => setYear(e.target.value)} className="h-11 rounded-lg border border-input bg-background px-3 text-base md:h-10 md:text-sm">
                  {d.years.map((y) => (
                    <option key={y} value={y}>
                      {y}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <label className="flex min-h-11 items-center gap-2 text-sm font-semibold">
              <input type="checkbox" checked={processed} onChange={(e) => setProcessed(e.target.checked)} className="size-5 accent-[var(--primary)]" />
              Afficher les traités
            </label>
          </div>
        </div>

        <p data-enter className="rounded-xl border border-accent-text/30 bg-accent/60 p-3 text-sm leading-relaxed text-muted-foreground">
          Un étudiant qui dépasse 15 % d’absences dans un cours doit le quitter ; l’enseignant doit en informer le registraire (§476). L’application le signale au
          pointage de l’absence de trop. Deux retards de 5 minutes comptent une absence, un retard de 10 minutes est une absence.
        </p>

        {q.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement de l’assiduité">
            <Skeleton className="h-32 w-full rounded-xl" />
            <Skeleton className="h-32 w-full rounded-xl" />
          </div>
        )}
        {q.isError && <LoadError error={q.error} onRetry={() => q.refetch()} what="L’assiduité" />}

        {d && (
          <>
            <FlagsSection flags={d.flags} year={d.year} showProcessed={processed} overviewKey={attendanceKeys.overview(year, processed)} />
            <DelaysSection delays={d.teacher_delays} byTeacher={d.delays_by_teacher} wait={d.wait_minutes} year={d.year} />
            <WithoutBaseSection rows={d.without_base} year={d.year} />
          </>
        )}
      </div>
    </AppShell>
  );
}

function Section({ title, icon, aside, children }: { title: string; icon: React.ReactNode; aside?: React.ReactNode; children: React.ReactNode }) {
  return (
    <section data-enter className="grid gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="flex items-center gap-2 text-lg font-extrabold tracking-tight">
          {icon}
          {title}
        </h3>
        {aside && <span className="text-sm text-muted-foreground">{aside}</span>}
      </div>
      {children}
    </section>
  );
}

function Empty({ icon, children }: { icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <Card className="flex-row items-center gap-3 p-4 text-sm text-muted-foreground">
      {icon}
      <p>{children}</p>
    </Card>
  );
}

// ---------------------------------------------------------------- 1. au-delà de 15 %

function FlagsSection({ flags, year, showProcessed, overviewKey }: { flags: Flag[]; year: string; showProcessed: boolean; overviewKey: readonly unknown[] }) {
  return (
    <Section title="Au-delà de 15 % d’absences" icon={<OctagonAlert className="size-5 text-destructive" aria-hidden />} aside={plural(flags.length, "étudiant")}>
      {flags.length === 0 ? (
        <Empty icon={<CircleCheck className="size-5 shrink-0 text-success" aria-hidden />}>
          Aucun signalement {showProcessed ? "" : "à traiter "}pour {year}.
        </Empty>
      ) : (
        <ul className="grid gap-3">
          {flags.map((f) => (
            <FlagCard key={f.id} flag={f} overviewKey={overviewKey} showProcessed={showProcessed} />
          ))}
        </ul>
      )}
    </Section>
  );
}

function FlagCard({ flag: f, overviewKey, showProcessed }: { flag: Flag; overviewKey: readonly unknown[]; showProcessed: boolean }) {
  const qc = useQueryClient();
  const standing = flagStanding(f);
  const treat = useMutation({
    mutationFn: async () => (await api<{ data: { processed: boolean; processed_at: string } }>(`/api/v1/review/attendance/flags/${f.id}/process`, { method: "POST" })).data,
    onSuccess: (res) => {
      qc.setQueryData<AttendanceOverview>(overviewKey, (old) => (old ? markProcessed(old, f.id, res.processed_at, showProcessed) : old));
      void qc.invalidateQueries({ queryKey: attendanceKeys.all });
      toast.success(res.processed ? "Signalement marqué comme traité" : "Ce signalement était déjà traité");
    },
    onError: (e) => toast.error("Enregistrement refusé", { description: e instanceof ApiError ? e.detail : "Connexion perdue : rien n’a été enregistré." }),
  });
  const t = f.today;

  return (
    <li data-enter className={f.processed_at ? "opacity-70" : undefined}>
      <Card className="gap-3 p-4">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="text-lg leading-snug font-extrabold tracking-tight">{fullName({ last_name: f.last_name, first_name: f.first_name, student_id: f.student_id })}</p>
            <p className="font-mono text-xs text-muted-foreground">{f.student_id}</p>
          </div>
          <StatusChip tone={standing.tone}>{standing.label}</StatusChip>
        </div>
        <div className="text-sm">
          <p>
            <span className="font-mono font-semibold text-accent-text">{f.code}</span> {f.title}
          </p>
          <p className="text-muted-foreground">
            {f.teacher ?? "Enseignant non indiqué"} · {f.semester_label}
          </p>
        </div>
        <div className="rounded-lg bg-muted/60 p-3 text-sm">
          <p>
            <strong className="tabular-nums">{countedText(t)}</strong> <span className="text-muted-foreground">absences comptées</span>
          </p>
          <p className="text-xs text-muted-foreground tabular-nums">
            {plural(t.absent, "absence")} · {plural(t.late, "retard")} · {t.planned ? `${t.planned} séances prévues` : "base non indiquée"}
            {t.no_penalty > 0 && ` · ${plural(t.no_penalty, "pointage")} sans pénalité (§474)`}
          </p>
          {f.counted_at_flag !== t.counted && <p className="text-xs text-muted-foreground tabular-nums">Au signalement : {f.counted_at_flag} sur {f.planned_at_flag} séances prévues.</p>}
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-xs text-muted-foreground">Signalé le {stampDate(f.flagged_at)}</p>
          {f.processed_at ? (
            <p className="inline-flex items-center gap-1.5 text-sm font-semibold text-success">
              <Check className="size-4" aria-hidden /> Traité le {stampDate(f.processed_at)}
            </p>
          ) : (
            <Button onClick={() => treat.mutate()} disabled={treat.isPending} aria-label={`Marquer comme traité : ${fullName({ last_name: f.last_name, first_name: f.first_name, student_id: f.student_id })}, ${f.code ?? "cours"}`}>
              <Check aria-hidden /> {treat.isPending ? "Enregistrement…" : "Traité"}
            </Button>
          )}
        </div>
      </Card>
    </li>
  );
}

// ---------------------------------------------------------------- 2. retards des enseignants

function DelaysSection({ delays, byTeacher, wait, year }: { delays: Delay[]; byTeacher: { teacher: string; count: number }[]; wait: number; year: string }) {
  return (
    <Section title={`Retards des enseignants (plus de ${wait} min)`} icon={<Hourglass className="size-5 text-warning" aria-hidden />} aside="§474">
      {delays.length === 0 ? (
        <Empty icon={<CircleCheck className="size-5 shrink-0 text-success" aria-hidden />}>
          Aucun retard de plus de {wait} minutes enregistré pour {year}.
        </Empty>
      ) : (
        <>
          <ul aria-label="Retards par enseignant" className="flex flex-wrap gap-2">
            {byTeacher.map((t) => (
              <li key={t.teacher} className="rounded-full border border-warning/40 bg-warning/10 px-3 py-1 text-sm font-semibold">
                {t.teacher} : <span className="tabular-nums">{t.count}</span>
              </li>
            ))}
          </ul>
          <ul className="grid gap-3">
            {delays.map((r) => (
              <li key={r.id} data-enter>
                <Card className="gap-1.5 p-4 text-sm">
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <p className="font-extrabold">{formatLongDate(r.date)}</p>
                    <p className="font-extrabold text-warning tabular-nums">{delayText(r.late_minutes)} de retard</p>
                  </div>
                  <p>
                    <span className="font-mono font-semibold text-accent-text">{r.code}</span> {r.title}
                  </p>
                  <p className="text-muted-foreground">
                    {r.teacher ?? "Enseignant non indiqué"} · prévue à {clockText(r.planned_time)}, arrivée à {clockText(r.arrival)}
                  </p>
                  <p className={r.warned ? "text-muted-foreground" : "font-semibold text-warning"}>{r.warned ? "Étudiants prévenus par message." : "Aucun message : séance sans pénalité pour les étudiants."}</p>
                </Card>
              </li>
            ))}
          </ul>
        </>
      )}
    </Section>
  );
}

// ---------------------------------------------------------------- 3. registres sans base

function WithoutBaseSection({ rows, year }: { rows: WithoutBase[]; year: string }) {
  return (
    <Section title="Registres sans séances prévues" icon={<SearchX className="size-5 text-muted-foreground" aria-hidden />} aside="La règle des 15 % ne peut pas s’y appliquer">
      {rows.length === 0 ? (
        <Empty icon={<CircleCheck className="size-5 shrink-0 text-success" aria-hidden />}>Tous les registres tenus en {year} indiquent leurs séances prévues.</Empty>
      ) : (
        <ul className="grid gap-3">
          {rows.map((f) => (
            <li key={f.sheet_id} data-enter>
              <Card className="flex-row items-center justify-between gap-3 p-4 text-sm">
                <div className="min-w-0">
                  <p>
                    <span className="font-mono font-semibold text-accent-text">{f.code}</span> {f.title}
                  </p>
                  <p className="text-muted-foreground">
                    {f.teacher ?? "Enseignant non indiqué"} · {f.semester_label}
                  </p>
                </div>
                <p className="shrink-0 text-right tabular-nums">
                  <strong>{f.held_sessions}</strong>
                  <span className="block text-xs text-muted-foreground">{f.held_sessions < 2 ? "séance tenue" : "séances tenues"}</span>
                </p>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </Section>
  );
}
