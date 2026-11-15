"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, FileX2, Pin } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Fragment, useRef } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { CATEGORY_ICON, NewsImage } from "@/components/student-news";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import { formatLongDate } from "@/lib/format";
import { splitLinks } from "@/lib/linkify";
import type { NewsDetail as Detail } from "@/lib/news";
import { cn } from "@/lib/utils";

/** Une annonce. L'identifiant vient de l'adresse (?id=12) : le site est statique, sans route dynamique. */
export function NewsDetail() {
  const raw = useSearchParams().get("id");
  const id = raw && /^\d+$/.test(raw) ? Number(raw) : null;

  return (
    <AppShell title="Actualités">
      <div className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        <Link href="/actualites/" className="inline-flex min-h-11 w-fit items-center gap-2 text-sm font-bold text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" aria-hidden /> Toutes les actualités
        </Link>
        {id === null ? <NotFound /> : <Loader id={id} />}
      </div>
    </AppShell>
  );
}

function Loader({ id }: { id: number }) {
  const scope = useRef<HTMLDivElement>(null);
  const q = useQuery({
    queryKey: ["me", "news", id],
    queryFn: async () => (await api<{ data: Detail }>(`/api/v1/me/news/${id}`)).data,
    // Une annonce retirée ou expirée (404) ne revient pas en réessayant.
    retry: (count, error) => !(error instanceof ApiError && error.status === 404) && count < 2,
  });
  useEntrance(scope, "[data-enter]", q.isSuccess);

  if (q.isPending) {
    return (
      <div className="grid gap-5" aria-busy="true" aria-label="Chargement de l’annonce">
        <Skeleton className="h-48 w-full rounded-xl" />
        <Skeleton className="h-40 w-full rounded-xl" />
      </div>
    );
  }
  if (q.isError) {
    if (q.error instanceof ApiError && q.error.status === 404) return <NotFound />;
    const offline = q.error instanceof ApiError && q.error.isOffline;
    return (
      <Card role="alert" className="gap-3 p-4">
        <p className="font-bold">{offline ? "Vous êtes hors ligne" : "L’annonce n’a pas pu se charger"}</p>
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
  const Icon = CATEGORY_ICON[n.category];
  return (
    <>
      {n.has_image && <NewsImage id={n.id} eager className="max-h-80 w-full rounded-xl border" />}
      <Card data-enter className="gap-4 p-5">
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
        <h2 className="text-2xl leading-tight font-extrabold tracking-tight break-words">{n.title}</h2>
        <p className="text-sm text-muted-foreground">
          {n.published_on ? formatLongDate(n.published_on) : "Date non précisée"}
          {n.author ? ` · ${n.author}` : ""}
        </p>
        <Body text={n.content} />
        {n.expires_on && <p className="border-t pt-3 text-xs text-muted-foreground">Affichée jusqu’au {formatLongDate(n.expires_on).toLocaleLowerCase("fr")}.</p>}
      </Card>
    </>
  );
}

/** Texte brut : les sauts de ligne sont gardés, les adresses http(s) deviennent des liens, tout le reste reste du TEXTE (React échappe). */
function Body({ text }: { text: string }) {
  return (
    <div className="leading-relaxed whitespace-pre-line break-words">
      {splitLinks(text).map((s, i) =>
        s.kind === "link" ? (
          <a key={i} href={s.value} target="_blank" rel="noopener noreferrer nofollow" className="text-accent-text underline underline-offset-4 break-all">
            {s.value}
          </a>
        ) : (
          <Fragment key={i}>{s.value}</Fragment>
        ),
      )}
    </div>
  );
}

function NotFound() {
  return (
    <Card role="alert" className="items-center gap-2 p-8 text-center">
      <FileX2 className="size-8 text-muted-foreground" aria-hidden />
      <p className="font-bold">Annonce introuvable</p>
      <p className="text-sm text-muted-foreground">Cette annonce n’existe pas ou n’est plus affichée.</p>
      <Link href="/actualites/" className={cn(buttonVariants(), "mt-2")}>
        Voir les actualités
      </Link>
    </Card>
  );
}
