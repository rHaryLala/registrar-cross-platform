"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { AppShell } from "@/components/app-shell";
import { StudentHome } from "@/components/student-home";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { ApiError } from "@/lib/api";
import { useMe, type Role, type User } from "@/lib/auth";

const ROLE_LABEL: Record<Role, string> = {
  superadmin: "du superadministrateur",
  admin: "de l’administration",
  registrar: "du registrariat",
  comptabilite: "de la comptabilité",
  media: "du service média",
  chef_mention: "du chef de mention",
  teacher: "de l’enseignant",
  student: "de l’étudiant",
  work_education: "du Work Education",
};

const LEGACY_URL = process.env.NEXT_PUBLIC_LEGACY_URL ?? "";

/** Aiguille la personne : non connectée → connexion ; étudiant → son accueil ; autre rôle → page d'attente honnête. */
export function HomeGate() {
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

  if (me.data.role === "student") return <StudentHome user={me.data} />;
  return <ComingSoon user={me.data} />;
}

function ComingSoon({ user }: { user: User }) {
  const space = user.role ? ROLE_LABEL[user.role] : "de votre compte";
  return (
    <AppShell title="Registrar">
      <div className="mx-auto grid w-full max-w-xl gap-6 p-4 md:p-6">
        <h2 className="text-2xl font-extrabold tracking-tight">Bonjour {user.first_name || user.display_name}</h2>
        <Card className="gap-3 p-4">
          <p className="font-bold">L’espace {space} arrive bientôt</p>
          <p className="text-muted-foreground">
            Cet espace n’est pas encore disponible dans la nouvelle application. Vos outils habituels restent accessibles dans l’ancienne version.
          </p>
          {LEGACY_URL && (
            <div>
              <Button render={<a href={LEGACY_URL} />} size="lg">
                Ouvrir l’ancienne version
              </Button>
            </div>
          )}
        </Card>
      </div>
    </AppShell>
  );
}
