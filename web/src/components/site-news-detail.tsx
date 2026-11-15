"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, ExternalLink, FileX2, Newspaper } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useRef, useState } from "react";
import { AppShell } from "@/components/app-shell";
import { SiteNewsBlocks } from "@/components/site-news-blocks";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import { formatLongDate } from "@/lib/format";
import { safeImageSrc, type SiteNewsDetail as Detail } from "@/lib/site-news";
import { cn } from "@/lib/utils";

/** Un article du site de l'université. L'identifiant (le « slug ») vient de l'adresse (?slug=…) : le site est statique. */
export function SiteNewsDetail() {
  const raw = useSearchParams().get("slug");
  const slug = raw && /^[a-z0-9][a-z0-9-]{0,118}$/.test(raw) ? raw : null;

  return (
    <AppShell title="Actualités">
      <div className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        <Link href="/actualites/" className="inline-flex min-h-11 w-fit items-center gap-2 text-sm font-bold text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" aria-hidden /> Toutes les actualités
        </Link>
        {slug === null ? <NotFound /> : <Loader slug={slug} />}
      </div>
    </AppShell>
  );
}

function Loader({ slug }: { slug: string }) {
  const scope = useRef<HTMLDivElement>(null);
  const q = useQuery({
    queryKey: ["me", "site-news", slug],
    queryFn: async () => (await api<{ data: Detail }>(`/api/v1/me/site-news/${slug}`)).data,
    retry: (count, error) => !(error instanceof ApiError && error.status === 404) && count < 2,
  });
  useEntrance(scope, "[data-enter]", q.isSuccess);

  if (q.isPending) {
    return (
      <div className="grid gap-5" aria-busy="true" aria-label="Chargement de l’article">
        <Skeleton className="h-56 w-full rounded-xl" />
        <Skeleton className="h-48 w-full rounded-xl" />
      </div>
    );
  }
  if (q.isError) {
    if (q.error instanceof ApiError && q.error.status === 404) return <NotFound />;
    const offline = q.error instanceof ApiError && q.error.isOffline;
    return (
      <Card role="alert" className="gap-3 p-4">
        <p className="font-bold">{offline ? "Vous êtes hors ligne" : "L’article n’a pas pu se charger"}</p>
        <p className="text-muted-foreground">{q.error instanceof ApiError && q.error.detail ? q.error.detail : "Réessayez dans un instant."}</p>
        <div>
          <Button onClick={() => q.refetch()}>Réessayer</Button>
        </div>
      </Card>
    );
  }
  return (
    <div ref={scope} className="grid gap-5">
      <Article n={q.data} />
    </div>
  );
}

function Article({ n }: { n: Detail }) {
  const [coverFailed, setCoverFailed] = useState(false);
  const cover = safeImageSrc(n.image_url);
  return (
    <>
      {cover && !coverFailed && (
        // eslint-disable-next-line @next/next/no-img-element -- image distante du site de l'université : next/image n'apporte rien dans un export statique
        <img src={cover} alt="" referrerPolicy="no-referrer" onError={() => setCoverFailed(true)} className="max-h-80 w-full rounded-xl border object-cover" />
      )}
      <Card data-enter className="gap-4 p-5">
        <p className="inline-flex items-center gap-1.5 text-xs font-bold text-accent-text">
          <Newspaper className="size-4" aria-hidden /> {n.category ?? "Actualités"}
        </p>
        <h2 className="text-2xl leading-tight font-extrabold tracking-tight break-words">{n.title}</h2>
        <p className="text-sm text-muted-foreground">
          {n.published_on ? formatLongDate(n.published_on) : "Date non précisée"}
          {n.author ? ` · ${n.author}` : ""}
        </p>
        <SiteNewsBlocks blocks={n.blocks} />
        <div className="grid gap-2 border-t pt-4">
          <a href={n.url} target="_blank" rel="noopener noreferrer" className={cn(buttonVariants({ variant: "outline", size: "lg" }), "w-full md:w-fit")}>
            <ExternalLink aria-hidden /> Lire sur le site de l’université
          </a>
          <p className="text-xs text-muted-foreground">Article publié sur zurcher.edu.mg ; cette copie est synchronisée automatiquement.</p>
        </div>
      </Card>
    </>
  );
}

function NotFound() {
  return (
    <Card role="alert" className="items-center gap-2 p-8 text-center">
      <FileX2 className="size-8 text-muted-foreground" aria-hidden />
      <p className="font-bold">Article introuvable</p>
      <p className="text-sm text-muted-foreground">Cet article n’existe pas ou n’est plus affiché.</p>
      <Link href="/actualites/" className={cn(buttonVariants(), "mt-2")}>
        Voir les actualités
      </Link>
    </Card>
  );
}
