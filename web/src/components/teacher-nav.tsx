"use client";

import { BadgeCheck, BookOpenCheck, CalendarCheck, CalendarClock, ClipboardCheck, Ellipsis, FileUser, GraduationCap, House, Library, UserRoundX, UsersRound, Wallet, type LucideIcon } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useMe } from "@/lib/auth";
import { matches, staffBar, staffEntries, type NavEntry, type NavIcon } from "@/lib/staff-nav";
import { cn } from "@/lib/utils";

export const NAV_ICONS: Record<NavIcon, LucideIcon> = {
  home: House,
  presence: CalendarCheck,
  notes: BookOpenCheck,
  review: ClipboardCheck,
  attendance: UserRoundX,
  students: UsersRound,
  courses: Library,
  teachers: GraduationCap,
  applications: FileUser,
  reenrolment: BadgeCheck,
  balances: Wallet,
  deadlines: CalendarClock,
  more: Ellipsis,
};

/** Les pages du compte : seulement celles dont la page EXISTE et que ce compte peut ouvrir (jamais de lien mort). */
export function useStaffEntries(): NavEntry[] {
  const me = useMe();
  return staffEntries(me.data);
}

function useActive(more: readonly NavEntry[] = []): (item: NavEntry) => boolean {
  const pathname = usePathname() ?? "/";
  return (item) => matches(item.href, pathname) || (item.icon === "more" && more.some((m) => matches(m.href, pathname)));
}

/** Barre du bas (téléphone) : 5 entrées au plus, le reste sous « Plus ». */
export function TeacherTabBar() {
  const { bar, more } = staffBar(useStaffEntries());
  const isActive = useActive(more);
  return (
    <nav aria-label="Navigation principale" className="fixed inset-x-0 bottom-0 z-30 border-t bg-background/95 backdrop-blur md:hidden">
      <ul className="mx-auto grid max-w-md pb-[env(safe-area-inset-bottom)]" style={{ gridTemplateColumns: `repeat(${bar.length}, minmax(0, 1fr))` }}>
        {bar.map((item) => {
          const Icon = NAV_ICONS[item.icon];
          return (
            <li key={item.href}>
              <Link
                href={item.href}
                aria-current={isActive(item) ? "page" : undefined}
                className={cn("relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-bold text-muted-foreground transition-colors", "aria-[current=page]:text-accent-text")}
              >
                {isActive(item) && <span aria-hidden className="absolute top-0 h-0.5 w-8 rounded-full bg-brand" />}
                <Icon className="size-5" aria-hidden />
                {item.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

/** Liens dans l'en-tête (ordinateur) : tout à plat sur grand écran, la barre (avec « Plus ») sur écran moyen. */
export function TeacherNavInline() {
  const entries = useStaffEntries();
  const { bar, more } = staffBar(entries);
  const isActive = useActive(more);
  const link = (item: NavEntry) => {
    const Icon = NAV_ICONS[item.icon];
    return (
      <Link
        key={item.href}
        href={item.href}
        aria-current={isActive(item) ? "page" : undefined}
        className="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-sm font-bold text-muted-foreground transition-colors hover:bg-accent aria-[current=page]:bg-accent aria-[current=page]:text-accent-text"
      >
        <Icon className="size-4" aria-hidden />
        {item.label}
      </Link>
    );
  };
  return (
    <>
      <nav aria-label="Navigation principale" className="hidden items-center gap-1 md:flex xl:hidden">
        {bar.map(link)}
      </nav>
      <nav aria-label="Navigation principale" className="hidden items-center gap-1 xl:flex">
        {entries.map(link)}
      </nav>
    </>
  );
}
