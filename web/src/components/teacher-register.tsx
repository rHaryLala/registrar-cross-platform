"use client";

import { useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, CalendarPlus, ChevronRight, OctagonX, Save, Hourglass } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { CopyBanner, Gauge, LoadError, STATE_LABEL, fullName } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { useRegister } from "@/hooks/use-teacher-queries";
import { ApiError } from "@/lib/api";
import { formatLongDate, plural } from "@/lib/format";
import { nowHHMM, openSession, setPlanned } from "@/lib/teacher-actions";
import { callUrl, noPenalty, normalizeTime, parseSemester, teacherLateMinutes, type RegisterData } from "@/lib/teacher-presence";

/** Le registre d'un cours : base des 15 %, ouverture d'une séance, séances tenues, bilan de chaque étudiant. */
export function TeacherRegister() {
  const params = useSearchParams();
  const course = Number(params.get("cours"));
  const year = params.get("annee") ?? "";
  const semester = parseSemester(params.get("semestre"));
  const scope = useRef<HTMLDivElement>(null);
  const q = useRegister(course, year, semester);
  useEntrance(scope, "[data-enter]", q.isSuccess);

  return (
    <AppShell title="Registre de présence">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        <Link href="/presence/" className="inline-flex min-h-11 w-fit items-center gap-1.5 text-sm font-bold text-accent-text hover:underline">
          <ArrowLeft className="size-4" aria-hidden /> Mes cours
        </Link>
        {(!Number.isInteger(course) || course <= 0 || year === "") && (
          <LoadError error={new ApiError(404, "presence.not_found", "Registre introuvable", "Adresse incomplète : ouvrez le registre depuis la liste de vos cours.")} onRetry={() => undefined} what="Le registre" />
        )}
        {q.isPending && Number.isInteger(course) && course > 0 && year !== "" && (
          <div className="grid gap-4" aria-busy="true" aria-label="Chargement du registre">
            <Skeleton className="h-16 w-full rounded-xl" />
            <Skeleton className="h-40 w-full rounded-xl" />
            <Skeleton className="h-64 w-full rounded-xl" />
          </div>
        )}
        {q.isError && <LoadError error={q.error} onRetry={() => q.refetch()} what="Le registre" />}
        {q.isSuccess && (
          <>
            <CopyBanner copyAt={q.data.copyAt} />
            <Content reg={q.data.data} />
          </>
        )}
      </div>
    </AppShell>
  );
}

function Content({ reg }: { reg: RegisterData }) {
  return (
    <>
      <div data-enter>
        <p className="font-mono text-sm font-semibold text-accent-text">{reg.course.code}</p>
        <h2 className="text-2xl font-extrabold tracking-tight">{reg.course.title}</h2>
        <p className="text-sm text-muted-foreground">
          {reg.year} · {reg.semester_label} · {plural(reg.students.length, "étudiant")}
        </p>
      </div>

      {reg.exceeded > 0 && (
        <Card data-enter role="alert" className="flex-row items-start gap-3 border-destructive p-4">
          <OctagonX className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden />
          <p className="text-sm leading-relaxed">
            <strong>{plural(reg.exceeded, "étudiant dépasse", "étudiants dépassent")} 15 % d’absences</strong> : {reg.exceeded > 1 ? "ils doivent" : "il doit"} quitter le cours (§476). Le registrariat en a été informé ; voir le bilan plus bas.
          </p>
        </Card>
      )}

      <PlannedCard reg={reg} />
      <NewSessionCard reg={reg} />
      <SessionsList reg={reg} />
      <Balance reg={reg} />
    </>
  );
}

