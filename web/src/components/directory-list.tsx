"use client";

import { keepPreviousData, useInfiniteQuery } from "@tanstack/react-query";
import { ChevronRight, Inbox, ListFilter, Search } from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from "react";
import { AppShell } from "@/components/app-shell";
import { FilterChips, FilterPanel } from "@/components/directory-filters";
import { StatusChip } from "@/components/status-chip";
import { LoadError } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api } from "@/lib/api";
import { useMe } from "@/lib/auth";
import {
  activeFilterCount, chooseSort, countText, directoryKeys, directoryUrl, EMPTY_QUERY, ficheUrl, flipDirection, isFiltered, moreText, personName, recalledQuery, rememberQuery, type CourseRow, type DirectoryKind, type DirectoryPage, type Query, type RowOf, type StudentRow, type TeacherRow,
} from "@/lib/directory";
import { cn } from "@/lib/utils";

const TITLES: Record<DirectoryKind, { title: string; search: string; empty: string }> = {
  students: { title: "Étudiants", search: "Nom, prénom, matricule, e-mail ou téléphone", empty: "Aucun étudiant ne correspond" },
  courses: { title: "Cours", search: "Sigle, intitulé, mention ou enseignant", empty: "Aucun cours ne correspond" },
  teachers: { title: "Enseignants", search: "Nom, prénom, e-mail ou téléphone", empty: "Aucun enseignant ne correspond" },
};

