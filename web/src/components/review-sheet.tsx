"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight, ChevronDown, CircleAlert, CircleCheck, Hourglass, RotateCcw, TriangleAlert, Undo2 } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { LoadError, fullName } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { formatCell, STATUS_INFO } from "@/lib/grading";
import { gradeLabel, reviewKeys, secondsLeft, willChange, type Review, type ReviewLine } from "@/lib/review";
import { cn } from "@/lib/utils";

const BASE = "/api/v1/review/remises";

/** Une feuille transmise : le registrariat la contrôle, la valide (annulable 10 s) ou la renvoie avec un motif. */
export function ReviewSheet() {
  const id = Number(useSearchParams().get("id"));
  const valid = Number.isInteger(id) && id > 0;
  const q = useQuery({
    queryKey: reviewKeys.one(id),
    queryFn: async () => (await api<{ data: Review }>(`${BASE}/${id}`)).data,
    enabled: valid,
    retry: (n, e) => !(e instanceof ApiError && e.status >= 400 && e.status < 500) && n < 1,
  });

  return (
    <AppShell title="Contrôle d’une feuille">
      <div className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        <Link href="/validations/" className="inline-flex min-h-11 w-fit items-center gap-1.5 text-sm font-bold text-accent-text hover:underline">
          <ArrowLeft className="size-4" aria-hidden /> Feuilles à valider
        </Link>
        {!valid && <LoadError error={new ApiError(404, "review.not_found", "Feuille introuvable", "Adresse incomplète : ouvrez la feuille depuis la file de validation.")} onRetry={() => undefined} what="La feuille" />}
        {valid && q.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement de la feuille">
            <Skeleton className="h-20 w-full rounded-xl" />
            <Skeleton className="h-40 w-full rounded-xl" />
          </div>
        )}
        {valid && q.isError && <LoadError error={q.error} onRetry={() => q.refetch()} what="La feuille" />}
        {valid && q.isSuccess && <Content review={q.data} />}
      </div>
    </AppShell>
  );
}

