"use client";

import { keepPreviousData, useInfiniteQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Check, Inbox, Pencil, Search, X } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { LoadError } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import {
  balancesKeys, balancesUrl, countText, editText, formatBalance, parseAmount, saveUrl, sessionOptionText, STATE_HINT, STATE_LABEL, STATE_ORDER, toggleState,
  type BalanceRow, type BalanceState, type BalancesPage, type SaveResult,
} from "@/lib/balances";
import { chooseSort, EMPTY_QUERY, flipDirection, moreText, recalledQuery, rememberQuery, type Query } from "@/lib/directory";
import { cn } from "@/lib/utils";

const REMEMBER = "balances";

const TONE: Record<BalanceState, string> = { debitaire: "text-destructive", creditaire: "text-success", solde: "text-muted-foreground", sans_ligne: "text-muted-foreground" };
const DOT: Record<BalanceState, string> = { debitaire: "bg-destructive", creditaire: "bg-success", solde: "bg-muted-foreground", sans_ligne: "bg-border" };

function problem(err: unknown): string | undefined {
  return err instanceof ApiError ? (err.isOffline ? "Pas de réseau : rien n’a été modifié. Réessayez avec une connexion." : err.detail) : undefined;
}

/** Solde de compte d'une session : dette (débitaire) ou avance (créditaire) de chaque étudiant inscrit ; le registrariat corrige le solde enregistré. */
export function BalancesList() {
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
  const [session, setSession] = useState<number | null>(null);
  const [editing, setEditing] = useState<string | null>(null);

  useEffect(() => {
    if (ready) rememberQuery(REMEMBER, query);
  }, [query, ready]);
  useEffect(() => {
    if (!ready || typed === null || typed === query.q) return;
    const t = window.setTimeout(() => update((q) => ({ ...q, q: typed })), 250);
    return () => window.clearTimeout(t);
  }, [typed, query.q, ready, update]);

  const list = useInfiniteQuery({
    queryKey: balancesKeys.list(session, query),
    queryFn: async ({ pageParam }) => (await api<{ data: BalancesPage }>(balancesUrl(session, query, pageParam))).data,
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
  const sessionId = first?.session_id ?? null;
  const wanted = query.filters.etat ?? [];

  const save = useMutation({
    mutationFn: async (v: { financeId: string; value: string }) => (await api<{ data: SaveResult }>(saveUrl(sessionId ?? 0, v.financeId), { method: "PUT", body: { balance: v.value } })).data,
    onSuccess: async (r) => {
      setEditing(null);
      toast.success(r.changed ? "Solde enregistré" : "Solde inchangé", { description: `${r.row.name || r.row.student_id} : ${formatBalance(r.row.balance)}` });
      await qc.invalidateQueries({ queryKey: balancesKeys.root });
    },
    onError: (e) => toast.error("Solde non enregistré", { description: problem(e) }),
  });

  return (
    <AppShell title="Solde de compte">
      <div ref={scope} className="mx-auto grid w-full max-w-4xl gap-4 p-4 md:p-6">
        <div data-enter className="grid gap-3">
          <p className="text-sm text-muted-foreground">
            Dette ou avance de chaque étudiant inscrit à la session. Une dette s’écrit <strong>-150 000 Ar</strong>, une avance <strong>+25 000 Ar</strong>.
          </p>
          <label className="grid gap-1 text-sm font-semibold">
            Session
            <select
              value={sessionId ?? ""}
              onChange={(e) => {
                setSession(Number(e.target.value));
                setEditing(null);
              }}
              className="h-12 w-full rounded-xl border border-input bg-background px-3 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {(first?.sessions ?? []).map((s) => (
                <option key={s.id} value={s.id}>
                  {sessionOptionText(s)}
                </option>
              ))}
            </select>
          </label>
          <label className="relative block">
            <span className="sr-only">Rechercher</span>
            <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <input
              type="search"
              value={text}
              onChange={(e) => setTyped(e.target.value)}
              placeholder="Matricule, account code ou nom"
              autoComplete="off"
              className="h-12 w-full rounded-xl border border-input bg-background pr-4 pl-11 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </label>
        </div>

        {first && (
          <div data-enter role="group" aria-label="Filtrer par état" className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {STATE_ORDER.map((s) => (
              <button
                key={s}
                type="button"
                aria-pressed={wanted.includes(s)}
                title={STATE_HINT[s]}
                onClick={() => update((q) => toggleState(q, s))}
                className={cn(
                  "grid min-h-20 content-between gap-1 rounded-xl border bg-card p-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring",
                  wanted.includes(s) ? "border-primary ring-2 ring-primary" : "border-border",
                )}
              >
                <span className="text-2xl leading-none font-black tabular-nums">{first.counts[s].toLocaleString("fr-FR")}</span>
                <span className="flex items-center gap-1.5 text-xs font-bold text-muted-foreground">
                  <span aria-hidden className={cn("size-2 shrink-0 rounded-full", DOT[s])} />
                  {STATE_LABEL[s]}
                </span>
              </button>
            ))}
          </div>
        )}

        {first && first.total > 0 && (
          <dl data-enter className="grid grid-cols-3 gap-2 text-center">
            <div className="rounded-xl bg-card p-3 ring-1 ring-foreground/10">
              <dt className="text-xs font-bold text-muted-foreground">Dettes</dt>
              <dd className="font-black text-destructive tabular-nums">{formatBalance(first.totals.debit)}</dd>
            </div>
            <div className="rounded-xl bg-card p-3 ring-1 ring-foreground/10">
              <dt className="text-xs font-bold text-muted-foreground">Avances</dt>
              <dd className="font-black text-success tabular-nums">{formatBalance(-first.totals.credit)}</dd>
            </div>
            <div className="rounded-xl bg-card p-3 ring-1 ring-foreground/10">
              <dt className="text-xs font-bold text-muted-foreground">Net</dt>
              <dd className="font-black tabular-nums">{formatBalance(first.totals.net)}</dd>
            </div>
          </dl>
        )}

        {first && (
          <div data-enter className="flex items-center gap-2">
            <p aria-live="polite" className={cn("text-sm font-semibold text-muted-foreground", list.isPlaceholderData && "opacity-60")}>
              {countText(first.total)}
              {first.total !== first.students_in_session ? ` sur ${first.students_in_session.toLocaleString("fr-FR")}` : ""}
              {first.total > rows.length && ` · ${rows.length.toLocaleString("fr-FR")} affichés`}
            </p>
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
          </div>
        )}

        {list.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement de la liste">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-24 w-full rounded-xl" />
            ))}
          </div>
        )}
        {list.isError && <LoadError error={list.error} onRetry={() => void list.refetch()} what="La liste" />}

        {first &&
          (rows.length === 0 ? (
            <Card data-enter className="items-center gap-2 p-8 text-center">
              <Inbox className="size-8 text-muted-foreground" aria-hidden />
              <p className="font-bold">{first.students_in_session === 0 ? "Aucun étudiant inscrit à cette session" : "Aucun étudiant ne correspond"}</p>
              {(query.q !== "" || wanted.length > 0) && (
                <Button
                  variant="outline"
                  onClick={() => {
                    setTyped("");
                    update((q) => ({ ...EMPTY_QUERY, sort: q.sort, direction: q.direction }));
                  }}
                >
                  Effacer la recherche et les filtres
                </Button>
              )}
            </Card>
          ) : (
            <ul className={cn("grid gap-2", list.isPlaceholderData && "opacity-60")}>
              {rows.map((r) => (
                <li key={r.student_id} data-enter>
                  <Line
                    row={r}
                    editing={editing === r.student_id}
                    busy={save.isPending}
                    onEdit={() => setEditing(r.student_id)}
                    onCancel={() => setEditing(null)}
                    onSave={(value) => r.finance_id && save.mutate({ financeId: r.finance_id, value })}
                  />
                </li>
              ))}
            </ul>
          ))}
        {last && last.remaining > 0 && (
          <div className="flex justify-center">
            <Button variant="outline" size="lg" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
              {list.isFetchingNextPage ? "Chargement…" : moreText(last.remaining, last.per_page)}
            </Button>
          </div>
        )}
      </div>
    </AppShell>
  );
}

