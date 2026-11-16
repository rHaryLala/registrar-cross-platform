"use client";

import { LogOut, WifiOff } from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import { toast } from "sonner";
import { Seal } from "@/components/brand";
import { PendingChanges } from "@/components/pending-changes";
import { StudentNavInline, StudentTabBar } from "@/components/student-nav";
import { TeacherNavInline, TeacherTabBar } from "@/components/teacher-nav";
import { Button } from "@/components/ui/button";
import { useLogout, useMe } from "@/lib/auth";
import { warmTeacherShell } from "@/lib/offline-shell";
import { pendingCount, purgeLocalAccount, startSync } from "@/lib/offline-sync";
import { cn } from "@/lib/utils";

/** Vrai tant que le navigateur dit avoir du réseau. N'affiche « hors ligne » que quand c'est vrai. */
function useOnline(): boolean {
  const [online, setOnline] = useState(true);
  useEffect(() => {
    const update = () => setOnline(navigator.onLine);
    update();
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    return () => {
      window.removeEventListener("online", update);
      window.removeEventListener("offline", update);
    };
  }, []);
  return online;
}

export function AppShell({ title, children }: { title: string; children: ReactNode }) {
  const router = useRouter();
  const me = useMe();
  const logout = useLogout();
  const online = useOnline();
  const qc = useQueryClient();
  const isStudent = me.data?.role === "student";
  const isTeacher = me.data?.teacher_space === true;
  const hasStaffSpace = isTeacher || me.data?.review_space === true || me.data?.attendance_space === true;
  const teacherId = isTeacher ? (me.data?.id ?? null) : null;

  // Enseignant : les changements faits hors ligne partent tout seuls (retour du réseau, retour sur l'onglet, toutes les 30 s).
  useEffect(() => (teacherId === null ? undefined : startSync(teacherId, qc)), [teacherId, qc]);
  // Et ses pages sont gardées sur l'appareil pour s'ouvrir sans réseau (l'appel dans un amphi sans connexion).
  useEffect(() => {
    if (teacherId !== null) void warmTeacherShell();
  }, [teacherId]);

  return (
    <div className="flex min-h-dvh flex-col">
      <a
        href="#contenu"
        className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-primary focus:px-4 focus:py-2 focus:font-bold focus:text-primary-foreground"
      >
        Aller au contenu
      </a>

      <header className="sticky top-0 z-20 flex min-h-16 items-center gap-3 border-b bg-background/95 px-4 backdrop-blur md:px-6">
        <Seal size={32} />
        <h1 className="truncate text-xl font-extrabold tracking-tight md:flex-none">{title}</h1>
        <div className="flex-1">
          {isStudent && <StudentNavInline />}
          {hasStaffSpace && <TeacherNavInline />}
        </div>
        {!online && (
          <span role="status" className="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-warning px-2.5 py-1 text-xs font-bold text-warning">
            <WifiOff className="size-3.5" aria-hidden /> Hors ligne
          </span>
        )}
        {isTeacher && <PendingChanges />}
        {me.data && (
          <Button
            variant="ghost"
            size="icon"
            aria-label={`Se déconnecter (${me.data.display_name})`}
            title="Se déconnecter"
            disabled={logout.isPending}
            onClick={() => {
              // Des changements faits hors ligne ne sont pas encore partis : se déconnecter les effacerait de cet appareil.
              if (isTeacher && pendingCount() > 0 && !window.confirm(`${pendingCount()} changement(s) fait(s) hors ligne ne sont pas encore enregistrés. Se déconnecter les perdrait. Se déconnecter quand même ?`)) return;
              const account = me.data?.id;
              logout.mutate(undefined, {
                onSuccess: () => {
                  if (account !== undefined) void purgeLocalAccount(account);
                  router.replace("/login/");
                },
                onError: () => toast.error("Déconnexion impossible", { description: "Vérifiez votre connexion, puis réessayez. Votre session reste ouverte." }),
              });
            }}
          >
            <LogOut aria-hidden />
          </Button>
        )}
      </header>

      {/* pb-24 : la barre du bas ne doit jamais cacher la fin du contenu */}
      <main id="contenu" tabIndex={-1} className={cn("flex-1 outline-none", (isStudent || hasStaffSpace) && "pb-24 md:pb-0")}>
        {children}
      </main>
      {isStudent && <StudentTabBar />}
      {hasStaffSpace && <TeacherTabBar />}
    </div>
  );
}
