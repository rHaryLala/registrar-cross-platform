"use client";

import { useQuery } from "@tanstack/react-query";
import { CalendarDays, ExternalLink, GraduationCap, Info, Newspaper, Palette, Pin, TriangleAlert, Trophy } from "lucide-react";
import Link from "next/link";
import { useRef, useState, type ComponentType } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { API_BASE, api, ApiError } from "@/lib/api";
import { formatDate } from "@/lib/format";
import { CATEGORY_LABEL, CATEGORY_ORDER, type NewsCategory, type NewsList, type NewsSummary } from "@/lib/news";
import { safeImageSrc, type SiteNewsList, type SiteNewsSummary } from "@/lib/site-news";
import { cn } from "@/lib/utils";

/** Une icône ET un mot par catégorie : la couleur n'est jamais seule à porter le sens (une seule teinte d'accent dans l'application). */
export const CATEGORY_ICON: Record<NewsCategory, ComponentType<{ className?: string }>> = {
  info: Info,
  event: CalendarDays,
  academic: GraduationCap,
  urgent: TriangleAlert,
  sport: Trophy,
  culture: Palette,
};

export function NewsImage({ id, className, eager }: { id: number; className?: string; eager?: boolean }) {
  const [failed, setFailed] = useState(false);
  if (failed) return null;
  return (
    // eslint-disable-next-line @next/next/no-img-element -- image protégée par la session et réduite côté serveur
    <img
      src={`${API_BASE}/api/v1/me/news/${id}/image`}
      alt=""
      loading={eager ? "eager" : "lazy"}
      crossOrigin="use-credentials"
      onError={() => setFailed(true)}
      className={cn("object-cover", className)}
    />
  );
}

type Source = "site" | "registrar";

const syncedFmt = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });

export function StudentNews() {
  const scope = useRef<HTMLDivElement>(null);
  const [chosen, setChosen] = useState<Source | null>(null);
  const [category, setCategory] = useState<NewsCategory | "all">("all");

  const site = useQuery({
    queryKey: ["me", "site-news"],
    queryFn: async () => (await api<{ data: SiteNewsList }>("/api/v1/me/site-news")).data,
  });
  const registrar = useQuery({
    queryKey: ["me", "news"],
    queryFn: async () => (await api<{ data: NewsList }>("/api/v1/me/news")).data,
  });

  const loading = site.isPending || registrar.isPending;
  const bothFailed = site.isError && registrar.isError;
  const ready = !loading && !bothFailed;
  useEntrance(scope, "[data-enter]", ready);

  const siteTotal = site.data?.total ?? 0;
  const regTotal = registrar.data?.total ?? 0;
  // Par défaut : les actualités du site (le sujet de la page) ; à défaut, les annonces du registrariat.
  const source: Source = chosen ?? (siteTotal > 0 || regTotal === 0 ? "site" : "registrar");

  return (
    <AppShell title="Actualités">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        {loading && <NewsSkeleton />}
        {bothFailed && <NewsError error={site.error} onRetry={() => void Promise.all([site.refetch(), registrar.refetch()])} />}
        {ready && (
          <>
            {siteTotal > 0 && regTotal > 0 && <SourceSwitch source={source} onChange={setChosen} siteTotal={siteTotal} regTotal={regTotal} />}
            {source === "site" ? (
              <SiteSection query={site} />
            ) : (
              <RegistrarSection data={registrar.data} failed={registrar.isError} category={category} onCategory={setCategory} />
            )}
          </>
        )}
      </div>
    </AppShell>
  );
}

function SourceSwitch({ source, onChange, siteTotal, regTotal }: { source: Source; onChange: (s: Source) => void; siteTotal: number; regTotal: number }) {
  const options: [Source, string][] = [
    ["site", `Université (${siteTotal})`],
    ["registrar", `Registrariat (${regTotal})`],
  ];
  return (
    <div data-enter role="group" aria-label="Source des actualités" className="grid grid-cols-2 gap-1 rounded-xl border p-1">
      {options.map(([value, label]) => (
        <button
          key={value}
          type="button"
          aria-pressed={source === value}
          onClick={() => onChange(value)}
          className="min-h-11 rounded-lg px-3 text-sm font-bold text-muted-foreground transition-colors aria-pressed:bg-accent aria-pressed:text-accent-text"
        >
          {label}
        </button>
      ))}
    </div>
  );
}

