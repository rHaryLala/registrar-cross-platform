"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, ClipboardPlus, Pencil, Plus, Power, Undo2 } from "lucide-react";
import { useCallback, useState } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { LoadError } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import {
  adjustedText, cellKey, COPY_URL, dirtyCount, draftErrors, EMPTY_DRAFT, FEES_URL, feeUrl, modifiedText, RESET_URL, SAVE_URL, savePayload, semestersOf, semestersText, shownExtra, shownText,
  stateText, tariffKeys, tariffUrl, withAmount, withExtra,
  type Draft, type Fee, type MentionCard, type SaveResult, type TariffPage,
} from "@/lib/tariff";
import { cn } from "@/lib/utils";

function problem(err: unknown): string | undefined {
  return err instanceof ApiError ? (err.isOffline ? "Pas de réseau : rien n’a été modifié. Réessayez avec une connexion." : err.detail) : undefined;
}

const TONE = { alert: "text-destructive", own: "text-success", base: "text-muted-foreground" } as const;

/** Barème financier par session : les tarifs de chaque filière, niveau et statut, ajustables à chaque semestre ; une session sans tarif propre hérite du barème de base. */
export function TariffEditor() {
  const [year, setYear] = useState<string | null>(null);
  const [semester, setSemester] = useState<number | null>(null);
  const [base, setBase] = useState(false);
  const [dirty, setDirty] = useState(0);

  const query = useQuery({
    queryKey: tariffKeys.one(year, semester, base),
    queryFn: async () => (await api<{ data: TariffPage }>(tariffUrl(year, semester, base))).data,
    retry: 1,
  });
  const page = query.data;

  const move = (change: () => void) => {
    if (dirty > 0 && !window.confirm("Des montants ne sont pas enregistrés : les abandonner et changer de période ?")) return;
    change();
  };

  return (
    <AppShell title="Barème financier">
      <div className="mx-auto grid w-full max-w-4xl gap-4 p-4 md:p-6">
        <p className="text-sm text-muted-foreground">Les tarifs de chaque filière, par niveau et statut. Ajustez une session : elle reçoit son propre tarif ; sans ajustement, elle suit le barème de base.</p>

        {page && (
          <Card className="gap-3 p-4">
            <label className="grid gap-1 text-sm font-semibold">
              Année scolaire
              <select
                value={page.year}
                onChange={(e) => move(() => { setYear(e.target.value); setSemester(null); setBase(false); })}
                className="h-12 rounded-xl border border-input bg-background px-3 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {page.years.map((y) => (
                  <option key={y} value={y}>
                    {y}
                  </option>
                ))}
              </select>
            </label>
            <div role="tablist" aria-label="Session" className="flex flex-wrap gap-2">
              {page.sessions.map((s) => (
                <Button key={s.id} role="tab" aria-selected={!page.editing_base && s.semester === page.semester} variant={!page.editing_base && s.semester === page.semester ? "default" : "outline"} onClick={() => move(() => { setSemester(s.semester); setBase(false); })}>
                  {s.name}
                </Button>
              ))}
              <Button role="tab" aria-selected={page.editing_base} variant={page.editing_base ? "default" : "outline"} title="Tarifs appliqués à toute session sans barème propre" onClick={() => move(() => { setSemester(page.semester); setBase(true); })}>
                Barème de base
              </Button>
            </div>
            <p className="text-sm font-semibold text-muted-foreground">
              {page.editing_base ? "Le barème de base sert de repli à toutes les sessions." : page.session ? adjustedText(page.adjusted_count, page.mention_count) : `Aucune session au catalogue pour ${page.year}.`}
            </p>
          </Card>
        )}

        {query.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement du barème">
            <Skeleton className="h-28 w-full rounded-xl" />
            <Skeleton className="h-40 w-full rounded-xl" />
          </div>
        )}
        {query.isError && <LoadError error={query.error} onRetry={() => void query.refetch()} what="Le barème" />}
        {page && <Board key={`${page.target}:${page.semester}:${page.editing_base}`} page={page} onDirty={setDirty} />}
      </div>
    </AppShell>
  );
}