function PlannedCard({ reg }: { reg: RegisterData }) {
  const qc = useQueryClient();
  const [value, setValue] = useState(reg.sheet.planned_sessions === null ? "" : String(reg.sheet.planned_sessions));
  const [busy, setBusy] = useState(false);
  const allowed = reg.sheet.allowed_absences;
  const parsed = value.trim() === "" ? null : Number(value);
  const valid = parsed === null || (Number.isInteger(parsed) && parsed >= 1 && parsed <= 200);
  const dirty = parsed !== reg.sheet.planned_sessions;

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!valid) return;
    setBusy(true);
    try {
      const res = await setPlanned(qc, { course: reg.course.id, year: reg.year, semester: reg.semester }, parsed);
      toast.success(res.queued ? "Enregistré sur cet appareil" : "Séances prévues enregistrées", { description: res.queued ? "Il partira au retour du réseau." : undefined });
    } catch (err) {
      toast.error("Enregistrement impossible", { description: err instanceof ApiError ? (err.fieldErrors ? "Indiquez un nombre entre 1 et 200." : err.detail) : undefined });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card data-enter className="gap-3 p-4">
      <h3 className="text-lg font-extrabold">Base des 15 %</h3>
      <form onSubmit={save} className="flex flex-wrap items-end gap-3">
        <div className="grid gap-1.5">
          <Label htmlFor="planned">Séances prévues ce semestre</Label>
          <Input id="planned" inputMode="numeric" value={value} onChange={(e) => setValue(e.target.value)} placeholder="ex. 30" aria-invalid={!valid} className="w-32" />
        </div>
        <Button type="submit" disabled={busy || !valid || !dirty}>
          <Save aria-hidden /> Enregistrer
        </Button>
      </form>
      {!valid && <p className="text-sm text-destructive">Indiquez un nombre entier entre 1 et 200, ou laissez vide.</p>}
      <p className="text-sm text-muted-foreground" aria-live="polite">
        {allowed === null
          ? "Indiquez-les : sans elles, la règle des 15 % ne peut pas s’appliquer."
          : `${plural(allowed, "absence permise", "absences permises")} ; la ${allowed + 1}${allowed === 0 ? "re" : "e"} fait dépasser 15 %.`}
      </p>
    </Card>
  );
}

function NewSessionCard({ reg }: { reg: RegisterData }) {
  const qc = useQueryClient();
  const router = useRouter();
  const [date, setDate] = useState(reg.today);
  const [planned, setPlannedTime] = useState(reg.suggested_time ?? "");
  const [arrival, setArrival] = useState(() => nowHHMM());
  const [warned, setWarned] = useState(false);
  const [busy, setBusy] = useState(false);
  // §474 : on le dit AVANT d'ouvrir la séance, pas après (« Votre arrivée » vaut l'heure actuelle tant qu'on ne la corrige pas).
  const plannedNorm = planned === "" ? null : normalizeTime(planned);
  const arrivalNorm = arrival === "" ? null : normalizeTime(arrival);
  const lateNote = noPenalty(plannedNorm, arrivalNorm, warned) ? teacherLateMinutes(plannedNorm, arrivalNorm) : null;

  async function submit(e: FormEvent) {
    e.preventDefault();
    const p = planned === "" ? null : normalizeTime(planned);
    const a = arrival === "" ? null : normalizeTime(arrival);
    if ((planned !== "" && p === null) || (arrival !== "" && a === null)) return toast.error("Heure invalide", { description: "Indiquez les heures au format HH:MM." });
    if (date === "" || date > reg.today) return toast.error("Date invalide", { description: "On ne fait pas l’appel d’une séance à venir." });
    setBusy(true);
    try {
      const id = await openSession(qc, reg, { date, planned_time: p, teacher_arrival: a, warned });
      router.push(callUrl(id, reg.course.id, reg.year, reg.semester));
    } catch (err) {
      toast.error("Ouverture impossible", { description: err instanceof ApiError ? err.detail : undefined });
      setBusy(false);
    }
  }

  return (
    <Card data-enter className="gap-3 p-4">
      <h3 className="text-lg font-extrabold">Nouvelle séance</h3>
      <form onSubmit={submit} className="grid gap-3">
        <div className="grid gap-3 sm:grid-cols-3">
          <div className="grid gap-1.5">
            <Label htmlFor="date">Date</Label>
            <Input id="date" type="date" value={date} max={reg.today} required onChange={(e) => setDate(e.target.value)} />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="planned-time">Début prévu</Label>
            <Input id="planned-time" type="time" value={planned} onChange={(e) => setPlannedTime(e.target.value)} />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="arrival">Votre arrivée</Label>
            <Input id="arrival" type="time" value={arrival} onChange={(e) => setArrival(e.target.value)} />
          </div>
        </div>
        {lateNote && (
          <p role="status" className="flex items-start gap-2 rounded-lg border border-warning p-3 text-sm">
            <Hourglass className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
            <span>
              Vous avez {lateNote} min de retard et n’avez pas prévenu : cette séance sera <strong>sans pénalité pour les étudiants</strong> (§474). Corrigez « Votre arrivée », ou cochez la case ci-dessous si vous les aviez prévenus.
            </span>
          </p>
        )}
        <Label htmlFor="warned" className="min-h-11 gap-3 text-sm">
          <input id="warned" type="checkbox" checked={warned} onChange={(e) => setWarned(e.target.checked)} className="size-5 accent-[var(--primary)]" />
          J’ai prévenu les étudiants d’un retard (§474)
        </Label>
        <div>
          <Button type="submit" size="lg" disabled={busy}>
            <CalendarPlus aria-hidden /> Faire l’appel
          </Button>
        </div>
      </form>
      <p className="text-xs text-muted-foreground">Ouvrir deux fois la même séance (même date, même heure) rouvre la première : rien n’est compté en double.</p>
    </Card>
  );
}

