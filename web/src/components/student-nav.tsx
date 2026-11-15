"use client";

import { BookOpenCheck, CalendarCheck, House } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";

/**
 * Les pages de l'espace étudiant. On n'ajoute ici une entrée que quand sa page EXISTE : jamais de lien mort.
 * (Cible : Accueil · Notes · Présence · Messages · Plus, 5 entrées au plus.)
 */
const ITEMS = [
  { href: "/", label: "Accueil", Icon: House },
  { href: "/notes/", label: "Notes", Icon: BookOpenCheck },
  { href: "/presence/", label: "Présence", Icon: CalendarCheck },
] as const;

function useActive(): (href: string) => boolean {
  const pathname = usePathname() ?? "/";
  const current = pathname.replace(/\/+$/, "") || "/";
  return (href: string) => current === (href.replace(/\/+$/, "") || "/");
}

/** Barre du bas, dans la zone du pouce (téléphone). */
export function StudentTabBar() {
  const isActive = useActive();
  return (
    <nav aria-label="Navigation principale" className="fixed inset-x-0 bottom-0 z-30 border-t bg-background/95 backdrop-blur md:hidden">
      <ul className="mx-auto grid max-w-md grid-cols-3 pb-[env(safe-area-inset-bottom)]">
        {ITEMS.map(({ href, label, Icon }) => (
          <li key={href}>
            <Link
              href={href}
              aria-current={isActive(href) ? "page" : undefined}
              className={cn(
                "relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-bold text-muted-foreground transition-colors",
                "aria-[current=page]:text-accent-text",
              )}
            >
              {isActive(href) && <span aria-hidden className="absolute top-0 h-0.5 w-8 rounded-full bg-brand" />}
              <Icon className="size-5" aria-hidden />
              {label}
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
      {ITEMS.map(({ href, label, Icon }) => (
        <Link
          key={href}
          href={href}
          aria-current={isActive(href) ? "page" : undefined}
          className="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-sm font-bold text-muted-foreground transition-colors hover:bg-accent aria-[current=page]:bg-accent aria-[current=page]:text-accent-text"
        >
          <Icon className="size-4" aria-hidden />
          {label}
        </Link>
      ))}
    </nav>
  );
}
