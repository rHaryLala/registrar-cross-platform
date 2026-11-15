"use client";

import { Fragment, useState } from "react";
import { safeHref, safeImageSrc, type Block, type Inline } from "@/lib/site-news";
import { cn } from "@/lib/utils";

/**
 * Affiche le corps d'un article du site public. AUCUN `dangerouslySetInnerHTML` : chaque bloc est rendu par React, qui échappe
 * tout. Les liens et images sont revérifiés ici (voir safeHref / safeImageSrc) même si le serveur les a déjà filtrés.
 */
export function SiteNewsBlocks({ blocks }: { blocks: Block[] }) {
  return (
    <div className="grid gap-4 leading-relaxed break-words">
      {blocks.map((b, i) => (
        <BlockView key={i} block={b} />
      ))}
    </div>
  );
}

function BlockView({ block: b }: { block: Block }) {
  switch (b.type) {
    case "paragraph":
      return (
        <p>
          <Inlines items={b.inlines} />
        </p>
      );
    case "quote":
      return (
        <blockquote className="border-l-4 border-accent-text pl-4 text-muted-foreground italic">
          <Inlines items={b.inlines} />
        </blockquote>
      );
    case "heading": {
      // Le titre de l'article est un h2 : les intertitres commencent à h3.
      const Tag = (b.level === 2 ? "h3" : b.level === 3 ? "h4" : "h5") as "h3" | "h4" | "h5";
      return (
        <Tag className={cn("font-extrabold tracking-tight", b.level === 2 ? "mt-2 text-xl" : "text-lg")}>
          <Inlines items={b.inlines} />
        </Tag>
      );
    }
    case "list": {
      const List = b.ordered ? "ol" : "ul";
      return (
        <List className={cn("grid gap-1.5 pl-6", b.ordered ? "list-decimal" : "list-disc")}>
          {b.items.map((it, i) => (
            <li key={i}>
              <Inlines items={it} />
            </li>
          ))}
        </List>
      );
    }
    case "image":
      return <FigureImage src={b.src} alt={b.alt} width={b.width} height={b.height} />;
  }
}

function Inlines({ items }: { items: Inline[] }) {
  return (
    <>
      {items.map((p, i) => {
        if ("br" in p) return <br key={i} />;
        const href = safeHref(p.href);
        let node: React.ReactNode = p.text;
        if (p.bold) node = <strong>{node}</strong>;
        if (p.italic) node = <em>{node}</em>;
        if (href) {
          node = (
            <a href={href} target={href.startsWith("mailto:") ? undefined : "_blank"} rel="noopener noreferrer nofollow" className="text-accent-text underline underline-offset-4 break-words">
              {node}
            </a>
          );
        }
        return <Fragment key={i}>{node}</Fragment>;
      })}
    </>
  );
}

/** Image du site : chargée sans envoyer l'adresse de l'application (`no-referrer`), retirée si elle ne charge pas. */
function FigureImage({ src, alt, width, height }: { src: string; alt: string; width: number | null; height: number | null }) {
  const [failed, setFailed] = useState(false);
  const safe = safeImageSrc(src);
  if (!safe || failed) return null;
  return (
    <figure>
      {/* eslint-disable-next-line @next/next/no-img-element -- image distante du site de l'université : next/image n'apporte rien dans un export statique */}
      <img
        src={safe}
        alt={alt}
        width={width ?? undefined}
        height={height ?? undefined}
        loading="lazy"
        decoding="async"
        referrerPolicy="no-referrer"
        onError={() => setFailed(true)}
        className="h-auto w-full rounded-xl border"
      />
    </figure>
  );
}
