"use client";

import { useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ChevronDown, CircleCheck, Hourglass, Trash2 } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { CopyBanner, LoadError, STATE_LABEL, fullName } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useSessionData } from "@/hooks/use-teacher-queries";
import { ApiError } from "@/lib/api";
import { formatLongDate, plural } from "@/lib/format";
import { useRealSession } from "@/lib/offline-sync";
import { deleteSession, editSession, justifyStudent, markAllPresent, markStudent } from "@/lib/teacher-actions";
import { callUrl, normalizeTime, parseSemester, registerUrl, type Justification, type Line, type SessionData, type Status } from "@/lib/teacher-presence";
import { cn } from "@/lib/utils";

/** L'appel d'une séance : un geste par étudiant, le résultat est visible tout de suite, avec ou sans réseau. */
export function TeacherCall() {
  const params = useSearchParams();
  const router = useRouter();
  const asked = Number(params.get("seance"));
  const course = Number(params.get("cours"));
  const year = params.get("annee") ?? "";
  const semester = parseSemester(params.get("semestre"));
  // Une séance créée hors ligne reçoit son vrai identifiant à l'envoi : l'adresse suit.
  const id = useRealSession(asked);
  const q = useSessionData(id);

  useEffect(() => {
    if (id !== asked && course > 0) router.replace(callUrl(id, course, year, semester));
  }, [id, asked, course, year, semester, router]);

  const back = Number.isInteger(course) && course > 0 && year !== "" ? registerUrl(course, year, semester) : "/presence/";

  return (
    <AppShell title="Appel">
      <div className="mx-auto grid w-full max-w-3xl gap-4 p-4 md:p-6">
        <Link href={back} className="inline-flex min-h-11 w-fit items-center gap-1.5 text-sm font-bold text-accent-text hover:underline">
          <ArrowLeft className="size-4" aria-hidden /> Registre du cours
        </Link>
        {!Number.isInteger(asked) || asked === 0 ? (
          <LoadError error={new ApiError(404, "presence.not_found", "Séance introuvable", "Adresse incomplète : ouvrez la séance depuis le registre du cours.")} onRetry={() => undefined} what="La séance" />
        ) : (
          <>
            {q.isPending && (
              <div className="grid gap-3" aria-busy="true" aria-label="Chargement de l’appel">
                <Skeleton className="h-16 w-full rounded-xl" />
                {[0, 1, 2, 3].map((i) => (
                  <Skeleton key={i} className="h-28 w-full rounded-xl" />
                ))}
              </div>
            )}
            {q.isError && <LoadError error={q.error} onRetry={() => q.refetch()} what="L’appel" />}
            {q.isSuccess && (
              <>
                <CopyBanner copyAt={q.data.copyAt} />
                <Roll data={q.data.data} id={id} course={course} year={year} semester={semester} back={back} />
              </>
            )}
          </>
        )}
      </div>
    </AppShell>
  );
}

function Roll({ data, id, course, year, semester, back }: { data: SessionData; id: number; course: number; year: string; semester: number | null; back: string }) {
  const qc = useQueryClient();
  const router = useRouter();
  const s = data.session;
  const marked = data.lines.filter((l) => l.status !== null).length;
  const total = data.lines.length;
  const remaining = total - marked;
  const [busyAll, setBusyAll] = useState(false);

  async function allPresent() {
    setBusyAll(true);
    try {
      const res = await markAllPresent(qc, id);
      toast.success(res.queued ? "Enregistré sur cet appareil" : `${plural(res.data ? ((res.data as { marked?: number }).marked ?? 0) : 0, "étudiant marqué présent", "étudiants marqués présents")}`);
    } catch (e) {
      toast.error("Action impossible", { description: e instanceof ApiError ? e.detail : undefined });
    } finally {
      setBusyAll(false);
    }
  }

  return (
    <>
      <div>
        <h2 className="text-2xl font-extrabold tracking-tight">{formatLongDate(s.date)}</h2>
        <p className="text-sm text-muted-foreground">
          {s.planned_time ? `Début prévu ${s.planned_time}` : "Heure non indiquée"}
          {s.teacher_arrival ? ` · votre arrivée ${s.teacher_arrival}` : ""}
          {id < 0 && <span className="font-semibold text-warning"> · séance sur cet appareil, pas encore envoyée</span>}
        </p>
        {s.no_penalty && (
          <p className="mt-1 inline-flex items-center gap-1.5 text-sm font-semibold text-warning">
            <Hourglass className="size-4" aria-hidden /> Sans pénalité pour les étudiants : vous avez {s.teacher_late_minutes} min de retard sans avoir prévenu (§474).
          </p>
        )}
      </div>

      <Card className="sticky top-16 z-10 flex-row flex-wrap items-center gap-3 p-3">
        <div className="min-w-0 flex-1" aria-live="polite">
          <p className="text-sm font-bold">
            {marked} / {total} pointés
          </p>
          <div role="progressbar" aria-label={`${marked} étudiants pointés sur ${total}`} aria-valuemin={0} aria-valuemax={total} aria-valuenow={marked} className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
            <div className="h-full rounded-full bg-success transition-[width]" style={{ width: total ? `${(marked / total) * 100}%` : "0%" }} />
          </div>
        </div>
        <Button onClick={allPresent} disabled={busyAll || remaining === 0}>
          <CircleCheck aria-hidden /> {remaining === 0 ? "Tout le monde est pointé" : `Tous présents (${remaining})`}
        </Button>
      </Card>

      <ul className="grid gap-3" aria-label="Étudiants">
        {data.lines.map((l) => (
          <StudentRow key={l.student_id} line={l} session={id} />
        ))}
      </ul>

      <SessionSettings data={data} id={id} />
      <DeleteSession id={id} onDeleted={() => router.replace(back)} qcCtx={{ course, year, semester }} qc={qc} />
    </>
  );
}

