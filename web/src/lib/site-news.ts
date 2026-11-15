/**
 * Actualités du SITE public de l'université (copie locale tenue par `php artisan news:sync`).
 * Le corps est une liste de BLOCS déjà assainis par le serveur : l'écran ne reçoit jamais de HTML et n'en injecte jamais.
 */

export type SiteNewsSummary = {
  slug: string;
  title: string;
  excerpt: string | null;
  category: string | null;
  image_url: string | null;
  /** miniature pour la liste (~685 px) ; absente : on retombe sur image_url */
  thumb_url: string | null;
  author: string | null;
  published_on: string | null;
  url: string;
};

export type SiteNewsList = { items: SiteNewsSummary[]; total: number; synced_at: string | null; source_url: string };

export type Inline = { text: string; bold?: boolean; italic?: boolean; href?: string } | { br: true };

export type Block =
  | { type: "paragraph" | "quote"; inlines: Inline[] }
  | { type: "heading"; level: 2 | 3 | 4; inlines: Inline[] }
  | { type: "list"; ordered: boolean; items: Inline[][] }
  | { type: "image"; src: string; alt: string; width: number | null; height: number | null };

export type SiteNewsDetail = SiteNewsSummary & { blocks: Block[]; synced_at: string | null };

/**
 * Défense en profondeur : le serveur a déjà filtré, mais l'écran ne fait confiance à personne. Un lien n'est rendu que s'il est
 * http(s) ou mailto ; une image que si elle est en https. Tout le reste s'affiche comme du texte (ou pas du tout).
 */
export function safeHref(href: string | undefined): string | undefined {
  if (!href) return undefined;
  return /^(https?:\/\/|mailto:)/i.test(href) && !/[\s<>"']/.test(href) ? href : undefined;
}

export function safeImageSrc(src: string | null | undefined): string | undefined {
  return src && /^https:\/\//i.test(src) && !/[\s<>"']/.test(src) ? src : undefined;
}
