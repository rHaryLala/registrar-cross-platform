"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { AppShell } from "@/components/app-shell";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { ApiError } from "@/lib/api";
import { useMe } from "@/lib/auth";

/**
 * Garde d'une page réservée aux étudiants : non connecté → connexion ; autre rôle → explication claire ;
 * serveur injoignable → nouvel essai. Le droit réel est vérifié par l'API (403) : cette garde n'est qu'une courtoisie.
 */
export function RequireStudent({ children }: { children: ReactNode }) {
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

  if (me.data.role !== "student") {
    return (
      <AppShell title="Registrar">
        <div className="mx-auto max-w-xl p-4 md:p-6">
          <Card className="gap-3 p-4">
            <p className="font-bold">Cette page est réservée aux étudiants</p>
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
