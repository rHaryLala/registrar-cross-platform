"use client";

import { useQueryClient } from "@tanstack/react-query";
import { ArrowDown, ArrowLeft, ArrowUp, ChevronDown, CircleAlert, CircleCheck, Lock, Plus, RotateCcw, Save, Send, Trash2, TriangleAlert } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useState, useSyncExternalStore } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { CopyBanner, LoadError, fullName } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { useSheet } from "@/hooks/use-teacher-queries";
import { ApiError } from "@/lib/api";
import { plural } from "@/lib/format";
import {
  formatCell,
  levelLabel,
  parseComponent,
  parseSpecial,
  parseWeight,
  STATUS_INFO,
  totalWeight,
  type CellField,
  type Component,
  type GradeLine,
  type Sheet,
  type SpecialCode,
} from "@/lib/grading";
import { saveCell, saveColumns, submitSheet, type ColumnDraft, type SheetCtx } from "@/lib/grading-actions";
import { parseSemester } from "@/lib/teacher-presence";
import { cn } from "@/lib/utils";

const subscribeOnline = (cb: () => void) => {
  window.addEventListener("online", cb);
  window.addEventListener("offline", cb);
  return () => {
    window.removeEventListener("online", cb);
    window.removeEventListener("offline", cb);
  };
};
const useOnline = () => useSyncExternalStore(subscribeOnline, () => navigator.onLine, () => true);

/** La feuille de notes d'un cours : pondération, une carte par étudiant (note calculée), contrôle, transmission. */
export function GradingSheet() {
  const params = useSearchParams();
  const course = Number(params.get("cours"));
  const year = params.get("annee") ?? "";
  const semester = parseSemester(params.get("semestre"));
  const q = useSheet(course, year, semester);
  const valid = Number.isInteger(course) && course > 0 && year !== "";

  return (
    <AppShell title="Feuille de notes">
      <div className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        <Link href="/notes/" className="inline-flex min-h-11 w-fit items-center gap-1.5 text-sm font-bold text-accent-text hover:underline">
          <ArrowLeft className="size-4" aria-hidden /> Mes feuilles
        </Link>
        {!valid && <LoadError error={new ApiError(404, "grading.not_found", "Feuille introuvable", "Adresse incomplète : ouvrez la feuille depuis la liste de vos cours.")} onRetry={() => undefined} what="La feuille" />}
        {valid && q.isPending && (
          <div className="grid gap-4" aria-busy="true" aria-label="Chargement de la feuille">
            <Skeleton className="h-20 w-full rounded-xl" />
            <Skeleton className="h-32 w-full rounded-xl" />
            <Skeleton className="h-48 w-full rounded-xl" />
          </div>
        )}
        {valid && q.isError && <LoadError error={q.error} onRetry={() => q.refetch()} what="La feuille" />}
        {valid && q.isSuccess && (
          <>
            <CopyBanner copyAt={q.data.copyAt} />
            <Content sheet={q.data.data} ctx={{ course, year, semester }} />
          </>
        )}
      </div>
    </AppShell>
  );
}

function Content({ sheet, ctx }: { sheet: Sheet; ctx: SheetCtx }) {
  const st = STATUS_INFO[sheet.remise.status];
  const editable = sheet.remise.editable;

  return (
    <>
      <div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="font-mono text-sm font-semibold text-accent-text">{sheet.course.code}</p>
          <StatusChip tone={st.tone}>{st.label}</StatusChip>
        </div>
        <h2 className="text-2xl font-extrabold tracking-tight">{sheet.course.title}</h2>
        <p className="text-sm text-muted-foreground">
          {sheet.year} · {sheet.semester_label} · {levelLabel(sheet.course.level)}{sheet.course.credits ? ` · ${sheet.course.credits} crédits` : ""} · {plural(sheet.lines.length, "étudiant")}
        </p>
      </div>

      <StatusBanner sheet={sheet} />

      {sheet.course.validation_course && (
        <Card className="p-3 text-sm text-muted-foreground">Ce cours se note en « validé » ou « échec » : à la validation, une note de 10 ou plus devient « validé ». Vous pouvez aussi choisir le statut V ou E pour un étudiant.</Card>
      )}

      <ColumnsCard sheet={sheet} ctx={ctx} />

      <section aria-labelledby="etudiants" className="grid gap-3">
        <h3 id="etudiants" className="text-lg font-extrabold">
          Étudiants
        </h3>
        {sheet.lines.length === 0 ? (
          <Card className="p-4 text-sm text-muted-foreground">Aucun étudiant inscrit à ce cours pour cette année et ce semestre.</Card>
        ) : (
          <ul className="grid gap-3">
            {sheet.lines.map((l) => (
              <StudentCard key={l.student_id} line={l} sheet={sheet} ctx={ctx} editable={editable} />
            ))}
          </ul>
        )}
      </section>

      <ControlCard sheet={sheet} ctx={ctx} />
      <Journal sheet={sheet} />
    </>
  );
}

