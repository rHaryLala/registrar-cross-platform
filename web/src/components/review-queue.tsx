"use client";

import { useQuery } from "@tanstack/react-query";
import { ChevronRight, Inbox } from "lucide-react";
import Link from "next/link";
import { useMemo, useRef, useState } from "react";
import { AppShell } from "@/components/app-shell";
import { BulkBar, useBulkValidation } from "@/components/review-bulk";
import { StatusChip } from "@/components/status-chip";
import { LoadError } from "@/components/teacher-common";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api } from "@/lib/api";
import { plural } from "@/lib/format";
import { STATUS_INFO, type SheetStatus } from "@/lib/grading";
import { BULK_MAX, isSelectable, pruneSelection, reviewKeys, reviewUrl, selectAll, toggleSelection, type Queue } from "@/lib/review";
import { cn } from "@/lib/utils";

const TABS: { status: SheetStatus; label: string }[] = [
  { status: "soumise", label: "À valider" },
  { status: "rejetee", label: "Renvoyées" },
  { status: "validee", label: "Validées" },
  { status: "brouillon", label: "Brouillons" },
];

/** Validation des notes : les feuilles transmises par les enseignants, celles à valider d'abord. */
export function ReviewQueue() {
  const scope = useRef<HTMLDivElement>(null);
  const [status, setStatus] = useState<SheetStatus>("soumise");
  const [year, setYear] = useState<string | null>(null);
  const q = useQuery({
    queryKey: reviewKeys.queue(status, year),
    queryFn: async () => (await api<{ data: Queue }>(`/api/v1/review/remises?status=${status}${year ? `&year=${encodeURIComponent(year)}` : ""}`)).data,
    retry: 1,
  });
  useEntrance(scope, "[data-enter]", q.isSuccess);

  // Validation groupée : on coche les feuilles prêtes de l'onglet « À valider ».
  const [picked, setSelected] = useState<ReadonlySet<number>>(new Set());
  const bulk = useBulkValidation(() => setSelected(new Set()));
  const items = q.data?.items;
  const selected = useMemo(() => (items ? pruneSelection(picked, items) : picked), [picked, items]);   // la liste change : on ne garde que ce qui est encore sélectionnable
  const canBulk = status === "soumise" && items !== undefined;
  const selectable = canBulk ? items.filter(isSelectable).length : 0;
  const locked = bulk.waiting !== null;

  return (
    <AppShell title="Validation des notes">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        <div data-enter className="flex flex-wrap items-end justify-between gap-3">
          <div role="tablist" aria-label="État des feuilles" className="flex flex-wrap gap-1.5">
            {TABS.map((t) => (
              <button
                key={t.status}
                role="tab"
                type="button"
                aria-selected={status === t.status}
                onClick={() => setStatus(t.status)}
                className={cn(
                  "inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3.5 text-sm font-bold transition-colors",
                  status === t.status ? "border-transparent bg-primary text-primary-foreground" : "border-border bg-background hover:bg-muted",
                )}
              >
                {t.label}
                {q.data?.counts[t.status] !== undefined && <span className="tabular-nums opacity-80">{q.data.counts[t.status]}</span>}
              </button>
            ))}
          </div>
          {q.data && q.data.years.length > 1 && (
            <label className="grid gap-1 text-sm font-semibold">
              Année scolaire
              <select value={year ?? ""} onChange={(e) => setYear(e.target.value || null)} className="h-11 rounded-lg border border-input bg-background px-3 text-base md:h-10 md:text-sm">
                <option value="">Toutes</option>
                {q.data.years.map((y) => (
                  <option key={y} value={y}>
                    {y}
                  </option>
                ))}
              </select>
            </label>
          )}
        </div>

        {q.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement des feuilles">
            <Skeleton className="h-24 w-full rounded-xl" />
            <Skeleton className="h-24 w-full rounded-xl" />
          </div>
        )}
        {q.isError && <LoadError error={q.error} onRetry={() => q.refetch()} what="La file de validation" />}
        {q.isSuccess && q.data.items.length === 0 && (
          <Card data-enter className="items-center gap-2 p-8 text-center">
            <Inbox className="size-8 text-muted-foreground" aria-hidden />
            <p className="font-bold">{status === "soumise" ? "Aucune feuille à valider" : "Aucune feuille dans cet état"}</p>
            <p className="text-sm text-muted-foreground">{status === "soumise" ? "Les feuilles apparaîtront ici dès que les enseignants les auront transmises." : "Changez d’onglet ou d’année scolaire."}</p>
          </Card>
        )}
        {q.isSuccess && (
          <ul className="grid gap-3">
            {q.data.items.map((r) => {
              const st = STATUS_INFO[r.status];
              const pickable = canBulk && isSelectable(r);
              return (
                <li key={r.id} data-enter className="flex items-stretch gap-2">
                  {pickable && (
                    <label className="flex min-h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xl bg-card ring-1 ring-foreground/10 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60">
                      <input
                        type="checkbox"
                        checked={selected.has(r.id)}
                        disabled={locked || (!selected.has(r.id) && selected.size >= BULK_MAX)}
                        onChange={() => setSelected((s) => toggleSelection(s, r.id))}
                        className="size-5 accent-[var(--primary)]"
                      />
                      <span className="sr-only">Sélectionner {r.code} {r.title}</span>
                    </label>
                  )}
                  <Link
                    href={reviewUrl(r.id)}
                    className="flex min-w-0 flex-1 items-center gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="font-mono text-sm font-semibold text-accent-text">{r.code}</p>
                        <span className="flex flex-wrap items-center gap-1.5">
                          {r.pending_until && <StatusChip tone="warn">Validation en cours</StatusChip>}
                          <StatusChip tone={st.tone}>{st.label}</StatusChip>
                        </span>
                      </div>
                      <p className="text-lg leading-snug font-extrabold tracking-tight">{r.title}</p>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {r.teacher ?? "Enseignant non indiqué"} · {r.year}
                        {r.semester ? ` · Semestre ${r.semester}` : ""}
                      </p>
                      {r.summary && (
                        <p className="mt-1 text-sm">
                          <strong className="tabular-nums">
                            {r.summary.ready} / {r.summary.total}
                          </strong>{" "}
                          notes prêtes
                          {r.summary.blockers > 0 && <span className="font-semibold text-destructive"> · {plural(r.summary.blockers, "point bloquant", "points bloquants")}</span>}
                        </p>
                      )}
                      {r.status === "rejetee" && r.reject_reason && <p className="mt-1 text-sm text-destructive">Renvoyée : {r.reject_reason}</p>}
                      {r.submitted_at && <p className="mt-0.5 text-xs text-muted-foreground">transmise le {new Date(r.submitted_at.replace(" ", "T")).toLocaleString("fr-FR", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" })}</p>}
                    </div>
                    <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                  </Link>
                </li>
              );
            })}
          </ul>
        )}
        {canBulk && (
          <BulkBar count={selected.size} selectable={selectable} bulk={bulk} onAll={() => setSelected(selectAll(items))} onNone={() => setSelected(new Set())} onValidate={() => void bulk.start([...selected])} />
        )}
      </div>
    </AppShell>
  );
}