function Board({ page, onDirty }: { page: TariffPage; onDirty: (n: number) => void }) {
  const qc = useQueryClient();
  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
  const refresh = useCallback(() => qc.invalidateQueries({ queryKey: tariffKeys.root }), [qc]);

  const setDraft = (sigle: string, next: Draft) =>
    setDrafts((all) => {
      const merged = { ...all, [sigle]: next };
      if (dirtyCount(next) === 0) delete merged[sigle];
      onDirty(Object.values(merged).reduce((n, d) => n + dirtyCount(d), 0));
      return merged;
    });
  const clear = (sigles: string[]) =>
    setDrafts((all) => {
      const merged = { ...all };
      for (const s of sigles) delete merged[s];
      onDirty(Object.values(merged).reduce((n, d) => n + dirtyCount(d), 0));
      return merged;
    });

  const save = useMutation({
    mutationFn: async (v: { sigle: string; others: string[]; draft: Draft }) =>
      (await api<{ data: SaveResult }>(SAVE_URL, { method: "PUT", body: savePayload(v.draft, page.target, v.sigle, v.others, page.semester) })).data,
    onSuccess: async (r) => {
      toast.success(r.message);
      clear(r.mentions);
      await refresh();
    },
    onError: (e) => toast.error("Barème non enregistré", { description: problem(e) }),
  });
  const act = useMutation({
    mutationFn: async (v: { url: string; body: Record<string, unknown> }) => (await api<{ data: { message: string } }>(v.url, { method: "POST", body: v.body })).data,
    onSuccess: async (r, v) => {
      toast.success(r.message);
      if (typeof v.body.mention === "string") clear([v.body.mention]);
      await refresh();
    },
    onError: (e) => toast.error("Action impossible", { description: problem(e) }),
  });

  if (!page.session && !page.editing_base) return null;
  return (
    <>
      <ExtraFees page={page} />
      {page.mentions.map((m) => (
        <MentionCardView
          key={m.sigle}
          page={page}
          card={m}
          draft={drafts[m.sigle] ?? EMPTY_DRAFT}
          busy={save.isPending || act.isPending}
          onDraft={(d) => setDraft(m.sigle, d)}
          onSave={(others) => save.mutate({ sigle: m.sigle, others, draft: drafts[m.sigle] ?? EMPTY_DRAFT })}
          onCopy={() => act.mutate({ url: COPY_URL, body: { session_id: page.target, source_session_id: 0, mention: m.sigle } })}
          onReset={() => {
            if (window.confirm(`Supprimer le tarif propre de ${m.label} pour cette session ? La filière reviendra au barème de base.`)) act.mutate({ url: RESET_URL, body: { session_id: page.target, mention: m.sigle } });
          }}
        />
      ))}
    </>
  );
}

