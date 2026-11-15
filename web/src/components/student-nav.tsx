"use client";

import { BookOpenCheck, CalendarCheck, Ellipsis, FileText, House, IdCard, Newspaper, Trophy } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";

type Item = { href: string; label: string; Icon: typeof House; /** adresses qui allument cet onglet */ owns?: string[] };

/**
 * Barre du bas : 5 entrées au plus (téléphone). Tout ce qui ne tient pas passe par « Plus » (page /plus/), qui garde son
 * onglet allumé sur ses sous-pages. On n'ajoute ici une entrée que quand sa page EXISTE : jamais de lien mort.
 */
const BAR: Item[] = [
  { href: "/", label: "Accueil", Icon: House },
  { href: "/notes/", label: "Notes", Icon: BookOpenCheck },
  { href: "/presence/", label: "Présence", Icon: CalendarCheck },
  { href: "/inscriptions/", label: "Inscriptions", Icon: FileText },
  { href: "/plus/", label: "Plus", Icon: Ellipsis, owns: ["/actualites/", "/informations/", "/quiz/"] },
];

/** Pages rangées sous « Plus » : listées dans l'en-tête sur ordinateur, et sur la page /plus/ sur téléphone. */
export const MORE_PAGES = [
  { href: "/actualites/", label: "Actualités", Icon: Newspaper, hint: "Les actualités du site de l’université et les annonces du registrariat." },
  { href: "/informations/", label: "Mes informations", Icon: IdCard, hint: "Votre fiche : identité, contact, études, famille, garant." },
  { href: "/quiz/", label: "Quiz", Icon: Trophy, hint: "Testez vos connaissances et comparez-vous au classement." },
] as const;

/** Sur ordinateur, l'en-tête a la place de tout montrer : les pages de « Plus » y sont des liens directs. */
const HEADER: Item[] = [...BAR.slice(0, 4), ...MORE_PAGES.map(({ href, label, Icon }) => ({ href, label, Icon }))];

function useActive(): (item: Item) => boolean {
  const pathname = usePathname() ?? "/";
  const current = pathname.replace(/\/+$/, "") || "/";
  const matches = (href: string) => {
    const target = href.replace(/\/+$/, "") || "/";
    // Une sous-page (/quiz/jouer) garde son onglet allumé ; l'accueil, lui, ne correspond qu'à lui-même.
    return target === "/" ? current === "/" : current === target || current.startsWith(`${target}/`);
  };
  return (item) => matches(item.href) || (item.owns ?? []).some(matches);
}

/** Barre du bas, dans la zone du pouce (téléphone). */
export function StudentTabBar() {
  const isActive = useActive();
  return (
    <nav aria-label="Navigation principale" className="fixed inset-x-0 bottom-0 z-30 border-t bg-background/95 backdrop-blur md:hidden">
      <ul className="mx-auto grid max-w-md grid-cols-5 pb-[env(safe-area-inset-bottom)]">
        {BAR.map((item) => (
          <li key={item.href}>
            <Link
              href={item.href}
              aria-current={isActive(item) ? "page" : undefined}
              className={cn(
                "relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-bold text-muted-foreground transition-colors",
                "aria-[current=page]:text-accent-text",
              )}
            >
              {isActive(item) && <span aria-hidden className="absolute top-0 h-0.5 w-8 rounded-full bg-brand" />}
              <item.Icon className="size-5" aria-hidden />
              {item.label}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}

/** Liens dans l'en-tête (ordinateur). */
export function StudentNavInline() {
  const isActive = useActive();
  return (
    <nav aria-label="Navigation principale" className="hidden items-center gap-1 md:flex">
      {HEADER.map((item) => (
        <Link
          key={item.href}
          href={item.href}
          aria-current={isActive(item) ? "page" : undefined}
          className="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-sm font-bold text-muted-foreground transition-colors hover:bg-accent aria-[current=page]:bg-accent aria-[current=page]:text-accent-text"
        >
          <item.Icon className="size-4" aria-hidden />
          {item.label}
        </Link>
      ))}
    </nav>
  );
}
