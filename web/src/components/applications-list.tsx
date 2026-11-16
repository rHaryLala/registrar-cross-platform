"use client";

import { useQuery } from "@tanstack/react-query";
import { ChevronRight, Inbox } from "lucide-react";
import Link from "next/link";
import { useRef, useState } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { LoadError } from "@/components/teacher-common";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api } from "@/lib/api";
import { applicationKeys, applicationUrl, currentNotices, hasFailure, listUrl, noticeText, receivedDate, STATUS_TABS, STATUS_TONE, type ApplicationList } from "@/lib/applications";
import { cn } from "@/lib/utils";

/** Candidatures de pré-admission : par statut, les nouvelles d'abord à instruire ; les archives à part. */
export function ApplicationsList() {
  const scope = useRef<HTMLDivElement>(null);
  const [filter, setFilter] = useState("");
  const q = useQuery({
    queryKey: applicationKeys.list(filter),
    queryFn: async () => (await api<{ data: ApplicationList }>(listUrl(filter))).data,
    retry: 1,
  });
  useEntrance(scope, "[data-enter]", q.isSuccess);
  const d = q.data;
  const count = (value: string) => (!d ? null : value === "" ? d.total : value === "archive" ? d.archived : d.counts[value as keyof typeof d.counts]);

  return (
    <AppShell title="Candidatures">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-4 p-4 md:p-6">
        <div data-enter>
          <h2 className="text-2xl font-extrabold tracking-tight">Candidatures de pré-admission</h2>
          {d && (
            <p className="text-sm text-muted-foreground" aria-live="polite">
              {d.total} au total · <span className="font-semibold text-accent-text">{d.counts.nouveau} nouvelle{d.counts.nouveau > 1 ? "s" : ""}</span>
            </p>
          )}
        </div>

        <div data-enter role="tablist" aria-label="Statut des candidatures" className="flex flex-wrap gap-1.5">
          {STATUS_TABS.map((t) => (
            <button
              key={t.value}
              role="tab"
              type="button"
              aria-selected={filter === t.value}
              onClick={() => setFilter(t.value)}
              className={cn(
                "inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3.5 text-sm font-bold transition-colors",
                filter === t.value ? "border-transparent bg-primary text-primary-foreground" : "border-border bg-background hover:bg-muted",
              )}
            >
              {t.label}
              {count(t.value) !== null && <span className="tabular-nums opacity-80">{count(t.value)}</span>}
            </button>
          ))}
        </div>

        {q.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement des candidatures">
            <Skeleton className="h-24 w-full rounded-xl" />
            <Skeleton className="h-24 w-full rounded-xl" />
          </div>
        )}
        {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} what="Les candidatures" />}
        {d && d.rows.length === 0 && (
          <Card data-enter className="items-center gap-2 p-8 text-center">
            <Inbox className="size-8 text-muted-foreground" aria-hidden />
            <p className="font-bold">{filter === "" ? "Aucune candidature pour l’instant" : "Aucune candidature dans cette vue"}</p>
          </Card>
        )}
        {d && (
          <ul className="grid gap-3">
            {d.rows.map((r) => {
              const notices = currentNotices(r);
              return (
                <li key={r.id} data-enter>
                  <Link
                    href={applicationUrl(r.id)}
                    className="flex items-center gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-lg leading-snug font-extrabold tracking-tight">{r.name}</p>
                        <StatusChip tone={STATUS_TONE[r.status]}>{r.status_label}</StatusChip>
                      </div>
                      <p className="text-sm text-muted-foreground">
                        {[r.mention, r.degree ? r.degree.charAt(0).toUpperCase() + r.degree.slice(1) : null, r.track].filter(Boolean).join(" · ")}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        reçue le {receivedDate(r.received_at)}
                        {r.processed_by ? ` · traitée par ${r.processed_by}` : ""}
                      </p>
                      {r.student_id && (
                        <p className="text-sm">
                          Étudiant : <span className="font-mono font-semibold text-accent-text">{r.student_id}</span>
                        </p>
                      )}
                      {notices.length > 0 && (
                        <ul className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                          {notices.map((n) => {
                            const t = noticeText(n);
                            return (
                              <li key={n.id} className={cn(t.tone === "ok" ? "text-success" : t.tone === "bad" ? "font-semibold text-destructive" : t.tone === "warn" ? "text-warning" : "text-muted-foreground")}>
                                {t.label}
                              </li>
                            );
                          })}
                        </ul>
                      )}
                      {hasFailure(r) && <p className="sr-only">Une notification n’est pas partie.</p>}
                    </div>
                    <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                  </Link>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </AppShell>
  );
}