function StatusBanner({ sheet }: { sheet: Sheet }) {
  const r = sheet.remise;
  const when = (iso: string | null) => (iso ? new Date(iso.replace(" ", "T")).toLocaleString("fr-FR", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "");
  if (r.status === "rejetee" && r.reject_reason) {
    return (
      <Card role="alert" className="flex-row items-start gap-3 border-destructive p-4">
        <RotateCcw className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden />
        <p className="text-sm leading-relaxed">
          <strong>Le registrariat vous a renvoyé cette feuille</strong>
          {r.processed_at ? ` le ${when(r.processed_at)}` : ""} :<br />
          <span className="whitespace-pre-line">{r.reject_reason}</span>
          <br />
          <span className="text-muted-foreground">Corrigez les lignes concernées, puis transmettez de nouveau.</span>
        </p>
      </Card>
    );
  }
  if (r.status === "soumise") {
    return (
      <Card role="status" className="flex-row items-start gap-3 border-warning p-4">
        <Lock className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
        <p className="text-sm leading-relaxed">Feuille transmise{r.submitted_at ? ` le ${when(r.submitted_at)}` : ""}, en attente du contrôle du registrariat. Elle est en lecture seule jusqu’à sa validation ou son renvoi.</p>
      </Card>
    );
  }
  if (r.status === "validee") {
    return (
      <Card role="status" className="flex-row items-start gap-3 border-success p-4">
        <CircleCheck className="mt-0.5 size-5 shrink-0 text-success" aria-hidden />
        <p className="text-sm leading-relaxed">Feuille validée par le registrariat{r.processed_at ? ` le ${when(r.processed_at)}` : ""}. Les notes figurent désormais dans le bulletin des étudiants.</p>
      </Card>
    );
  }
  return null;
}

function ColumnsCard({ sheet, ctx }: { sheet: Sheet; ctx: SheetCtx }) {
  // Le brouillon se recharge tout seul quand la feuille change côté serveur (clé = structure enregistrée).
  return <ColumnsEditor key={JSON.stringify(sheet.components)} sheet={sheet} ctx={ctx} />;
}

type Row = { key: string; slot: number | null; label: string; weight: string };

function ColumnsEditor({ sheet, ctx }: { sheet: Sheet; ctx: SheetCtx }) {
  const qc = useQueryClient();
  const online = useOnline();
  const editable = sheet.remise.editable;
  const toRows = (): Row[] => sheet.components.map((c) => ({ key: `s${c.slot}`, slot: c.slot, label: c.label, weight: formatTotal(c.weight) }));
  const [rows, setRows] = useState<Row[]>(toRows);
  const [busy, setBusy] = useState(false);
  const [counter, setCounter] = useState(0);
  const [open, setOpen] = useState(() => Math.abs(totalWeight(sheet.components) - 100) > 0.01);

  const drafts: ColumnDraft[] = rows.map((r) => ({ slot: r.slot, label: r.label.trim().replace(/\s+/g, " "), weight: parseWeight(r.weight) }));
  const total = totalWeight(drafts);
  const left = Math.round((100 - total) * 100) / 100;
  const ok = Math.abs(left) <= 0.01;
  const dirty = JSON.stringify(drafts.map((d) => [d.slot, d.label, d.weight])) !== JSON.stringify(sheet.components.map((c) => [c.slot, c.label, c.weight]));
  const labels = drafts.map((d) => d.label.toLowerCase());
  const problem = drafts.some((d) => d.label === "")
    ? "Chaque colonne doit avoir un titre."
    : drafts.some((d) => d.label.length > sheet.limits.max_label)
      ? `Un titre fait au plus ${sheet.limits.max_label} caractères.`
      : labels.some((l, i) => labels.indexOf(l) !== i)
        ? "Deux colonnes ont le même titre."
        : null;
  /** Notes déjà saisies qu'on perdrait en supprimant ces colonnes. */
  const lost = (slot: number | null) => (slot === null ? 0 : sheet.lines.filter((l) => l.values[String(slot)] !== null && l.values[String(slot)] !== undefined).length);

  const update = (key: string, patch: Partial<Row>) => setRows((rs) => rs.map((r) => (r.key === key ? { ...r, ...patch } : r)));
  const move = (i: number, d: -1 | 1) =>
    setRows((rs) => {
      const j = i + d;
      if (j < 0 || j >= rs.length) return rs;
      const next = [...rs];
      [next[i], next[j]] = [next[j], next[i]];
      return next;
    });

  async function save() {
    setBusy(true);
    const res = await saveColumns(qc, ctx, drafts);
    setBusy(false);
    if (res.ok) toast.success("Colonnes enregistrées");
    else toast.error("Colonnes non enregistrées", { description: res.message });
  }

  return (
    <Card className="p-0">
      <details open={open || dirty} onToggle={(e) => setOpen(e.currentTarget.open)} className="group">
        <summary className="grid cursor-pointer gap-1 p-4">
          <span className="flex flex-wrap items-baseline justify-between gap-2">
            <span className="flex items-center gap-1 text-lg font-extrabold">
              Colonnes et pondération
              <ChevronDown className="size-4 transition-transform group-open:rotate-180" aria-hidden />
            </span>
            <span aria-live="polite" className={cn("text-sm font-bold tabular-nums", ok ? "text-success" : "text-destructive")}>
              Total {formatTotal(total)} % {ok ? "" : left > 0 ? `— il reste ${formatTotal(left)} % à répartir` : `— ${formatTotal(-left)} % de trop`}
            </span>
          </span>
          <span className="text-xs text-muted-foreground">{sheet.components.map((c) => `${c.label} ${formatTotal(c.weight)} %`).join(" · ")}</span>
        </summary>
        <div className="grid gap-3 border-t p-4">
      <p className="text-sm text-muted-foreground">
        Vous choisissez les colonnes de la feuille : leur nombre (jusqu’à {sheet.limits.max_components}), leur titre et leur pondération. Seul le total est imposé : il doit faire 100 %. Une colonne à 0 % est ignorée.
      </p>
      <ul className="grid gap-2">
        {rows.map((r, i) => (
          <li key={r.key} className="grid grid-cols-[1fr_5.5rem] items-end gap-2 sm:grid-cols-[1fr_6rem_auto]">
            <div className="grid gap-1">
              <label htmlFor={`col-${r.key}`} className="text-xs font-semibold text-muted-foreground">
                Titre de la colonne {i + 1}
              </label>
              <Input id={`col-${r.key}`} value={r.label} maxLength={sheet.limits.max_label} disabled={!editable} onChange={(e) => update(r.key, { label: e.target.value })} placeholder="ex. Devoir 1" />
            </div>
            <div className="grid gap-1">
              <label htmlFor={`pct-${r.key}`} className="text-xs font-semibold text-muted-foreground">
                Poids (%)
              </label>
              <Input id={`pct-${r.key}`} inputMode="decimal" value={r.weight} disabled={!editable} onChange={(e) => update(r.key, { weight: e.target.value })} className="text-center tabular-nums" />
            </div>
            {editable && (
              <div className="col-span-2 flex items-center gap-1 sm:col-span-1">
                <Button variant="ghost" size="icon-sm" aria-label={`Monter la colonne ${r.label || i + 1}`} disabled={i === 0} onClick={() => move(i, -1)}>
                  <ArrowUp aria-hidden />
                </Button>
                <Button variant="ghost" size="icon-sm" aria-label={`Descendre la colonne ${r.label || i + 1}`} disabled={i === rows.length - 1} onClick={() => move(i, 1)}>
                  <ArrowDown aria-hidden />
                </Button>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  aria-label={`Supprimer la colonne ${r.label || i + 1}${lost(r.slot) ? ` (${lost(r.slot)} note(s) supprimée(s))` : ""}`}
                  disabled={rows.length <= 1}
                  onClick={() => setRows((rs) => rs.filter((x) => x.key !== r.key))}
                >
                  <Trash2 aria-hidden />
                </Button>
                {lost(r.slot) > 0 && <span className="text-xs text-warning">{lost(r.slot)} note(s)</span>}
              </div>
            )}
          </li>
        ))}
      </ul>
      {editable && (
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="outline"
            disabled={rows.length >= sheet.limits.max_components}
            onClick={() => {
              setCounter((n) => n + 1);
              setRows((rs) => [...rs, { key: `n${counter}`, slot: null, label: "", weight: "0" }]);
            }}
          >
            <Plus aria-hidden /> Ajouter une colonne
          </Button>
          <Button disabled={!dirty || busy || problem !== null || !online} onClick={() => void save()}>
            <Save aria-hidden /> Enregistrer les colonnes
          </Button>
          {dirty && (
            <Button variant="ghost" onClick={() => setRows(toRows())}>
              Annuler les changements
            </Button>
          )}
        </div>
      )}
      {editable && problem && <p className="text-sm text-destructive">{problem}</p>}
      {editable && dirty && rows.some((r) => lost(r.slot) > 0) === false && sheet.components.some((c) => !rows.some((r) => r.slot === c.slot) && lost(c.slot) > 0) && (
        <p className="text-sm text-warning">Supprimer une colonne supprime aussi les notes saisies dedans.</p>
      )}
        </div>
      </details>
      {editable && !online && <p className="border-t px-4 py-3 text-sm text-muted-foreground">Hors ligne : la saisie des notes fonctionne, mais modifier les colonnes demande le réseau.</p>}
    </Card>
  );
}

/** Une copie de l'objet sans cette clé. */
function without<T extends object>(obj: T, key: keyof T): T {
  const copy = { ...obj };
  delete copy[key];
  return copy;
}

const formatTotal = (n: number) => new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 2 }).format(n);

