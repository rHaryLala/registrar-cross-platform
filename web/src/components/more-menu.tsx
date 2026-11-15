"use client";

import { ChevronRight } from "lucide-react";
import Link from "next/link";
import { AppShell } from "@/components/app-shell";
import { MORE_PAGES } from "@/components/student-nav";
import { Card } from "@/components/ui/card";

/** « Plus » : les pages qui ne tiennent pas dans la barre du bas. Chaque entrée dit à quoi elle sert. */
export function MoreMenu() {
  return (
    <AppShell title="Plus">
      <div className="mx-auto grid w-full max-w-3xl gap-3 p-4 md:p-6">
        <Card className="gap-0 divide-y p-0">
          {MORE_PAGES.map(({ href, label, Icon, hint }) => (
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
