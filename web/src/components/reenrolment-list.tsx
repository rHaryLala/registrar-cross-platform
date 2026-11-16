"use client";

import { keepPreviousData, useInfiniteQuery, useMutation, useQueryClient, type InfiniteData } from "@tanstack/react-query";
import { Inbox, ListFilter, Search } from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { FilterChips, FilterPanel } from "@/components/directory-filters";
import { ResetDialog, UndoBanner } from "@/components/reenrolment-reset";
import { LoadError } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import {
  activeFilterCount, chooseSort, EMPTY_QUERY, ficheUrl, flipDirection, isFiltered, moreText, personName, recalledQuery, rememberQuery, type Query,
} from "@/lib/directory";
import {
  bulkActions, bulkUrl, canWrite, FIELD_LABEL, patchRow, reenrolmentKeys, reenrolmentUrl, selectedText, selectionProblem, STATE_LABEL, STATE_ORDER, toggleUrl, trace,
  type BulkResult, type Field, type ReenrolmentPage, type ReenrolmentRights, type ReenrolmentRow, type ReenrolmentState, type ReenrolmentStats, type ToggleResult,
} from "@/lib/reenrolment";
import { cn } from "@/lib/utils";

const REMEMBER = "reenrolment";

function problem(err: unknown): string | undefined {
  return err instanceof ApiError ? (err.isOffline ? "Pas de réseau : rien n’a été modifié. Réessayez avec une connexion." : err.detail) : undefined;
}

const DOT: Record<ReenrolmentState, string> = { prets: "bg-success", attente_caisse: "bg-warning", attente_dossier: "bg-warning", aucun: "bg-muted-foreground" };