function Content({ review: r }: { review: Review }) {
  const st = STATUS_INFO[r.status];
  const when = (iso: string | null) => (iso ? new Date(iso.replace(" ", "T")).toLocaleString("fr-FR", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "");

  return (
    <>
      <div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="font-mono text-sm font-semibold text-accent-text">{r.course.code}</p>
          <StatusChip tone={st.tone}>{st.label}</StatusChip>
        </div>
        <h2 className="text-2xl font-extrabold tracking-tight">{r.course.title}</h2>
        <p className="text-sm text-muted-foreground">
          {r.course.teacher ?? "Enseignant non indiqué"} · {r.year}
          {r.semester ? ` · Semestre ${r.semester}` : ""}
          {r.submitted_at ? ` · transmise le ${when(r.submitted_at)}` : ""}
        </p>
      </div>

      {r.status === "validee" && (
        <Card role="status" className="flex-row items-start gap-3 border-success p-4">
          <CircleCheck className="mt-0.5 size-5 shrink-0 text-success" aria-hidden />
          <p className="text-sm">Feuille validée{r.processed_at ? ` le ${when(r.processed_at)}` : ""} : les notes figurent dans le bulletin des étudiants.</p>
        </Card>
      )}
      {r.status === "rejetee" && (
        <Card role="status" className="flex-row items-start gap-3 border-destructive p-4">
          <RotateCcw className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden />
          <p className="text-sm">
            Renvoyée à l’enseignant{r.processed_at ? ` le ${when(r.processed_at)}` : ""} :<br />
            <span className="whitespace-pre-line">{r.reject_reason}</span>
          </p>
        </Card>
      )}
      {r.status === "brouillon" && <Card className="p-4 text-sm text-muted-foreground">Cette feuille est encore un brouillon : l’enseignant ne l’a pas transmise. Vous la voyez en lecture seule.</Card>}

      <Controls review={r} />

      <section aria-labelledby="etudiants" className="grid gap-3">
        <h3 id="etudiants" className="text-lg font-extrabold">
          Étudiants
        </h3>
        <ul className="grid gap-3">
          {r.lines.map((l) => (
            <LineCard key={l.student_id} line={l} review={r} />
          ))}
        </ul>
      </section>

      <Journal review={r} />
    </>
  );
}

/** Contrôle, validation (avec compte à rebours d'annulation) et renvoi. */
function Controls({ review: r }: { review: Review }) {
  const qc = useQueryClient();
  const [deadline, setDeadline] = useState<number | null>(() => (r.pending ? Date.now() + r.pending.remaining_ms : null));
  const [now, setNow] = useState(() => Date.now());
  const [busy, setBusy] = useState(false);
  const [returning, setReturning] = useState(false);
  const [reason, setReason] = useState("");
  const finalizing = useRef(false);
  const c = r.check;

  const refresh = () => qc.invalidateQueries({ queryKey: ["review"] });

  // L'écran attend la fin du délai d'annulation, puis demande la finalisation (le serveur la ferait aussi tout seul si l'onglet se fermait).
  useEffect(() => {
    if (deadline === null) return;
    const t = window.setInterval(() => setNow(Date.now()), 250);
    return () => window.clearInterval(t);
  }, [deadline]);

  const remaining = deadline === null ? null : deadline - now;
  useEffect(() => {
    if (deadline === null || remaining === null || remaining > 0 || finalizing.current) return;
    finalizing.current = true;
    void (async () => {
      try {
        const res = await api<{ data: { written: number | null; already: boolean } }>(`${BASE}/${r.id}/finalize`, { method: "POST" });
        setDeadline(null);
        toast.success("Feuille validée", { description: res.data.written === null ? undefined : `${res.data.written} note(s) intégrée(s) au bulletin.` });
        await refresh();
      } catch (e) {
        if (e instanceof ApiError && e.code === "review.not_due") {
          setDeadline(Date.now() + Number(e.extra?.remaining_ms ?? 1000)); // l'horloge du serveur fait foi
        } else {
          setDeadline(null);
          toast.error("Validation impossible", { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." });
          await refresh();
        }
      } finally {
        finalizing.current = false;
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [remaining === null || remaining <= 0, deadline]);

  async function approve() {
    setBusy(true);
    try {
      const res = await api<{ data: { remaining_ms: number } }>(`${BASE}/${r.id}/approve`, { method: "POST" });
      setNow(Date.now());
      setDeadline(Date.now() + res.data.remaining_ms);
    } catch (e) {
      toast.error("Validation refusée", { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." });
      await refresh();
    } finally {
      setBusy(false);
    }
  }

  async function undo() {
    setBusy(true);
    try {
      await api(`${BASE}/${r.id}/undo`, { method: "POST" });
      setDeadline(null);
      toast("Validation annulée", { description: "Aucune note n’a été écrite." });
      await refresh();
    } catch (e) {
      toast.error("Annulation impossible", { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." });
      await refresh();
    } finally {
      setBusy(false);
    }
  }

  async function sendBack() {
    if (reason.trim() === "") return;
    setBusy(true);
    try {
      await api(`${BASE}/${r.id}/return`, { method: "POST", body: { reason: reason.trim() } });
      setReturning(false);
      setReason("");
      toast.success("Feuille renvoyée à l’enseignant");
      await refresh();
    } catch (e) {
      toast.error("Renvoi impossible", { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." });
    } finally {
      setBusy(false);
    }
  }

  const submitted = r.status === "soumise";

  return (
    <Card className="gap-3 p-4">
      <h3 className="text-lg font-extrabold">Contrôle</h3>
      <p className="text-sm">
        <strong className="tabular-nums">
          {c.ready} / {c.total}
        </strong>{" "}
        notes prêtes · <strong className="tabular-nums">{r.changes}</strong> note(s) changeront dans le bulletin.
      </p>
      {c.blockers.length > 0 && (
        <ul role="alert" className="grid gap-1.5">
          {c.blockers.map((b) => (
            <li key={b} className="flex items-start gap-2 text-sm text-destructive">
              <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden /> {b}
            </li>
          ))}
        </ul>
      )}
      {c.warnings.map((w) => (
        <p key={w} className="flex items-start gap-2 text-sm text-warning">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden /> {w}
        </p>
      ))}
      {c.blockers.length === 0 && (
        <p className="flex items-center gap-2 text-sm text-success">
          <CircleCheck className="size-4" aria-hidden /> Rien ne bloque la validation.
        </p>
      )}

      {submitted && deadline !== null && remaining !== null && (
        <Card role="status" aria-live="polite" className="gap-3 border-warning p-4">
          <p className="flex items-center gap-2 text-sm font-bold">
            <Hourglass className="size-4 text-warning" aria-hidden />
            {remaining > 0 ? `Validation dans ${secondsLeft(remaining)} s` : "Validation en cours…"}
          </p>
          <p className="text-sm text-muted-foreground">Aucune note n’est encore écrite. Vous pouvez annuler tant que ce délai n’est pas écoulé.</p>
          <div>
            <Button variant="outline" size="lg" disabled={busy || remaining <= 0} onClick={() => void undo()}>
              <Undo2 aria-hidden /> Annuler la validation
            </Button>
          </div>
        </Card>
      )}

      {submitted && deadline === null && !returning && (
        <div className="flex flex-wrap gap-2">
          <Button size="lg" disabled={!r.can_approve || busy} onClick={() => void approve()}>
            <CircleCheck aria-hidden /> Valider la feuille
          </Button>
          <Button size="lg" variant="outline" disabled={busy} onClick={() => setReturning(true)}>
            <RotateCcw aria-hidden /> Renvoyer à l’enseignant
          </Button>
        </div>
      )}
      {submitted && deadline === null && !returning && r.can_approve && (
        <p className="text-xs text-muted-foreground">
          Valider écrit les notes dans l’historique et le bulletin des étudiants, après un délai de {r.undo_seconds} secondes pendant lequel vous pouvez annuler.
        </p>
      )}

      {submitted && returning && (
        <Card role="group" aria-label="Renvoyer la feuille" className="gap-3 p-4">
          <label htmlFor="reason" className="text-sm font-bold">
            Ce que l’enseignant doit corriger
          </label>
          <textarea
            id="reason"
            value={reason}
            maxLength={2000}
            rows={3}
            onChange={(e) => setReason(e.target.value)}
            className="w-full rounded-lg border border-input bg-background p-3 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:text-sm"
            placeholder="Indiquez les lignes ou les notes concernées."
          />
          <div className="flex flex-wrap gap-2">
            <Button disabled={busy || reason.trim() === ""} onClick={() => void sendBack()}>
              Renvoyer la feuille
            </Button>
            <Button variant="outline" onClick={() => setReturning(false)}>
              Revenir à la feuille
            </Button>
          </div>
          {reason.trim() === "" && <p className="text-xs text-muted-foreground">Le motif est obligatoire : l’enseignant le lira en rouvrant sa feuille.</p>}
        </Card>
      )}
    </Card>
  );
}

function LineCard({ line: l, review: r }: { line: ReviewLine; review: Review }) {
  const change = willChange(l);
  const n = l.note;
  const note =
    n.state === "calculated" && n.value !== null
      ? { text: `${formatCell(n.value)} / 20`, tone: n.value >= 10 ? "text-success" : "text-destructive" }
      : n.state === "special"
        ? { text: `Statut ${n.detail}`, tone: "text-accent-text" }
        : { text: n.detail, tone: n.state === "incomplete" ? "text-warning" : "text-muted-foreground" };

  return (
    <li>
      <Card className="gap-2 p-3 md:p-4" data-student={l.student_id}>
        <div className="flex flex-wrap items-baseline justify-between gap-x-3">
          <p className="font-bold">
            {fullName(l)} <span className="font-mono text-xs font-normal text-muted-foreground">{l.student_id}</span>
          </p>
          <output className={cn("text-lg font-extrabold tabular-nums", note.tone)}>{note.text}</output>
        </div>
        {l.statut_special === null && (
          <dl className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
            {r.components.map((c) => (
              <div key={c.slot} className={cn("flex gap-1.5", c.weight <= 0 && "opacity-50")}>
                <dt className="text-muted-foreground">
                  {c.label} <span className="text-xs">({formatCell(c.weight)} %)</span>
                </dt>
                <dd className="font-semibold tabular-nums">{formatCell(l.values[String(c.slot)] ?? null) || "—"}</dd>
              </div>
            ))}
          </dl>
        )}
        {l.commentaire && <p className="text-sm text-muted-foreground">« {l.commentaire} »</p>}
        <p className="flex flex-wrap items-center gap-2 text-sm">
          {!l.linked ? (
            <span className="text-warning">Aucune inscription liée : rien ne sera écrit pour cet étudiant.</span>
          ) : (
            <>
              <span className="text-muted-foreground">En base :</span>
              <strong>{gradeLabel(l.current_grade)}</strong>
              <ArrowRight className="size-4 text-muted-foreground" aria-hidden />
              <strong className={cn(change && "text-accent-text")}>{gradeLabel(l.new_grade)}</strong>
              {!change && <span className="text-xs text-muted-foreground">(inchangé)</span>}
            </>
          )}
        </p>
      </Card>
    </li>
  );
}

const ACTION: Record<string, string> = { creation: "a ouvert la feuille", ponderation: "a changé les colonnes", soumission: "a transmis la feuille", validation: "a validé la feuille", rejet: "a renvoyé la feuille", reouverture: "a rouvert la feuille", saisie: "a saisi" };

function Journal({ review: r }: { review: Review }) {
  return (
    <Card className="p-0">
      <details className="group">
        <summary className="flex min-h-12 cursor-pointer items-center gap-2 px-4 py-2 font-bold">
          Historique des modifications
          <ChevronDown className="ml-auto size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden />
        </summary>
        {r.journal.length === 0 ? (
          <p className="border-t p-4 text-sm text-muted-foreground">Aucune modification enregistrée.</p>
        ) : (
          <ul className="divide-y border-t">
            {r.journal.map((j, i) => (
              <li key={`${j.at}-${i}`} className="grid gap-0.5 px-4 py-2.5 text-sm">
                <span className="text-xs text-muted-foreground tabular-nums">{new Date(j.at.replace(" ", "T")).toLocaleString("fr-FR")}</span>
                <span>
                  <strong>{j.author ?? "Compte supprimé"}</strong> {ACTION[j.action] ?? j.action}
                  {j.field && j.action === "saisie" ? (
                    <>
                      {" "}
                      <em>{j.field}</em>
                    </>
                  ) : null}
                  {j.student_id ? (
                    <>
                      {" "}
                      pour <span className="font-mono text-xs">{j.student_id}</span>
                    </>
                  ) : null}
                  {(j.old !== null || j.new !== null) && j.action !== "creation" && (
                    <span className="ml-1">
                      {j.old !== null && <span className="text-destructive line-through">{j.old}</span>}
                      {j.old !== null && " → "}
                      <span className="text-success">{j.new ?? "vide"}</span>
                    </span>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
      </details>
    </Card>
  );
}
