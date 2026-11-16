"use client";

import { ChevronRight } from "lucide-react";
import Link from "next/link";
import { AppShell } from "@/components/app-shell";
import { MORE_PAGES } from "@/components/student-nav";
import { NAV_ICONS, useStaffEntries } from "@/components/teacher-nav";
import { Card } from "@/components/ui/card";
import { useMe } from "@/lib/auth";
import { staffBar } from "@/lib/staff-nav";

/** « Plus » : les pages qui ne tiennent pas dans la barre du bas (étudiant : ses pages ; personnel : celles rangées sous « Plus »). Chaque entrée dit à quoi elle sert. */
export function MoreMenu() {
  const me = useMe();
  const staff = staffBar(useStaffEntries()).more;
  const pages = me.data?.role === "student" ? MORE_PAGES.map((p) => ({ ...p })) : staff.map((e) => ({ href: e.href, label: e.label, hint: e.hint, Icon: NAV_ICONS[e.icon] }));
  return (
    <AppShell title="Plus">
      <div className="mx-auto grid w-full max-w-3xl gap-3 p-4 md:p-6">
        {pages.length === 0 && <Card className="p-4 text-sm text-muted-foreground">Toutes vos pages sont déjà dans la barre du bas.</Card>}
        <Card className="gap-0 divide-y p-0">
          {pages.map(({ href, label, Icon, hint }) => (
            <Link key={href} href={href} className="flex min-h-16 items-center gap-4 p-4 transition-colors hover:bg-accent focus-visible:bg-accent">
              <Icon className="size-6 shrink-0 text-accent-text" aria-hidden />
              <span className="min-w-0 flex-1">
                <span className="block font-extrabold">{label}</span>
                <span className="block text-sm text-muted-foreground">{hint}</span>
              </span>
              <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
            </Link>
          ))}
        </Card>
      </div>
    </AppShell>
  );
}