/** Une liste du registrariat : recherche, filtres, tri, « afficher la suite ». La recherche est gardée le temps de la session. */
export function DirectoryList({ kind }: { kind: DirectoryKind }) {
  const scope = useRef<HTMLDivElement>(null);
  // Côté navigateur seulement (la page est exportée en statique) : on reprend la recherche gardée au retour d’une fiche.
  const ready = useSyncExternalStore(
    () => () => undefined,
    () => true,
    () => false,
  );
  const recalled = useMemo(() => (ready ? recalledQuery(kind) : EMPTY_QUERY), [ready, kind]);
  const [override, setOverride] = useState<Query | null>(null);
  const query = override ?? recalled;
  const update = useCallback((change: (q: Query) => Query) => setOverride((prev) => change(prev ?? recalled)), [recalled]);
  const [typed, setTyped] = useState<string | null>(null);
  const text = typed ?? query.q;
  const [panel, setPanel] = useState(false);

  useEffect(() => {
    if (ready) rememberQuery(kind, query);
  }, [kind, query, ready]);
  // La saisie part au serveur quand la personne s’arrête de taper (250 ms), pas à chaque lettre.
  useEffect(() => {
    if (!ready || typed === null || typed === query.q) return;
    const t = window.setTimeout(() => update((q) => ({ ...q, q: typed })), 250);
    return () => window.clearTimeout(t);
  }, [typed, query.q, ready, update]);

  const list = useInfiniteQuery({
    queryKey: directoryKeys.list(kind, query),
    queryFn: async ({ pageParam }) => (await api<{ data: DirectoryPage }>(directoryUrl(kind, query, pageParam))).data,
    initialPageParam: 0,
    getNextPageParam: (last) => (last.remaining > 0 ? last.state.page + 1 : undefined),
    placeholderData: keepPreviousData,
    enabled: ready,
    retry: 1,
  });
  const first = list.data?.pages[0];
  const last = list.data?.pages[list.data.pages.length - 1];
  const rows = list.data?.pages.flatMap((p) => p.rows) ?? [];
  useEntrance(scope, "[data-enter]", list.isSuccess && !list.isPlaceholderData);

  const me = useMe();
  const cfg = TITLES[kind];
  const filtered = isFiltered(query);

  return (
    <AppShell title={cfg.title}>
      <div ref={scope} className="mx-auto grid w-full max-w-4xl gap-4 p-4 md:p-6">
        {kind === "students" && me.data?.student_creation && (
          <div data-enter className="flex justify-end">
            <Button render={<Link href="/etudiants/nouveau/" />}>Nouvel étudiant</Button>
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
              placeholder={cfg.search}
              autoComplete="off"
              className="h-12 w-full rounded-xl border border-input bg-background pr-4 pl-11 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </label>

          <div className="flex flex-wrap items-center gap-2">
            <Button variant="outline" aria-expanded={panel} aria-controls="filtres" onClick={() => setPanel((v) => !v)}>
              <ListFilter aria-hidden /> Filtres{activeFilterCount(query) > 0 ? ` (${activeFilterCount(query)})` : ""}
            </Button>
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

          {panel && first && <FilterPanel facets={first.facets} update={update} />}

          {first && <FilterChips query={query} facets={first.facets} update={update} />}
        </div>

        {first?.notice && (
          <p data-enter role="note" className="rounded-xl border border-accent-text/30 bg-accent/60 p-3 text-sm text-muted-foreground">
            {first.notice}
          </p>
        )}

        {list.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement de la liste">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-20 w-full rounded-xl" />
            ))}
          </div>
        )}
        {list.isError && <LoadError error={list.error} onRetry={() => void list.refetch()} what="La liste" />}

        {first && (
          <>
            <p data-enter aria-live="polite" className={cn("text-sm font-semibold text-muted-foreground", list.isPlaceholderData && "opacity-60")}>
              {countText(kind, first.total)}
              {filtered ? " correspondent" : ""}
              {first.total > rows.length && ` · ${rows.length.toLocaleString("fr-FR")} affichés`}
            </p>
            {rows.length === 0 ? (
              <Card data-enter className="items-center gap-2 p-8 text-center">
                <Inbox className="size-8 text-muted-foreground" aria-hidden />
                <p className="font-bold">{cfg.empty}</p>
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
                  <li key={(r as { id: number }).id} data-enter>
                    <RowLink kind={kind} row={r} />
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
    </AppShell>
  );
}

function RowLink<K extends DirectoryKind>({ kind, row }: { kind: K; row: RowOf<K> }) {
  const id = (row as { id: number }).id;
  return (
    <Link href={ficheUrl(kind, id)} className="flex items-center gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring">
      <div className="min-w-0 flex-1">
        {kind === "students" && <StudentLine r={row as StudentRow} />}
        {kind === "courses" && <CourseLine r={row as CourseRow} />}
        {kind === "teachers" && <TeacherLine r={row as TeacherRow} />}
      </div>
      <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
    </Link>
  );
}

function Dots({ parts }: { parts: (string | null | undefined)[] }) {
  const shown = parts.filter((p): p is string => !!p && p.trim() !== "");
  return <>{shown.join(" · ")}</>;
}

function StudentLine({ r }: { r: StudentRow }) {
  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-mono text-sm font-semibold text-accent-text">{r.student_id}</p>
        {r.is_new && <StatusChip tone="info">Nouveau</StatusChip>}
      </div>
      <p className="text-lg leading-snug font-extrabold tracking-tight">{personName(r.last_name, r.first_name) || "Sans nom"}</p>
      <p className="text-sm text-muted-foreground">
        <Dots parts={[r.mention, r.track, r.level_label, r.year]} />
      </p>
    </>
  );
}

function CourseLine({ r }: { r: CourseRow }) {
  return (
    <>
      <p className="font-mono text-sm font-semibold text-accent-text">{r.code}</p>
      <p className="text-lg leading-snug font-extrabold tracking-tight">{r.title}</p>
      <p className="text-sm text-muted-foreground">
        <Dots parts={[r.mention, r.track, r.level_label, r.semester ? `Semestre ${r.semester}` : null, r.credits !== null && r.credits !== "" ? `${r.credits} crédits` : null, r.category]} />
      </p>
      <p className="text-sm">{r.teacher ?? <span className="text-muted-foreground">Sans enseignant</span>}</p>
    </>
  );
}

function TeacherLine({ r }: { r: TeacherRow }) {
  return (
    <>
      <p className="text-lg leading-snug font-extrabold tracking-tight">{personName(r.name, r.last_name) || "Sans nom"}</p>
      <p className="text-sm text-muted-foreground">
        <Dots parts={[r.email, r.phone, r.address]} />
      </p>
    </>
  );
}
