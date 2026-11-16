"use client";

import { WifiOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ApiError } from "@/lib/api";
import type { State } from "@/lib/teacher-presence";
import { cn } from "@/lib/utils";

export const STATE_LABEL: Record<State, { label: string; tone: "ok" | "warn" | "bad" | "info"; bar: string }> = {
  ok: { label: "Dans la limite", tone: "ok", bar: "bg-success" },
  limit: { label: "À la limite des 15 %", tone: "warn", bar: "bg-warning" },
  exceeded: { label: "Au-delà de 15 %", tone: "bad", bar: "bg-destructive" },
  unknown: { label: "Base non indiquée", tone: "info", bar: "bg-muted-foreground" },
};

/** Données rouvertes sans réseau : on le dit, avec la date de la copie. */
export function CopyBanner({ copyAt }: { copyAt: number | null }) {
  if (copyAt === null) return null;
  const when = new Date(copyAt).toLocaleString("fr-FR", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });
  return (
    <Card role="status" className="flex-row items-start gap-3 border-warning p-3">
      <WifiOff className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
      <p className="text-sm">
        <strong>Hors ligne.</strong> Vous voyez la copie gardée sur cet appareil le {when}. Vos changements partiront dès le retour du réseau.
      </p>
    </Card>
  );
}

export function LoadError({ error, onRetry, what }: { error: Error; onRetry: () => void; what: string }) {
  const offline = error instanceof ApiError && error.isOffline;
  const missing = error instanceof ApiError && error.status === 404;
  return (
    <Card role="alert" className="gap-3 p-4">
      <p className="font-bold">{offline ? "Vous êtes hors ligne" : missing ? "Introuvable" : `${what} n’a pas pu se charger`}</p>
      <p className="text-muted-foreground">
        {offline
          ? "Cette page n’a pas encore été ouverte sur cet appareil avec du réseau : il n’y en a pas de copie. Réessayez avec une connexion."
          : error instanceof ApiError && error.detail
            ? error.detail
            : "Réessayez dans un instant ; si cela continue, contactez le registrariat."}
      </p>
      {!missing && (
        <div>
          <Button onClick={onRetry}>Réessayer</Button>
        </div>
      )}
    </Card>
  );
}

/** « Nom Prénom », jamais vide : le matricule en dernier recours. */
export function fullName(p: { last_name: string | null; first_name: string | null; id?: string; student_id?: string }): string {
  return [p.last_name, p.first_name].filter(Boolean).join(" ") || (p.id ?? p.student_id ?? "");
}

export function Gauge({ value, bar, label }: { value: number; bar: string; label: string }) {
  return (
    <div role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={value} className="h-1.5 overflow-hidden rounded-full bg-muted">
      <div className={cn("h-full rounded-full", bar)} style={{ width: `${value}%` }} />
    </div>
  );
}
