/** Formes des réponses « Actualités » (/api/v1/me/news…). Le contenu est du TEXTE BRUT : l'écran l'affiche comme tel, jamais comme du HTML. */

export type NewsCategory = "info" | "event" | "academic" | "urgent" | "sport" | "culture";

export type NewsSummary = {
  id: number;
  title: string;
  excerpt: string;
  category: NewsCategory;
  category_label: string;
  pinned: boolean;
  published_on: string | null;
  author: string | null;
  has_image: boolean;
};

export type NewsList = { items: NewsSummary[]; counts: Record<NewsCategory, number>; total: number };

export type NewsDetail = NewsSummary & { content: string; expires_on: string | null };

export const CATEGORY_ORDER: NewsCategory[] = ["info", "event", "academic", "urgent", "sport", "culture"];

export const CATEGORY_LABEL: Record<NewsCategory, string> = {
  info: "Information",
  event: "Événement",
  academic: "Académique",
  urgent: "Urgent",
  sport: "Sport",
  culture: "Culture",
};