function Line({ row, editing, busy, onEdit, onCancel, onSave }: { row: BalanceRow; editing: boolean; busy: boolean; onEdit: () => void; onCancel: () => void; onSave: (value: string) => void }) {
  return (
    <Card className="gap-2 p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <p className="font-mono text-sm font-semibold text-accent-text">
          {row.student_id}
          {row.account_code !== "" && <span className="ml-2 text-muted-foreground">{row.account_code}</span>}
        </p>
        <span className={cn("inline-flex items-center gap-1.5 text-xs font-bold", TONE[row.state])}>
          <span aria-hidden className={cn("size-2 rounded-full", DOT[row.state])} />
          {STATE_LABEL[row.state]}
        </span>
      </div>
      <p className="text-lg leading-snug font-extrabold tracking-tight">{row.name || "Sans nom"}</p>
      {editing ? (
        <EditForm initial={editText(row.balance)} busy={busy} onCancel={onCancel} onSave={onSave} />
      ) : (
        <div className="flex items-center gap-3">
          <p className={cn("flex-1 text-xl font-black tabular-nums", TONE[row.state])}>{row.state === "sans_ligne" ? "—" : formatBalance(row.balance)}</p>
          {row.editable ? (
            <Button variant="outline" size="sm" onClick={onEdit} aria-label={`Modifier le solde de ${row.name || row.student_id}`}>
              <Pencil aria-hidden /> Modifier
            </Button>
          ) : (
            <span className="text-xs text-muted-foreground">Pas de ligne finance pour cette session</span>
          )}
        </div>
      )}
    </Card>
  );
}

/** Monté seulement pendant la saisie : la valeur de départ est toujours le solde enregistré au moment où l'on ouvre l'éditeur. */
function EditForm({ initial, busy, onCancel, onSave }: { initial: string; busy: boolean; onCancel: () => void; onSave: (value: string) => void }) {
  const [value, setValue] = useState(initial);
  const parsed = parseAmount(value);
  const invalid = value.trim() !== "" && parsed === null;
  return (
    <form
      className="grid gap-2"
      onSubmit={(e) => {
        e.preventDefault();
        if (parsed !== null) onSave(value);
      }}
    >
      <label className="grid gap-1 text-sm font-semibold">
        Nouveau solde (positif = dette, négatif = avance)
        <input
          value={value}
          onChange={(e) => setValue(e.target.value)}
          inputMode="decimal"
          autoComplete="off"
          autoFocus
          aria-invalid={invalid}
          className="h-12 rounded-lg border border-input bg-background px-3 font-mono text-base outline-none focus-visible:ring-2 focus-visible:ring-ring aria-invalid:border-destructive"
        />
      </label>
      {invalid && (
        <p role="alert" className="text-sm font-semibold text-destructive">
          Saisissez un montant, par exemple 150000 ou -25 000,50.
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={busy || parsed === null}>
          <Check aria-hidden /> {busy ? "Enregistrement…" : "Enregistrer"}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel} disabled={busy}>
          <X aria-hidden /> Annuler
        </Button>
      </div>
    </form>
  );
}
