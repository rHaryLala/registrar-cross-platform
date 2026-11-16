"use client";

import { CloudUpload, RefreshCw, X } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { resolveConflict, sync, useQueue } from "@/lib/offline-sync";
import { plural } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { QueuedOp } from "@/lib/offline-queue";

/**
 * Les changements faits hors ligne qui n'ont pas encore été enregistrés par le serveur. Le serveur fait foi : quand une donnée a
 * changé entre-temps, rien n'est écrasé sans que la personne le décide (« Appliquer quand même » ou « Ignorer »).
 */
export function PendingChanges() {
  const q = useQueue();
  const [open, setOpen] = useState(false);
  const dialog = useRef<HTMLDialogElement>(null);
  const total = q.ops.length;
  const needsYou = q.conflict + q.rejected;
  const label = needsYou > 0 ? plural(needsYou, "changement à vérifier", "changements à vérifier") : q.syncing ? "Envoi en cours…" : plural(q.pending, "changement en attente", "changements en attente");

  // Plus rien à montrer : le panneau se ferme de lui-même (il disparaît avec le bouton).
  const visible = open && total > 0;
  useEffect(() => {
    const d = dialog.current;
    if (!d) return;
    if (visible && !d.open) d.showModal();
    if (!visible && d.open) d.close();
  }, [visible]);

  if (total === 0) return null;

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label={label}
        title={label}
        className={cn(
          "inline-flex min-h-10 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3 text-xs font-bold",
          needsYou > 0 ? "border-destructive text-destructive" : "border-warning text-warning",
        )}
      >
        <CloudUpload className="size-3.5" aria-hidden />
        {/* Téléphone : juste le nombre ; le texte complet est dans le nom accessible et sur écran plus large. */}
        <span aria-hidden className="sm:hidden">
          {needsYou > 0 ? needsYou : q.syncing ? "…" : q.pending}
        </span>
        <span aria-hidden className="hidden sm:inline">
          {label}
        </span>
      </button>

      <dialog
        ref={dialog}
        aria-labelledby="pending-title"
        onClose={() => setOpen(false)}
        onClick={(e) => e.target === dialog.current && setOpen(false)}
        className="m-auto max-h-[85dvh] w-[min(36rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-xl border bg-card p-0 text-card-foreground shadow-xl backdrop:bg-black/50 open:flex"
      >
        <div className="flex shrink-0 items-center gap-2 border-b p-4">
          <h2 id="pending-title" className="flex-1 text-lg font-extrabold">
            Changements en attente
          </h2>
          <Button variant="ghost" size="icon" aria-label="Fermer" onClick={() => setOpen(false)}>
            <X aria-hidden />
          </Button>
        </div>
        <p className="shrink-0 px-4 pt-3 text-sm text-muted-foreground">
          Ces changements ont été faits sur cet appareil sans réseau. Ils partent dans l’ordre dès que la connexion revient ; le serveur fait foi si une donnée a changé entre-temps.
        </p>
        <ul className="grid min-h-0 flex-1 content-start gap-2 overflow-auto p-4">
          {q.ops.map((op) => (
            <Row key={op.id} op={op} />
          ))}
        </ul>
        <div className="flex shrink-0 justify-end gap-2 border-t p-4">
          <Button variant="outline" disabled={q.syncing || q.pending === 0} onClick={() => void sync()}>
            <RefreshCw className={cn(q.syncing && "animate-spin")} aria-hidden />
            Envoyer maintenant
          </Button>
          <Button onClick={() => setOpen(false)}>Fermer</Button>
        </div>
      </dialog>
    </>
  );
}

function Row({ op }: { op: QueuedOp }) {
  const [busy, setBusy] = useState(false);
  const act = async (choice: "apply" | "ignore") => {
    setBusy(true);
    try {
      await resolveConflict(op, choice);
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className={cn("grid gap-2 rounded-lg border p-3 text-sm", op.state === "conflict" && "border-warning", op.state === "rejected" && "border-destructive")}>
      <p className="font-semibold">{op.label}</p>
      <p className="text-xs text-muted-foreground">Fait le {new Date(op.clientAt).toLocaleString("fr-FR", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" })}</p>
      {op.state === "pending" && <p className="text-xs text-muted-foreground">En attente d’envoi.</p>}
      {op.state !== "pending" && op.problem?.detail && <p className={op.state === "conflict" ? "text-warning" : "text-destructive"}>{op.problem.detail}</p>}
      {op.state === "conflict" && (
        <div className="flex flex-wrap gap-2">
          <Button size="sm" disabled={busy} onClick={() => void act("apply")}>
            Appliquer quand même
          </Button>
          <Button size="sm" variant="outline" disabled={busy} onClick={() => void act("ignore")}>
            Ignorer ce changement
          </Button>
        </div>
      )}
      {op.state === "rejected" && (
        <div>
          <Button size="sm" variant="outline" disabled={busy} onClick={() => void act("ignore")}>
            Compris, retirer
          </Button>
        </div>
      )}
    </li>
  );
}
