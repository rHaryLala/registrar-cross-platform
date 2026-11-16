"use client";

import { ChevronRight } from "lucide-react";
import Link from "next/link";
import { AppShell } from "@/components/app-shell";
import { StaffDashboard } from "@/components/staff-dashboard";
import { NAV_ICONS } from "@/components/teacher-nav";
import type { User } from "@/lib/auth";
import { staffEntries } from "@/lib/staff-nav";

/** Titres des cartes d'accueil quand ils diffèrent de l'étiquette courte du menu. */
const CARD_TITLE: Record<string, string> = { "/validations/": "Validation des notes", "/notes/": "Remise de notes" };

/** Accueil du personnel et des enseignants : les outils déjà disponibles dans la nouvelle application, selon ce que le compte peut ouvrir. */
export function TeacherHome({ user }: { user: User }) {
  const entries = staffEntries(user).filter((e) => e.href !== "/");
  return (
    <AppShell title="Registrar">
      <div className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        <h2 className="text-2xl font-extrabold tracking-tight">Bonjour {user.first_name || user.display_name}</h2>
        {user.dashboard && <StaffDashboard />}
        <ul className="grid gap-3">
          {entries.map((e) => {
            const Icon = NAV_ICONS[e.icon];
            return (
              <li key={e.href}>
                <Link href={e.href} className="flex items-center gap-3 rounded-xl bg-card p-4 ring-1 ring-foreground/10 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring">
                  <Icon className="size-6 shrink-0 text-accent-text" aria-hidden />
                  <div className="min-w-0 flex-1">
                    <p className="text-lg font-extrabold">{CARD_TITLE[e.href] ?? e.label}</p>
                    <p className="text-sm text-muted-foreground">{e.hint}</p>
                  </div>
                  <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                </Link>
              </li>
            );
          })}
        </ul>
      </div>
    </AppShell>
  );
}