const CHOICES: { status: Status; label: string; on: string }[] = [
  { status: "present", label: "Présent", on: "bg-success text-white" },
  { status: "retard", label: "Retard", on: "bg-warning text-black" },
  { status: "absent", label: "Absent", on: "bg-destructive text-white" },
];

function StudentRow({ line: l, session }: { line: Line; session: number }) {
  const qc = useQueryClient();
  // Pendant la saisie on montre ce que la personne tape ; sinon, la durée enregistrée (qui suit le serveur après une synchronisation).
  const [typed, setTyped] = useState<string | null>(null);
  const mins = typed ?? String(l.minutes_late ?? 5);
  const setMins = setTyped;
  const name = fullName(l);
  const st = STATE_LABEL[l.summary.state];

  async function mark(status: Status | null, minutes: number | null = null) {
    try {
      const res = await markStudent(qc, session, l.student_id, status, minutes);
      if (res.data?.newly_flagged) toast.warning(`${name} dépasse 15 % d’absences`, { description: "Le registrariat en est informé (§476)." });
      else if (res.queued) toast("Enregistré sur cet appareil", { description: "Il partira au retour du réseau.", id: "queued-mark" });
    } catch (e) {
      toast.error(`Pointage de ${name} impossible`, { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." });
    }
  }

  const commitMinutes = () => {
    const n = Number(mins);
    setTyped(null);
    if (!Number.isInteger(n) || n < 1 || n > 240) return toast.error("Durée invalide", { description: "Indiquez le retard en minutes, de 1 à 240." });
    if (n !== l.minutes_late) void mark("retard", n);
  };

  return (
    <li>
      <Card className="gap-3 p-3 md:p-4" data-line={l.student_id}>
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
          <p className="font-bold">
            {name} <span className="font-mono text-xs font-normal text-muted-foreground">{l.student_id}</span>
          </p>
          {l.stale ? (
            <span className="text-xs text-muted-foreground">Bilan à jour au retour du réseau</span>
          ) : (
            (l.summary.state === "limit" || l.summary.state === "exceeded") && <StatusChip tone={st.tone}>{st.label}</StatusChip>
          )}
        </div>

        <div role="group" aria-label={`Pointage de ${name}`} className="grid grid-cols-3 gap-2">
          {CHOICES.map((c) => {
            const active = l.status === c.status;
            return (
              <button
                key={c.status}
                type="button"
                aria-pressed={active}
                onClick={() => void mark(active ? null : c.status, c.status === "retard" ? Number(mins) || 5 : null)}
                className={cn(
                  "min-h-12 rounded-lg border px-2 text-base font-bold transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                  active ? cn(c.on, "border-transparent") : "border-border bg-background hover:bg-muted",
                )}
              >
                {c.label}
              </button>
            );
          })}
        </div>

        {l.status === "retard" && (
          <div className="flex flex-wrap items-center gap-2">
            <Label htmlFor={`min-${l.student_id}`} className="shrink-0">
              Retard (minutes)
            </Label>
            <Input
              id={`min-${l.student_id}`}
              inputMode="numeric"
              value={mins}
              onChange={(e) => setMins(e.target.value)}
              onBlur={commitMinutes}
              onKeyDown={(e) => e.key === "Enter" && (e.currentTarget.blur(), undefined)}
              className="w-20"
            />
            {[5, 10, 15].map((m) => (
              <Button
                key={m}
                size="sm"
                variant={l.minutes_late === m ? "default" : "outline"}
                onClick={() => {
                  setTyped(null);
                  void mark("retard", m);
                }}
              >
                {m} min
              </Button>
            ))}
          </div>
        )}

        <p className={cn("text-sm font-semibold", l.category === "absence" ? "text-destructive" : l.category === "retard" ? "text-warning" : l.category === "present" ? "text-success" : "text-muted-foreground")}>{l.label}</p>

        {(l.status === "absent" || l.status === "retard") && <Justify line={l} session={session} />}
      </Card>
    </li>
  );
}

function Justify({ line: l, session }: { line: Line; session: number }) {
  const qc = useQueryClient();
  return (
    <div className="flex flex-wrap items-center gap-2">
      <Label htmlFor={`just-${l.student_id}`} className="shrink-0">
        Justificatif
      </Label>
      <select
        id={`just-${l.student_id}`}
        value={l.justification ?? ""}
        onChange={(e) => {
          const v = (e.target.value || null) as Justification | null;
          void justifyStudent(qc, session, l.student_id, v).catch((err) => toast.error("Justificatif non enregistré", { description: err instanceof ApiError ? err.detail : undefined }));
        }}
        className="h-11 rounded-lg border border-input bg-background px-3 text-base md:h-10 md:text-sm"
      >
        <option value="">Aucun</option>
        <option value="medical">Certificat médical</option>
        <option value="autre">Autre justificatif</option>
      </select>
      <span className="text-xs text-muted-foreground">Ne change pas le comptage : permet de refaire les tests manqués (§476).</span>
    </div>
  );
}

function SessionSettings({ data, id }: { data: SessionData; id: number }) {
  const qc = useQueryClient();
  const s = data.session;
  const [planned, setPlanned] = useState(s.planned_time ?? "");
  const [arrival, setArrival] = useState(s.teacher_arrival ?? "");
  const [warned, setWarned] = useState(s.warned);
  const [comment, setComment] = useState(s.comment ?? "");
  const [busy, setBusy] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    const p = planned === "" ? null : normalizeTime(planned);
    const a = arrival === "" ? null : normalizeTime(arrival);
    if ((planned !== "" && p === null) || (arrival !== "" && a === null)) return toast.error("Heure invalide", { description: "Indiquez les heures au format HH:MM." });
    setBusy(true);
    try {
      const res = await editSession(qc, id, { planned_time: p, teacher_arrival: a, warned, comment: comment.trim() === "" ? null : comment.trim() });
      toast.success(res.queued ? "Enregistré sur cet appareil" : "Séance enregistrée");
    } catch (err) {
      toast.error("Enregistrement impossible", { description: err instanceof ApiError ? err.detail : undefined });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="p-0">
      <details className="group">
        <summary className="flex min-h-12 cursor-pointer items-center gap-2 px-4 py-2 font-bold">
          Heures et message de la séance
          <ChevronDown className="ml-auto size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden />
        </summary>
        <form onSubmit={save} className="grid gap-3 border-t p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-1.5">
              <Label htmlFor="s-planned">Début prévu</Label>
              <Input id="s-planned" type="time" value={planned} onChange={(e) => setPlanned(e.target.value)} />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="s-arrival">Votre arrivée</Label>
              <Input id="s-arrival" type="time" value={arrival} onChange={(e) => setArrival(e.target.value)} />
            </div>
          </div>
          <Label htmlFor="s-warned" className="min-h-11 gap-3">
            <input id="s-warned" type="checkbox" checked={warned} onChange={(e) => setWarned(e.target.checked)} className="size-5 accent-[var(--primary)]" />
            J’ai prévenu les étudiants d’un retard (§474)
          </Label>
          <div className="grid gap-1.5">
            <Label htmlFor="s-comment">Commentaire (facultatif)</Label>
            <Input id="s-comment" value={comment} maxLength={255} onChange={(e) => setComment(e.target.value)} />
          </div>
          <p className="text-xs text-muted-foreground">Si vous arrivez plus de 10 minutes après l’heure prévue sans avoir prévenu, les absences et retards de cette séance ne comptent pas.</p>
          <div>
            <Button type="submit" disabled={busy}>
              Enregistrer la séance
            </Button>
          </div>
        </form>
      </details>
    </Card>
  );
}

function DeleteSession({ id, onDeleted, qcCtx, qc }: { id: number; onDeleted: () => void; qcCtx: { course: number; year: string; semester: number | null }; qc: ReturnType<typeof useQueryClient> }) {
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const timer = useRef<number | undefined>(undefined);

  useEffect(() => () => window.clearTimeout(timer.current), []);

  async function remove() {
    setBusy(true);
    try {
      await deleteSession(qc, id, qcCtx);
      toast.success("Séance supprimée");
      onDeleted();
    } catch (err) {
      toast.error("Suppression impossible", { description: err instanceof ApiError ? err.detail : undefined });
      setBusy(false);
    }
  }

  return (
    <div className="grid gap-2 pb-2">
      {!confirming ? (
        <Button
          variant="destructive"
          className="w-fit"
          onClick={() => {
            setConfirming(true);
            timer.current = window.setTimeout(() => setConfirming(false), 8000);
          }}
        >
          <Trash2 aria-hidden /> Supprimer cette séance
        </Button>
      ) : (
        <Card role="alertdialog" aria-label="Confirmer la suppression" className="gap-3 border-destructive p-4">
          <p className="text-sm">Cette séance et <strong>tous ses pointages</strong> seront supprimés. Les bilans de chaque étudiant seront recalculés.</p>
          <div className="flex flex-wrap gap-2">
            <Button variant="destructive" disabled={busy} onClick={remove}>
              Oui, supprimer la séance
            </Button>
            <Button variant="outline" onClick={() => setConfirming(false)}>
              Garder la séance
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}