// ----------------------------------------------------------------------------------- site de l'université

function SiteSection({ query }: { query: { data?: SiteNewsList; isError: boolean; refetch: () => unknown } }) {
  if (query.isError || !query.data) {
    return (
      <Card role="alert" className="gap-3 p-4">
        <p className="font-bold">Les actualités du site n’ont pas pu se charger</p>
        <div>
          <Button onClick={() => void query.refetch()}>Réessayer</Button>
        </div>
      </Card>
    );
  }
  const d = query.data;
  if (d.total === 0) {
    return (
      <Card data-enter className="items-center gap-2 p-8 text-center">
        <Newspaper className="size-8 text-muted-foreground" aria-hidden />
        <p className="font-bold">Aucune actualité pour le moment</p>
        <p className="text-sm text-muted-foreground">
          Les actualités du site de l’université s’afficheront ici dès leur synchronisation.{" "}
          <a href={d.source_url} target="_blank" rel="noopener noreferrer" className="text-accent-text underline underline-offset-4">
            Voir le site
          </a>
        </p>
      </Card>
    );
  }
  return (
    <>
      <p data-enter className="text-sm text-muted-foreground">
        Les actualités du site de l’université, synchronisées avec{" "}
        <a href={d.source_url} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 items-center gap-1 text-accent-text underline underline-offset-4">
          zurcher.edu.mg/actualites <ExternalLink className="size-3.5" aria-hidden />
        </a>
        {d.synced_at ? ` · mises à jour le ${syncedFmt.format(new Date(d.synced_at))}` : ""}.
      </p>
      <ul className="grid gap-4">
        {d.items.map((n) => (
          <li key={n.slug} data-enter>
            <SiteCard item={n} />
          </li>
        ))}
      </ul>
    </>
  );
}

function SiteCard({ item: n }: { item: SiteNewsSummary }) {
  const [failed, setFailed] = useState(false);
  // La liste charge la MINIATURE (~685 px) : 18 couvertures de 2 000 px pèseraient plusieurs mégaoctets sur un téléphone.
  const img = safeImageSrc(n.thumb_url) ?? safeImageSrc(n.image_url);
  return (
    <Link href={`/actualites/site/?slug=${encodeURIComponent(n.slug)}`} className="block rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
      <Card className="gap-0 overflow-hidden p-0 transition-colors hover:bg-accent">
        {img && !failed && (
          // eslint-disable-next-line @next/next/no-img-element -- image distante du site de l'université : next/image n'apporte rien dans un export statique
          <img src={img} alt="" loading="lazy" decoding="async" referrerPolicy="no-referrer" onError={() => setFailed(true)} className="aspect-video w-full object-cover" />
        )}
        <div className="grid gap-1.5 p-4">
          <p className="inline-flex items-center gap-1.5 text-xs font-bold text-accent-text">
            <Newspaper className="size-4" aria-hidden /> {n.category ?? "Actualités"}
          </p>
          <h2 className="text-lg leading-snug font-extrabold tracking-tight">{n.title}</h2>
          {n.excerpt && <p className="line-clamp-3 text-sm text-muted-foreground">{n.excerpt}</p>}
          <p className="text-xs text-muted-foreground">
            {n.published_on ? formatDate(n.published_on) : "Date non précisée"}
            {n.author ? ` · ${n.author}` : ""}
          </p>
        </div>
      </Card>
    </Link>
  );
}

// ------------------------------------------------------------------------- annonces du registrariat