/** Autorisation de réinscription : la caisse autorise, le registraire valide le dossier ; les deux réunis, l'étudiant peut se réinscrire. */
export function ReenrolmentList() {
  const scope = useRef<HTMLDivElement>(null);
  const qc = useQueryClient();
  const ready = useSyncExternalStore(
    () => () => undefined,
    () => true,
    () => false,
  );
  const recalled = useMemo(() => (ready ? recalledQuery(REMEMBER) : EMPTY_QUERY), [ready]);
  const [override, setOverride] = useState<Query | null>(null);
  const query = override ?? recalled;
  const update = useCallback((change: (q: Query) => Query) => setOverride((prev) => change(prev ?? recalled)), [recalled]);
  const [typed, setTyped] = useState<string | null>(null);
  const text = typed ?? query.q;
  const [panel, setPanel] = useState(false);
  const [selected, setSelected] = useState<ReadonlySet<number>>(new Set());
  const [pending, setPending] = useState<Record<string, boolean>>({});

  useEffect(() => {
    if (ready) rememberQuery(REMEMBER, query);
  }, [query, ready]);
  useEffect(() => {
    if (!ready || typed === null || typed === query.q) return;
    const t = window.setTimeout(() => update((q) => ({ ...q, q: typed })), 250);
    return () => window.clearTimeout(t);
  }, [typed, query.q, ready, update]);

  const list = useInfiniteQuery({
    queryKey: reenrolmentKeys.list(query),
    queryFn: async ({ pageParam }) => (await api<{ data: ReenrolmentPage }>(reenrolmentUrl(query, pageParam))).data,
    initialPageParam: 0,
    getNextPageParam: (last) => (last.remaining > 0 ? last.state.page + 1 : undefined),
    placeholderData: keepPreviousData,
    enabled: ready,
    retry: 1,
  });
  const first = list.data?.pages[0];
  const last = list.data?.pages[list.data.pages.length - 1];
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.rows) ?? [], [list.data]);
  useEntrance(scope, "[data-enter]", list.isSuccess && !list.isPlaceholderData);

  const rights: ReenrolmentRights = first?.rights ?? { authorization: false, file: false, reset: false };
  const stats = first?.stats;
  const filtered = isFiltered(query);
  const actions = bulkActions(rights);
  const selectable = actions.length > 0;
  const tooMany = selectionProblem(selected.size);

  /** La réponse du serveur remplace la ligne dans toutes les listes en mémoire (on ne recharge pas : la ligne reste en place, comme dans l'ancienne page). */
  const applyServer = useCallback(
    async (row: ReenrolmentRow, nextStats: ReenrolmentStats) => {
      await qc.cancelQueries({ queryKey: reenrolmentKeys.root });
      qc.setQueriesData<InfiniteData<ReenrolmentPage>>({ queryKey: reenrolmentKeys.root }, (old) =>
        old && Array.isArray(old.pages) ? { ...old, pages: old.pages.map((p) => patchRow(p, row, nextStats)) } : old,
      );
    },
    [qc],
  );

  const toggle = useMutation({
    mutationFn: async (v: { row: ReenrolmentRow; field: Field; value: boolean }) =>
      (await api<{ data: ToggleResult }>(toggleUrl(v.row.id, v.field), { method: "PUT", body: { value: v.value } })).data,
    onMutate: (v) => setPending((p) => ({ ...p, [`${v.row.id}:${v.field}`]: v.value })),
    onSuccess: async (r) => {
      await applyServer(r.row, r.stats);
      toast.success(r.changed ? r.message : "Déjà dans cet état");
    },
    onError: (e) => toast.error("Modification impossible", { description: problem(e) }),
    onSettled: (_r, _e, v) =>
      setPending((p) => {
        const next = { ...p };
        delete next[`${v.row.id}:${v.field}`];
        return next;
      }),
  });

  const bulk = useMutation({
    mutationFn: async (v: { field: Field; value: boolean; ids: number[] }) => (await api<{ data: BulkResult }>(bulkUrl, { method: "POST", body: v })).data,
    onSuccess: async (r, v) => {
      setSelected(new Set());
      await qc.invalidateQueries({ queryKey: reenrolmentKeys.root });
      if (r.changed.length === 0) return toast.info(r.message);
      toast.success(r.message, {
        duration: 12000,
        action: { label: "Annuler", onClick: () => bulk.mutate({ field: v.field, value: !v.value, ids: r.changed }) },
      });
    },
    onError: (e) => toast.error("Action groupée impossible", { description: problem(e) }),
  });

  const flip = (row: ReenrolmentRow, field: Field) => toggle.mutate({ row, field, value: !row[field].value });
  const flipSelection = (rowsLoaded: ReenrolmentRow[], on: boolean) => setSelected(on ? new Set(rowsLoaded.map((r) => r.id)) : new Set());
  const choose = (id: number) =>
    setSelected((cur) => {
      const next = new Set(cur);
      if (!next.delete(id)) next.add(id);
      return next;
    });
  const stateOn = query.filters.etat?.[0];
  const pickState = (s: ReenrolmentState | null) => update((q) => (s === null ? { ...q, filters: Object.fromEntries(Object.entries(q.filters).filter(([k]) => k !== "etat")) } : { ...q, filters: { ...q.filters, etat: [s] } }));
  const panelCount = activeFilterCount({ ...query, filters: Object.fromEntries(Object.entries(query.filters).filter(([k]) => k !== "etat")) });
  const panelFacets = first ? Object.fromEntries(Object.entries(first.facets).filter(([k]) => k !== "etat")) : {};

  return (
    <AppShell title="Autorisation de réinscription">
      <div ref={scope} className={cn("mx-auto grid w-full max-w-4xl gap-4 p-4 md:p-6", selected.size > 0 && "pb-40 md:pb-28")}>
        <div data-enter className="grid gap-2">
          <p className="text-sm text-muted-foreground">
            Un étudiant peut se réinscrire quand la caisse l’autorise <em>et</em> que le registraire a validé son dossier.
          </p>
          {first && (
            <p className="flex flex-wrap gap-2 text-xs font-bold" aria-label="Vos droits sur cette page">
              {(["file", "authorization"] as const).map((f) => (
                <span key={f} className={cn("rounded-full border px-2.5 py-0.5", canWrite(rights, f) ? "border-success text-success" : "border-border text-muted-foreground")}>
                  {FIELD_LABEL[f].column} : {canWrite(rights, f) ? "modifiable" : "lecture seule"}
                </span>
              ))}
            </p>
          )}
        </div>

        {first?.last_batch && (
          <div data-enter>
            <UndoBanner batch={first.last_batch} />
          </div>
        )}

        {stats && (
          <div data-enter role="group" aria-label="Filtrer par état" className="grid grid-cols-2 gap-2 sm:grid-cols-5">
            <StateTile label="Tous les étudiants" count={stats.total} on={stateOn === undefined} onClick={() => pickState(null)} />
            {STATE_ORDER.map((s) => (
              <StateTile key={s} label={STATE_LABEL[s]} count={stats[s]} dot={DOT[s]} on={stateOn === s} onClick={() => pickState(stateOn === s ? null : s)} />
            ))}
          </div>
        )}

        <div data-enter className="grid gap-3">
          <label className="relative block">
            <span className="sr-only">Rechercher</span>
            <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <input
              type="search"
              value={text}
              onChange={(e) => setTyped(e.target.value)}
              placeholder="Matricule, nom, prénom, mention ou parcours"
              autoComplete="off"
              className="h-12 w-full rounded-xl border border-input bg-background pr-4 pl-11 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </label>
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="outline" aria-expanded={panel} aria-controls="filtres" onClick={() => setPanel((v) => !v)}>
              <ListFilter aria-hidden /> Filtres{panelCount > 0 ? ` (${panelCount})` : ""}
            </Button>
            {rights.reset && first && <ResetDialog available={first.batches_available} />}
            {first && (
              <div className="ml-auto flex items-center gap-2">
                <label className="flex items-center gap-2 text-sm font-semibold">
                  Trier par
                  <select
                    value={first.state.sort}
                    onChange={(e) => update((q) => chooseSort(q, e.target.value, first.state, first.sorts))}
                    className="h-11 rounded-lg border border-input bg-background px-2 text-base md:h-10 md:text-sm"
                  >
                    {Object.entries(first.sorts).map(([k, s]) => (
                      <option key={k} value={k}>
                        {s.label}
                      </option>
                    ))}
                  </select>
                </label>
                <Button variant="outline" size="icon" aria-label={first.state.direction === "asc" ? "Ordre croissant : inverser" : "Ordre décroissant : inverser"} onClick={() => update((q) => flipDirection(q, first.state))}>
                  <span aria-hidden className="text-lg leading-none font-black">
                    {first.state.direction === "asc" ? "↑" : "↓"}
                  </span>
                </Button>
              </div>
            )}
          </div>
          {panel && first && <FilterPanel facets={panelFacets} update={update} />}
          {first && <FilterChips query={query} facets={first.facets} update={update} />}
        </div>

        {list.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement de la liste">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-28 w-full rounded-xl" />
            ))}
          </div>
        )}
        {list.isError && <LoadError error={list.error} onRetry={() => void list.refetch()} what="La liste" />}

        {first && (
          <>
            <div data-enter className="flex flex-wrap items-center gap-3">
              <p aria-live="polite" className={cn("text-sm font-semibold text-muted-foreground", list.isPlaceholderData && "opacity-60")}>
                {first.total === 0 ? "Aucun étudiant" : `${first.total.toLocaleString("fr-FR")} étudiant${first.total > 1 ? "s" : ""}`}
                {filtered ? " correspondent" : ""}
                {first.total > rows.length && ` · ${rows.length.toLocaleString("fr-FR")} affichés`}
              </p>
              {selectable && rows.length > 0 && (
                <label className="ml-auto flex min-h-11 items-center gap-2 text-sm font-semibold md:min-h-9">
                  <input type="checkbox" checked={selected.size > 0 && rows.every((r) => selected.has(r.id))} onChange={(e) => flipSelection(rows, e.target.checked)} className="size-5 accent-[var(--primary)]" />
                  Tout sélectionner ({rows.length.toLocaleString("fr-FR")} affichés)
                </label>
              )}
            </div>
            {rows.length === 0 ? (
              <Card data-enter className="items-center gap-2 p-8 text-center">
                <Inbox className="size-8 text-muted-foreground" aria-hidden />
                <p className="font-bold">Aucun étudiant ne correspond</p>
                {filtered && (
                  <div>
                    <Button
                      variant="outline"
                      onClick={() => {
                        setTyped("");
                        update((q) => ({ ...EMPTY_QUERY, sort: q.sort, direction: q.direction }));
                      }}
                    >
                      Effacer la recherche et les filtres
                    </Button>
                  </div>
                )}
              </Card>
            ) : (
              <ul className={cn("grid gap-2", list.isPlaceholderData && "opacity-60")}>
                {rows.map((r) => (
                  <li key={r.id} data-enter>
                    <StudentCard row={r} rights={rights} pending={pending} selectable={selectable} selected={selected.has(r.id)} onSelect={() => choose(r.id)} onFlip={(f) => flip(r, f)} />
                  </li>
                ))}
              </ul>
            )}
            {last && last.remaining > 0 && (
              <div className="flex justify-center">
                <Button variant="outline" size="lg" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                  {list.isFetchingNextPage ? "Chargement…" : moreText(last.remaining, last.per_page)}
                </Button>
              </div>
            )}
          </>
        )}
      </div>

      {selected.size > 0 && (
        <div role="region" aria-label="Action groupée" className="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-20 border-t bg-background/95 p-3 shadow-lg backdrop-blur md:bottom-0">
          <div className="mx-auto grid max-w-4xl gap-2">
            <div className="flex flex-wrap items-center gap-2">
              <p aria-live="polite" className="flex-1 text-sm font-bold">
                {selectedText(selected.size)}
              </p>
              <Button variant="ghost" size="sm" onClick={() => setSelected(new Set())}>
                Vider la sélection
              </Button>
            </div>
            {tooMany && (
              <p role="alert" className="text-sm font-semibold text-destructive">
                {tooMany}
              </p>
            )}
            <div className="flex flex-wrap gap-2">
              {actions.map((a) => (
                <Button key={`${a.field}:${a.value}`} variant={a.value ? "default" : "outline"} disabled={bulk.isPending || tooMany !== null} onClick={() => bulk.mutate({ field: a.field, value: a.value, ids: [...selected] })}>
                  {a.label}
                </Button>
              ))}
            </div>
          </div>
        </div>
      )}
    </AppShell>
  );
}