function MentionCardView({
  page, card, draft, busy, onDraft, onSave, onCopy, onReset,
}: {
  page: TariffPage;
  card: MentionCard;
  draft: Draft;
  busy: boolean;
  onDraft: (d: Draft) => void;
  onSave: (others: string[]) => void;
  onCopy: () => void;
  onReset: () => void;
}) {
  const [level, setLevel] = useState(1);
  const [others, setOthers] = useState<string[]>([]);
  const changes = dirtyCount(draft);
  const problems = draftErrors(draft);
  const blocked = Object.keys(problems).length > 0;
  const state = stateText(card, page.editing_base);
  const feeIds = Object.keys(card.extra).map(Number);
  const fees = feeIds.map((id) => page.extra_fees.find((f) => f.id === id)).filter((f): f is Fee => f !== undefined);
  const otherMentions = page.mentions.filter((m) => m.sigle !== card.sigle);

  return (
    <Card className="gap-3 p-4">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="text-lg font-extrabold tracking-tight">{card.label}</h2>
        {state && <span className={cn("text-xs font-bold", TONE[state.tone])}>{state.text}</span>}
        {changes > 0 && <span className="text-xs font-bold text-warning">{modifiedText(changes)}</span>}
        {!page.editing_base && (
          <div className="ml-auto flex flex-wrap gap-2">
            <Button variant="outline" size="sm" disabled={busy} onClick={onCopy} title="Crée le tarif propre de cette session en repartant du barème de base">
              <ClipboardPlus aria-hidden /> Reprendre la base
            </Button>
            {card.adjusted && (
              <Button variant="destructive" size="sm" disabled={busy} onClick={onReset} title="Supprime le tarif propre : la filière revient au barème de base">
                <Undo2 aria-hidden /> Revenir à la base
              </Button>
            )}
          </div>
        )}
      </div>

      <div role="tablist" aria-label={`Niveau — ${card.label}`} className="flex gap-2 overflow-x-auto pb-1">
        {page.levels.map((l) => (
          <Button key={l.level} role="tab" aria-selected={l.level === level} size="sm" variant={l.level === level ? "default" : "outline"} onClick={() => setLevel(l.level)} className="shrink-0">
            {l.label}
          </Button>
        ))}
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[19rem] text-sm">
          <thead>
            <tr className="text-left text-xs text-muted-foreground">
              <th scope="col" className="py-1 pr-2 font-bold">
                Montant (Ar)
              </th>
              {page.statuses.map((s) => (
                <th key={s} scope="col" className="px-1 py-1 font-bold">
                  {s}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {card.fields.map((f) => (
              <tr key={f.column} className="border-t">
                <th scope="row" className="py-1.5 pr-2 text-left font-semibold">
                  {f.label}
                </th>
                {page.statuses.map((s) => {
                  const key = cellKey(level, s);
                  const cell = card.grid[key];
                  const typed = draft.amounts[key]?.[f.column] !== undefined;
                  const err = problems[`${key}|${f.column}`];
                  return (
                    <td key={s} className="px-1 py-1">
                      <input
                        inputMode="decimal"
                        autoComplete="off"
                        value={shownText(draft, card, key, f.column)}
                        onChange={(e) => onDraft(withAmount(draft, card, key, f.column, e.target.value))}
                        aria-label={`${f.label} — ${s}`}
                        aria-invalid={err !== undefined}
                        placeholder={cell?.source === "none" ? "—" : undefined}
                        className={cn(
                          "h-11 w-full min-w-24 rounded-lg border bg-background px-2 text-right font-mono outline-none focus-visible:ring-2 focus-visible:ring-ring md:h-10",
                          typed ? "border-warning" : cell?.source === "base" && !page.editing_base ? "border-dashed text-muted-foreground" : "border-input",
                          err && "border-destructive",
                        )}
                      />
                    </td>
                  );
                })}
              </tr>
            ))}
            {fees.map((f) => (
              <tr key={`fee-${f.id}`} className="border-t">
                <th scope="row" className="py-1.5 pr-2 text-left font-semibold">
                  {f.label} <span className="font-normal text-muted-foreground">(frais supplémentaire)</span>
                </th>
                {page.statuses.map((s) => {
                  const key = cellKey(level, s);
                  const typed = draft.extra[String(f.id)]?.[key] !== undefined;
                  const err = problems[`extra|${f.id}|${key}`];
                  const inherited = card.extra[String(f.id)]?.[key]?.inherited && !typed;
                  return (
                    <td key={s} className="px-1 py-1">
                      <input
                        inputMode="decimal"
                        autoComplete="off"
                        value={shownExtra(draft, card, f.id, key)}
                        onChange={(e) => onDraft(withExtra(draft, card, f.id, key, e.target.value))}
                        aria-label={`${f.label} — ${s}`}
                        aria-invalid={err !== undefined}
                        title="Case vide : le frais suit le barème de base ; 0 : rien à payer ici"
                        className={cn(
                          "h-11 w-full min-w-24 rounded-lg border bg-background px-2 text-right font-mono outline-none focus-visible:ring-2 focus-visible:ring-ring md:h-10",
                          typed ? "border-warning" : inherited ? "border-dashed text-muted-foreground" : "border-input",
                          err && "border-destructive",
                        )}
                      />
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {blocked && (
        <p role="alert" className="text-sm font-semibold text-destructive">
          Corrigez les montants entourés en rouge : un nombre positif ou nul est attendu.
        </p>
      )}

      {changes > 0 && (
        <div className="grid gap-2 rounded-lg bg-accent/60 p-3">
          {otherMentions.length > 0 && (
            <details>
              <summary className="min-h-9 cursor-pointer text-sm font-semibold">Appliquer aussi à d’autres filières{others.length > 0 ? ` (${others.length})` : ""}</summary>
              <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                {otherMentions.map((m) => (
                  <label key={m.sigle} className="flex min-h-9 items-center gap-2 text-sm">
                    <input type="checkbox" checked={others.includes(m.sigle)} onChange={() => setOthers((o) => (o.includes(m.sigle) ? o.filter((x) => x !== m.sigle) : [...o, m.sigle]))} className="size-5 accent-[var(--primary)]" />
                    {m.label}
                  </label>
                ))}
              </div>
              <p className="mt-1 text-xs text-muted-foreground">Les mêmes montants modifiés sont écrits pour ces filières, en une seule fois.</p>
            </details>
          )}
          <div className="flex flex-wrap items-center gap-2">
            <Button
              disabled={busy || blocked}
              onClick={() => {
                if (others.length > 0 && !window.confirm(`Appliquer ces montants à ${others.length} autre${others.length > 1 ? "s" : ""} filière${others.length > 1 ? "s" : ""} ? Leurs montants correspondants seront remplacés.`)) return;
                onSave(others);
                setOthers([]);
              }}
            >
              <Check aria-hidden /> {busy ? "Enregistrement…" : others.length > 0 ? "Enregistrer et appliquer" : "Enregistrer"}
            </Button>
            <Button variant="outline" disabled={busy} onClick={() => onDraft(EMPTY_DRAFT)}>
              Annuler les changements
            </Button>
          </div>
        </div>
      )}
    </Card>
  );
}

function ExtraFees({ page }: { page: TariffPage }) {
  const qc = useQueryClient();
  const [label, setLabel] = useState("");
  const refresh = () => qc.invalidateQueries({ queryKey: tariffKeys.root });
  const create = useMutation({
    mutationFn: async () => (await api<{ data: { message: string } }>(FEES_URL, { method: "POST", body: { label } })).data,
    onSuccess: async (r) => {
      toast.success(r.message);
      setLabel("");
      await refresh();
    },
    onError: (e) => toast.error("Frais non ajouté", { description: problem(e) }),
  });
  if (!page.extra_fees_installed) {
    return (
      <p role="alert" className="rounded-xl border border-destructive p-3 text-sm font-semibold text-destructive">
        Les tables des frais supplémentaires n’existent pas encore sur ce serveur.
      </p>
    );
  }
  return (
    <details className="rounded-xl bg-card ring-1 ring-foreground/10">
      <summary className="flex min-h-12 cursor-pointer items-center gap-2 p-4 font-extrabold">
        <Plus className="size-4" aria-hidden /> Frais supplémentaires <span className="font-normal text-muted-foreground">({page.extra_fees.filter((f) => f.active).length} actif{page.extra_fees.filter((f) => f.active).length > 1 ? "s" : ""})</span>
      </summary>
      <div className="grid gap-3 p-4 pt-0">
        <p className="text-sm text-muted-foreground">Un frais ajouté ici devient une ligne de chaque filière, saisissable par niveau et statut, et figure sur la fiche d’inscription de l’étudiant que le barème désigne.</p>
        <form
          className="flex flex-wrap gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (label.trim() !== "") create.mutate();
          }}
        >
          <input
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            maxLength={120}
            placeholder="Nom du frais (ex. Alliance française)"
            aria-label="Nom du frais à ajouter"
            className="h-12 min-w-0 flex-1 rounded-lg border border-input bg-background px-3 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
          <Button type="submit" disabled={create.isPending || label.trim() === ""}>
            <Plus aria-hidden /> Ajouter
          </Button>
        </form>
        <ul className="grid gap-2">
          {page.extra_fees.map((f) => (
            <FeeRow key={`${f.id}:${f.label}:${f.active}:${f.condition}:${f.semesters}`} fee={f} conditions={page.extra_fee_conditions} onDone={refresh} />
          ))}
        </ul>
      </div>
    </details>
  );
}

function FeeRow({ fee, conditions, onDone }: { fee: Fee; conditions: Record<string, string>; onDone: () => Promise<void> }) {
  const [condition, setCondition] = useState(fee.condition);
  const [semesters, setSemesters] = useState<number[]>(semestersOf(fee.semesters));
  const changed = condition !== fee.condition || semestersText(semesters) !== fee.semesters;
  const patch = useMutation({
    mutationFn: async (body: Record<string, unknown>) => (await api<{ data: { message: string } }>(feeUrl(fee.id), { method: "PATCH", body })).data,
    onSuccess: async (r) => {
      toast.success(r.message);
      await onDone();
    },
    onError: (e) => toast.error("Frais non modifié", { description: problem(e) }),
  });
  return (
    <li className={cn("grid gap-2 rounded-lg border p-3", !fee.active && "opacity-60")}>
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-bold">{fee.label}</span>
        {!fee.active && <em className="text-xs text-muted-foreground">désactivé</em>}
        <div className="ml-auto flex gap-1">
          <Button
            variant="ghost"
            size="icon"
            aria-label={`Renommer le frais ${fee.label}`}
            onClick={() => {
              const next = window.prompt("Nouveau nom du frais (les inscriptions déjà enregistrées gardent l’ancien nom) :", fee.label);
              if (next !== null && next.trim() !== "" && next !== fee.label) patch.mutate({ label: next });
            }}
          >
            <Pencil aria-hidden />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label={fee.active ? `Désactiver le frais ${fee.label}` : `Réactiver le frais ${fee.label}`}
            onClick={() => {
              if (fee.active && !window.confirm("Désactiver ce frais ? Il cesse d’être facturé ; les montants et l’historique sont conservés.")) return;
              patch.mutate({ active: !fee.active });
            }}
          >
            <Power aria-hidden />
          </Button>
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <label className="grid gap-0.5 text-xs font-bold">
          Facturé à
          <select value={condition} onChange={(e) => setCondition(e.target.value)} className="h-10 rounded-lg border border-input bg-background px-2 text-sm font-normal">
            {Object.entries(conditions).map(([code, text]) => (
              <option key={code} value={code}>
                {text}
              </option>
            ))}
          </select>
        </label>
        <fieldset className="flex flex-wrap items-center gap-x-3">
          <legend className="text-xs font-bold">Semestres (aucune case : tous)</legend>
          {[1, 2, 3, 4].map((s) => (
            <label key={s} className="flex min-h-9 items-center gap-1.5 text-sm">
              <input type="checkbox" checked={semesters.includes(s)} onChange={() => setSemesters((cur) => (cur.includes(s) ? cur.filter((x) => x !== s) : [...cur, s]))} className="size-5 accent-[var(--primary)]" />S{s}
            </label>
          ))}
        </fieldset>
        <Button size="sm" disabled={!changed || patch.isPending} onClick={() => patch.mutate({ condition, semesters: semestersText(semesters) })}>
          <Check aria-hidden /> Appliquer
        </Button>
      </div>
    </li>
  );
}
