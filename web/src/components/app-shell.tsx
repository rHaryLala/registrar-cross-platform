"use client";

import { LogOut, WifiOff } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import { toast } from "sonner";
import { Seal } from "@/components/brand";
import { StudentNavInline, StudentTabBar } from "@/components/student-nav";
import { Button } from "@/components/ui/button";
import { useLogout, useMe } from "@/lib/auth";
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
  const isStudent = me.data?.role === "student";

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
        <div className="flex-1">{isStudent && <StudentNavInline />}</div>
        {!online && (
          <span role="status" className="inline-flex items-center gap-1.5 rounded-full border border-warning px-2.5 py-1 text-xs font-bold text-warning">
            <WifiOff className="size-3.5" aria-hidden /> Hors ligne
          </span>
        )}
        {me.data && (
          <Button
            variant="ghost"
            size="icon"
            aria-label={`Se déconnecter (${me.data.display_name})`}
            title="Se déconnecter"
            disabled={logout.isPending}
            onClick={() =>
              logout.mutate(undefined, {
                onSuccess: () => router.replace("/login/"),
                onError: () => toast.error("Déconnexion impossible", { description: "Vérifiez votre connexion, puis réessayez. Votre session reste ouverte." }),
              })
            }
          >
            <LogOut aria-hidden />
          </Button>
        )}
      </header>

      {/* pb-24 : la barre du bas ne doit jamais cacher la fin du contenu */}
      <main id="contenu" tabIndex={-1} className={cn("flex-1 outline-none", isStudent && "pb-24 md:pb-0")}>
        {children}
      </main>
      {isStudent && <StudentTabBar />}
    </div>
  );
}