function StateTile({ label, count, dot, on, onClick }: { label: string; count: number; dot?: string; on: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      aria-pressed={on}
      onClick={onClick}
      className={cn(
        "grid min-h-20 content-between gap-1 rounded-xl border bg-card p-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring",
        on ? "border-primary ring-2 ring-primary" : "border-border",
      )}
    >
      <span className="text-2xl leading-none font-black tabular-nums">{count.toLocaleString("fr-FR")}</span>
      <span className="flex items-center gap-1.5 text-xs font-bold text-muted-foreground">
        {dot && <span aria-hidden className={cn("size-2 shrink-0 rounded-full", dot)} />}
        {label}
      </span>
    </button>
  );
}

function StudentCard({
  row, rights, pending, selectable, selected, onSelect, onFlip,
}: {
  row: ReenrolmentRow;
  rights: ReenrolmentRights;
  pending: Record<string, boolean>;
  selectable: boolean;
  selected: boolean;
  onSelect: () => void;
  onFlip: (field: Field) => void;
}) {
  const name = personName(row.last_name, row.first_name) || "Sans nom";
  return (
    <Card className={cn("gap-3 p-4", selected && "ring-2 ring-primary")}>
      <div className="flex items-start gap-3">
        {selectable && (
          <label className="-m-2 flex size-11 shrink-0 items-center justify-center">
            <input type="checkbox" checked={selected} onChange={onSelect} aria-label={`Sélectionner ${name}`} className="size-5 accent-[var(--primary)]" />
          </label>
        )}
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="font-mono text-sm font-semibold text-accent-text">{row.student_id}</p>
            <span className={cn("inline-flex items-center gap-1.5 text-xs font-bold", row.state === "prets" ? "text-success" : "text-muted-foreground")}>
              <span aria-hidden className={cn("size-2 rounded-full", DOT[row.state])} />
              {STATE_LABEL[row.state]}
            </span>
          </div>
          <Link href={ficheUrl("students", row.id)} className="text-lg leading-snug font-extrabold tracking-tight hover:underline">
            {name}
          </Link>
          <p className="text-sm text-muted-foreground">{[row.mention, row.track, row.level_label, row.year, row.residence].filter((x) => x && x.trim() !== "").join(" · ")}</p>
        </div>
      </div>
      <div className="grid gap-2 sm:grid-cols-2">
        {(["file", "authorization"] as const).map((f) => (
          <FlagControl key={f} row={row} field={f} editable={canWrite(rights, f)} pending={pending[`${row.id}:${f}`]} onFlip={() => onFlip(f)} />
        ))}
      </div>
    </Card>
  );
}