function SessionsList({ reg }: { reg: RegisterData }) {
  return (
    <section data-enter aria-labelledby="seances" className="grid gap-3">
      <h3 id="seances" className="text-lg font-extrabold">
        Séances tenues <span className="text-base font-semibold text-muted-foreground">({reg.sessions.length}{reg.sheet.planned_sessions ? ` sur ${reg.sheet.planned_sessions} prévues` : ""})</span>
      </h3>
      {reg.sessions.length === 0 ? (
        <Card className="p-4 text-sm text-muted-foreground">Aucune séance pour l’instant. Ouvrez-en une avec « Faire l’appel ».</Card>
      ) : (
        <ul className="grid gap-2">
          {reg.sessions.map((s) => (
            <li key={s.id}>
              <Link
                href={callUrl(s.id, reg.course.id, reg.year, reg.semester)}
                className="flex items-center gap-3 rounded-xl bg-card p-3 ring-1 ring-foreground/10 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
              >
                <div className="min-w-0 flex-1">
                  <p className="font-bold">
                    {formatLongDate(s.date)}
                    {s.planned_time ? ` · ${s.planned_time}` : ""}
                    {s.id < 0 && <span className="ml-2 text-xs font-semibold text-warning">(sur cet appareil, pas encore envoyée)</span>}
                  </p>
                  <p className="text-sm text-muted-foreground">
                    {s.absent} abs. · {s.late} ret.
                    {s.to_mark > 0 && <span className="font-semibold text-warning"> · {s.to_mark} à pointer</span>}
                  </p>
                  {s.no_penalty && (
                    <p className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-warning">
                      <Hourglass className="size-3" aria-hidden /> Sans pénalité pour les étudiants (§474)
                    </p>
                  )}
                </div>
                <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function Balance({ reg }: { reg: RegisterData }) {
  return (
    <section data-enter aria-labelledby="bilan" className="grid gap-3">
      <div>
        <h3 id="bilan" className="text-lg font-extrabold">
          Bilan des absences (§476)
        </h3>
        <p className="text-sm text-muted-foreground">Absences comptées = absences + 1 par paire de retards.</p>
      </div>
      <Card className="gap-0 p-0">
        <ul className="divide-y">
          {reg.students.map((s) => {
            const st = STATE_LABEL[s.summary.state];
            return (
              <li key={s.id} className="grid gap-1.5 p-3">
                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                  <p className="font-semibold">
                    {fullName(s)} <span className="font-mono text-xs font-normal text-muted-foreground">{s.id}</span>
                  </p>
                  <StatusChip tone={st.tone}>{st.label}</StatusChip>
                </div>
                <p className="text-sm text-muted-foreground">
                  {s.summary.present} prés. · {s.summary.late} ret. · {s.summary.absent} abs. ·{" "}
                  <strong className="text-foreground">
                    {s.summary.counted}
                    {s.summary.allowed !== null ? ` / ${s.summary.allowed}` : ""} comptée(s)
                  </strong>
                  {s.summary.rate !== null && ` · ${String(s.summary.rate).replace(".", ",")} %`}
                </p>
                {s.summary.allowed !== null && <Gauge value={s.summary.gauge} bar={st.bar} label={`${s.summary.counted} absence(s) comptée(s) sur ${s.summary.allowed} permise(s)`} />}
                {s.flagged_at && <p className="text-xs text-muted-foreground">Signalé au registrariat le {s.flagged_at.slice(0, 10).split("-").reverse().join("/")}.</p>}
              </li>
            );
          })}
        </ul>
      </Card>
    </section>
  );
}