function noteText(l: GradeLine): { text: string; tone: string } {
  const n = l.note;
  if (n.state === "calculated" && n.value !== null) return { text: `${formatCell(n.value)} / 20`, tone: n.value >= 10 ? "text-success" : "text-destructive" };
  if (n.state === "special") return { text: n.detail === "V" ? "Validé (V)" : n.detail === "E" ? "Échec (E)" : "Pas encore noté (NN)", tone: n.detail === "E" ? "text-destructive" : n.detail === "V" ? "text-success" : "text-muted-foreground" };
  if (n.state === "incomplete") return { text: n.detail, tone: "text-warning" };
  return { text: "—", tone: "text-muted-foreground" };
}

function StudentCard({ line: l, sheet, ctx, editable }: { line: GradeLine; sheet: Sheet; ctx: SheetCtx; editable: boolean }) {
  const qc = useQueryClient();
  const name = fullName(l);
  const note = noteText(l);
  const [typed, setTyped] = useState<Record<string, string>>({});

  async function save(field: CellField, value: number | string | null, columnLabel?: string) {
    try {
      const res = await saveCell(qc, ctx, l.student_id, name, field, value, columnLabel);
      if (res.queued) toast("Enregistré sur cet appareil", { description: "Il partira au retour du réseau.", id: "queued-cell" });
    } catch (e) {
      toast.error(`Saisie de ${name} non enregistrée`, { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." });
    }
  }

  function commit(c: Component) {
    const key = String(c.slot);
    const raw = typed[key];
    if (raw === undefined) return;
    setTyped((t) => without(t, key));
    const v = parseComponent(raw);
    if (v === undefined) return toast.error("Note invalide", { description: "Indiquez une note entre 0 et 20 (la virgule est acceptée)." });
    if (v === (l.values[key] ?? null)) return;
    void save(`c${c.slot}`, v, c.label);
  }

  return (
    <li>
      <Card className="gap-3 p-3 md:p-4" data-student={l.student_id}>
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
          <p className="font-bold">
            {name} <span className="font-mono text-xs font-normal text-muted-foreground">{l.student_id}</span>
          </p>
          <div className="flex flex-wrap items-center gap-2">
            {l.attendance_exceeded && <StatusChip tone="bad">Absences &gt; 15 %</StatusChip>}
            {l.pending && <span className="text-xs font-semibold text-warning">à envoyer</span>}
          </div>
        </div>

        <div className="grid gap-2" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(4.5rem, 1fr))" }}>
          {sheet.components.map((c) => {
            const key = String(c.slot);
            const used = c.weight > 0;
            return (
              <div key={c.slot} className="grid min-w-0 gap-1">
                <label htmlFor={`${l.student_id}-${c.slot}`} className={cn("min-w-0 overflow-hidden text-xs font-semibold", used ? "text-muted-foreground" : "text-muted-foreground/50")} title={c.label}>
                  <span className="block truncate">{c.label}</span>
                  <span className="block font-normal">{used ? `${formatTotal(c.weight)} %` : "—"}</span>
                </label>
                <Input
                  id={`${l.student_id}-${c.slot}`}
                  inputMode="decimal"
                  enterKeyHint="next"
                  placeholder="—"
                  disabled={!editable || !used}
                  aria-label={`${name} — ${c.label}`}
                  value={typed[key] ?? formatCell(l.values[key] ?? null)}
                  onChange={(e) => setTyped((t) => ({ ...t, [key]: e.target.value }))}
                  onBlur={() => commit(c)}
                  onKeyDown={(e) => e.key === "Enter" && e.currentTarget.blur()}
                  className="px-1 text-center tabular-nums"
                />
              </div>
            );
          })}
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3">
          <output aria-live="polite" className={cn("text-lg font-extrabold tabular-nums", note.tone)}>
            {note.text}
          </output>
          <div className="flex items-center gap-2">
            <label htmlFor={`${l.student_id}-statut`} className="text-xs font-semibold text-muted-foreground">
              Statut
            </label>
            <select
              id={`${l.student_id}-statut`}
              disabled={!editable}
              value={l.statut_special ?? ""}
              title="V = validé sans note, E = échec sans note, NN = pas encore noté"
              onChange={(e) => void save("statut_special", parseSpecial(e.target.value) as SpecialCode | null)}
              className="h-11 rounded-lg border border-input bg-background px-2 text-base md:h-10 md:text-sm"
            >
              <option value="">—</option>
              <option value="V">V</option>
              <option value="E">E</option>
              <option value="NN">NN</option>
            </select>
          </div>
        </div>

        <details className="group">
          <summary className="flex min-h-11 cursor-pointer items-center gap-1 text-sm text-muted-foreground">
            Commentaire{l.commentaire ? " (1)" : ""}
            <ChevronDown className="size-4 transition-transform group-open:rotate-180" aria-hidden />
          </summary>
          <Input
            aria-label={`Commentaire pour ${name}`}
            disabled={!editable}
            maxLength={255}
            value={typed.commentaire ?? l.commentaire ?? ""}
            onChange={(e) => setTyped((t) => ({ ...t, commentaire: e.target.value }))}
            onBlur={() => {
              const raw = typed.commentaire;
              setTyped((t) => without(t, "commentaire"));
              if (raw !== undefined && raw.trim() !== (l.commentaire ?? "")) void save("commentaire", raw.trim() === "" ? null : raw.trim());
            }}
            onKeyDown={(e) => e.key === "Enter" && e.currentTarget.blur()}
          />
        </details>
      </Card>
    </li>
  );
}

function ControlCard({ sheet, ctx }: { sheet: Sheet; ctx: SheetCtx }) {
  const qc = useQueryClient();
  const online = useOnline();
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [serverCheck, setServerCheck] = useState<Sheet["check"] | null>(null);
  const c = serverCheck ?? sheet.check;
  const editable = sheet.remise.editable;
  const pending = sheet.lines.some((l) => l.pending);

  async function submit() {
    setBusy(true);
    const res = await submitSheet(qc, ctx);
    setBusy(false);
    setConfirming(false);
    if (res.ok) {
      setServerCheck(null);
      toast.success("Feuille transmise au registrariat", { description: "Elle est désormais en lecture seule." });
    } else {
      if (res.check) setServerCheck(res.check);
      toast.error("Transmission impossible", { description: res.message });
    }
  }

  return (
    <Card className="gap-3 p-4">
      <h3 className="text-lg font-extrabold">Contrôle avant transmission</h3>
      <p className="text-sm">
        <strong className="tabular-nums">
          {c.ready} / {c.total}
        </strong>{" "}
        notes prêtes.
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
      {c.warnings.length > 0 && (
        <ul className="grid gap-1.5">
          {c.warnings.map((w) => (
            <li key={w} className="flex items-start gap-2 text-sm text-warning">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden /> {w}
            </li>
          ))}
        </ul>
      )}
      {c.blockers.length === 0 && <p className="flex items-center gap-2 text-sm text-success"><CircleCheck className="size-4" aria-hidden /> Rien ne bloque la transmission.</p>}

      {editable && (
        <>
          {!confirming ? (
            <div className="grid gap-2">
              <div>
                <Button size="lg" disabled={c.blockers.length > 0 || !online || busy} onClick={() => setConfirming(true)}>
                  <Send aria-hidden /> Transmettre au registrariat
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                {!online ? "La transmission demande le réseau. " : pending ? "Des saisies attendent d’être envoyées : elles partiront avant la transmission. " : ""}
                Après transmission, la feuille passe en lecture seule jusqu’à la décision du registrariat.
              </p>
            </div>
          ) : (
            <Card role="alertdialog" aria-label="Confirmer la transmission" className="gap-3 border-warning p-4">
              <p className="text-sm">
                Transmettre cette feuille au registrariat ? Vous ne pourrez plus la modifier tant qu’il ne l’aura pas validée ou renvoyée.
              </p>
              <div className="flex flex-wrap gap-2">
                <Button disabled={busy} onClick={() => void submit()}>
                  Oui, transmettre
                </Button>
                <Button variant="outline" onClick={() => setConfirming(false)}>
                  Revenir à la feuille
                </Button>
              </div>
            </Card>
          )}
        </>
      )}
    </Card>
  );
}

const ACTION_LABEL: Record<string, string> = { creation: "a ouvert la feuille", ponderation: "a changé la pondération", soumission: "a transmis la feuille au registrariat", validation: "a validé la feuille", rejet: "a renvoyé la feuille", reouverture: "a rouvert la feuille", saisie: "a saisi" };

function Journal({ sheet }: { sheet: Sheet }) {
  return (
    <Card className="p-0">
      <details className="group">
        <summary className="flex min-h-12 cursor-pointer items-center gap-2 px-4 py-2 font-bold">
          Historique des modifications
          <ChevronDown className="ml-auto size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden />
        </summary>
        {sheet.journal.length === 0 ? (
          <p className="border-t p-4 text-sm text-muted-foreground">Aucune modification enregistrée.</p>
        ) : (
          <ul className="divide-y border-t">
            {sheet.journal.map((j, i) => (
              <li key={`${j.at}-${i}`} className="grid gap-0.5 px-4 py-2.5 text-sm">
                <span className="text-xs text-muted-foreground tabular-nums">{new Date(j.at.replace(" ", "T")).toLocaleString("fr-FR")}</span>
                <span>
                  <strong>{j.author ?? "Compte supprimé"}</strong> {ACTION_LABEL[j.action] ?? j.action}
                  {j.field && j.action !== "soumission" && j.action !== "validation" && j.action !== "rejet" ? <> <em>{j.field}</em></> : null}
                  {j.student_id ? <> pour <span className="font-mono text-xs">{j.student_id}</span></> : null}
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