function RegistrarSection({ data, failed, category, onCategory }: { data?: NewsList; failed: boolean; category: NewsCategory | "all"; onCategory: (c: NewsCategory | "all") => void }) {
  if (failed || !data) {
    return (
      <Card role="alert" className="gap-3 p-4">
        <p className="font-bold">Les annonces du registrariat n’ont pas pu se charger</p>
      </Card>
    );
  }
  if (data.total === 0) {
    return (
      <Card data-enter className="items-center gap-2 p-8 text-center">
        <Newspaper className="size-8 text-muted-foreground" aria-hidden />
        <p className="font-bold">Aucune annonce du registrariat pour le moment</p>
        <p className="text-sm text-muted-foreground">Les annonces officielles apparaîtront ici dès leur publication.</p>
      </Card>
    );
  }

  const shown = category === "all" ? data.items : data.items.filter((n) => n.category === category);
  const chips: { value: NewsCategory | "all"; label: string; count: number }[] = [
    { value: "all", label: "Toutes", count: data.total },
    ...CATEGORY_ORDER.filter((c) => data.counts[c] > 0).map((c) => ({ value: c, label: CATEGORY_LABEL[c], count: data.counts[c] })),
  ];

  return (
    <>
      {chips.length > 2 && (
        <div data-enter role="group" aria-label="Filtrer par catégorie" className="flex flex-wrap gap-2">
          {chips.map((c) => (
            <button
              key={c.value}
              type="button"
              aria-pressed={category === c.value}
              onClick={() => onCategory(c.value)}
              className="min-h-11 rounded-full border px-4 text-sm font-bold text-muted-foreground transition-colors hover:bg-accent aria-pressed:border-accent-text aria-pressed:bg-accent aria-pressed:text-accent-text"
            >
              {c.label} <span className="tabular-nums">({c.count})</span>
            </button>
          ))}
        </div>
      )}

      <ul className="grid gap-3" aria-live="polite">
        {shown.map((n) => (
          <li key={n.id} data-enter>
            <NewsCard item={n} />
          </li>
        ))}
      </ul>
    </>
  );
}

function NewsCard({ item: n }: { item: NewsSummary }) {
  const Icon = CATEGORY_ICON[n.category];
  return (
    <Link href={`/actualites/detail/?id=${n.id}`} className="block rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
      <Card className={cn("flex-row gap-0 overflow-hidden p-0 transition-colors hover:bg-accent", n.category === "urgent" && "border-destructive")}>
        {n.has_image ? (
          <NewsImage id={n.id} className="hidden h-auto w-28 shrink-0 sm:block" />
        ) : (
          <div aria-hidden className="hidden w-28 shrink-0 place-items-center bg-muted sm:grid">
            <Icon className="size-8 text-muted-foreground" />
          </div>
        )}
        <div className="grid min-w-0 flex-1 gap-1.5 p-4">
          <div className="flex flex-wrap items-center gap-2 text-xs font-bold">
            <span className={cn("inline-flex items-center gap-1.5", n.category === "urgent" ? "text-destructive" : "text-accent-text")}>
              <Icon className="size-4" aria-hidden /> {n.category_label}
            </span>
            {n.pinned && (
              <StatusChip tone="info">
                <Pin className="size-3" aria-hidden /> Épinglée
              </StatusChip>
            )}
          </div>
          <h2 className="text-base leading-snug font-extrabold tracking-tight">{n.title}</h2>
          <p className="line-clamp-2 text-sm text-muted-foreground">{n.excerpt}</p>
          <p className="text-xs text-muted-foreground">
            {n.published_on ? formatDate(n.published_on) : "Date non précisée"}
            {n.author ? ` · ${n.author}` : ""}
          </p>
        </div>
      </Card>
    </Link>
  );
}

function NewsSkeleton() {
  return (
    <div className="grid gap-3" aria-busy="true" aria-label="Chargement des actualités">
      <Skeleton className="h-56 w-full rounded-xl" />
      <Skeleton className="h-56 w-full rounded-xl" />
    </div>
  );
}

function NewsError({ error, onRetry }: { error: Error | null; onRetry: () => void }) {
  const offline = error instanceof ApiError && error.isOffline;
  return (
    <Card role="alert" className="gap-3 p-4">
      <p className="font-bold">{offline ? "Vous êtes hors ligne" : "Les actualités n’ont pas pu se charger"}</p>
      <p className="text-muted-foreground">{error instanceof ApiError && error.detail ? error.detail : "Réessayez dans un instant."}</p>
      <div>
        <Button onClick={onRetry}>Réessayer</Button>
      </div>
    </Card>
  );
}
