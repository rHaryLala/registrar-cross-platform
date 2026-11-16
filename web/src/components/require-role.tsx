"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { AppShell } from "@/components/app-shell";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { ApiError } from "@/lib/api";
import { useMe, type Role } from "@/lib/auth";

/**
 * Garde d'une page réservée à certains rôles : non connecté → connexion ; autre rôle → explication claire ;
 * serveur injoignable → nouvel essai. Le droit réel est vérifié par l'API (403) : cette garde n'est qu'une courtoisie.
 *
 * `reserved` complète « Cette page est réservée… » (ex. « aux étudiants »).
 */
export function RequireRole({ allow, reserved, teacherSpace = false, children }: { allow: readonly Role[]; reserved: string; /** laisse aussi entrer tout compte qui a un espace enseignant */ teacherSpace?: boolean; children: ReactNode }) {
  const router = useRouter();
  const me = useMe();

  useEffect(() => {
    if (me.data === null) router.replace("/login/");
  }, [me.data, router]);

  if (me.isError) {
    return (
      <AppShell title="Registrar">
        <div className="mx-auto max-w-xl p-4">
          <Card role="alert" className="gap-3 p-4">
            <p className="font-bold">{me.error instanceof ApiError && me.error.isOffline ? "Vous êtes hors ligne" : "Impossible de joindre le serveur"}</p>
            <p className="text-muted-foreground">Vérifiez votre connexion, puis réessayez.</p>
            <div>
              <Button onClick={() => me.refetch()}>Réessayer</Button>
            </div>
          </Card>
        </div>
      </AppShell>
    );
  }

  if (me.isPending || me.data === null) {
    return (
      <div className="mx-auto grid min-h-dvh w-full max-w-3xl content-start gap-6 p-4" aria-busy="true" aria-label="Chargement">
        <Skeleton className="h-12 w-full" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }

  const allowed = (me.data.role !== null && allow.includes(me.data.role)) || (teacherSpace && me.data.teacher_space);
  if (!allowed) {
    return (
      <AppShell title="Registrar">
        <div className="mx-auto max-w-xl p-4 md:p-6">
          <Card className="gap-3 p-4">
            <p className="font-bold">Cette page est réservée {reserved}</p>
            <p className="text-muted-foreground">Votre espace n’est pas encore disponible dans la nouvelle application.</p>
            <div>
              <Button render={<Link href="/" />}>Retour à l’accueil</Button>
            </div>
          </Card>
        </div>
      </AppShell>
    );
  }

  return <>{children}</>;
}

export const TEACHER_ROLES: readonly Role[] = ["teacher", "chef_mention"];

/** Le registrariat (niveaux 1 à 3) : validation des notes. Le droit réel est vérifié par l'API. */
export function RequireReviewer({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={["superadmin", "admin", "registrar"]} reserved="au registrariat">
      {children}
    </RequireRole>
  );
}

/** L'assiduité du registrariat : superadmin et registrariat seulement (pas l'administration), comme l'ancienne page. */
export function RequireAttendanceReviewer({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={["superadmin", "registrar"]} reserved="au registrariat">
      {children}
    </RequireRole>
  );
}

/** Page « Plus » : l’étudiant (ses pages) et le personnel (celles qui ne tiennent pas dans la barre du bas). */
export function RequirePlus({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={["student", "superadmin", "admin", "registrar", "comptabilite", "media", "chef_mention"]} teacherSpace reserved="aux comptes de l’application">
      {children}
    </RequireRole>
  );
}

/** Liste des étudiants : tout le personnel (pas l’étudiant, l’enseignant ni le Work Education). */
export function RequireStudentsDirectory({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={["superadmin", "admin", "registrar", "comptabilite", "media", "chef_mention"]} reserved="au personnel">
      {children}
    </RequireRole>
  );
}

/** Création d’un étudiant : niveaux 1 à 3 (superadmin, administration, registrariat). */
export function RequireStudentCreation({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={["superadmin", "admin", "registrar"]} reserved="au registrariat">
      {children}
    </RequireRole>
  );
}

export function RequireTeacher({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={TEACHER_ROLES} teacherSpace reserved="aux enseignants">
      {children}
    </RequireRole>
  );
}

/** Finance : autorisation de réinscription. Niveaux 1, 3 et 4 (superadmin, registrariat, comptabilité) ; le droit réel et celui de chaque colonne sont vérifiés par l’API. */
export function RequireFinance({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={["superadmin", "registrar", "comptabilite"]} reserved="au registrariat et à la comptabilité">
      {children}
    </RequireRole>
  );
}

/** Solde de compte : niveaux 1 à 4 (superadmin, administration, registrariat, comptabilité). Le droit réel est vérifié par l’API. */
export function RequireBalances({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={["superadmin", "admin", "registrar", "comptabilite"]} reserved="au registrariat et à la comptabilité">
      {children}
    </RequireRole>
  );
}

/** Échéances de paiement : niveaux 1 à 4 (superadmin, administration, registrariat, comptabilité). Le droit réel est vérifié par l’API. */
export function RequireDeadlines({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={["superadmin", "admin", "registrar", "comptabilite"]} reserved="au registrariat et à la comptabilité">
      {children}
    </RequireRole>
  );
}