function FlagControl({ row, field, editable, pending, onFlip }: { row: ReenrolmentRow; field: Field; editable: boolean; pending: boolean | undefined; onFlip: () => void }) {
  const shown = pending ?? row[field].value;
  const label = FIELD_LABEL[field];
  const who = trace(row[field]);
  return (
    <div className="grid gap-0.5 rounded-lg bg-muted/60 p-2.5">
      <div className="flex items-center gap-3">
        <span className="min-w-0 flex-1 text-xs font-extrabold tracking-wide text-muted-foreground uppercase">{label.column}</span>
        {editable ? (
          <button
            type="button"
            role="switch"
            aria-checked={shown}
            aria-busy={pending !== undefined}
            aria-label={`${field === "file" ? "Dossier validé" : "Réinscription autorisée"} — ${personName(row.last_name, row.first_name)}`}
            onClick={onFlip}
            disabled={pending !== undefined}
            className="group inline-flex min-h-11 items-center gap-2 rounded-full pl-1 outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-70 md:min-h-9"
          >
            <span className={cn("relative h-6 w-11 rounded-full transition-colors", shown ? "bg-primary" : "bg-input")}>
              <span className={cn("absolute top-0.5 left-0.5 size-5 rounded-full bg-background shadow transition-transform", shown && "translate-x-5")} />
            </span>
            <span className="w-24 text-left text-sm font-bold">{shown ? label.on : label.off}</span>
          </button>
        ) : (
          <span className={cn("rounded-full border px-2.5 py-0.5 text-xs font-bold", shown ? "border-success text-success" : "border-border text-muted-foreground")}>{shown ? label.on : label.off}</span>
        )}
      </div>
      <p className="min-h-4 text-xs text-muted-foreground">{who}</p>
    </div>
  );
}
