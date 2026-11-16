"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { RotateCcw, TriangleAlert, Undo2, X } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { api, ApiError } from "@/lib/api";
import {
  batchText, confirmationOk, previewUrl, reenrolmentKeys, resetScope, resetUrl, undoUrl,
  type Field, type LastBatch, type ResetPreview, type ResetResult, type UndoResult,
} from "@/lib/reenrolment";

function describe(err: unknown): string | undefined {
  return err instanceof ApiError ? (err.isOffline ? "Pas de réseau : rien n’a été modifié. Réessayez avec une connexion." : err.detail) : undefined;
}

/** Le bandeau « Annuler cette réinitialisation » : proposé au superadmin pendant sept jours, après un rechargement ou depuis un autre poste. */
export function UndoBanner({ batch }: { batch: LastBatch }) {
  const qc = useQueryClient();
  const undo = useMutation({
    mutationFn: async () => (await api<{ data: UndoResult }>(undoUrl(batch.lot), { method: "POST" })).data,
    onSuccess: async (r) => {
      toast.success("Réinitialisation annulée", { description: r.message });
      await qc.invalidateQueries({ queryKey: reenrolmentKeys.root });
    },
    onError: (e) => toast.error("Annulation impossible", { description: describe(e) }),
  });
  return (
    <Card role="status" className="flex-row flex-wrap items-center gap-3 border-warning p-4">
      <Undo2 className="size-5 shrink-0 text-warning" aria-hidden />
      <p className="min-w-0 flex-1 text-sm">{batchText(batch)}</p>
      <Button variant="outline" disabled={undo.isPending} onClick={() => undo.mutate()}>
        {undo.isPending ? "Annulation…" : "Annuler cette réinitialisation"}
      </Button>
    </Card>
  );
}

/**
 * « Tout réinitialiser » (superadmin) : choisir les colonnes, lire la portée EXACTE (aperçu serveur, rien n'est écrit), saisir RÉINITIALISER.
 * Le geste reste annulable pendant sept jours depuis le bandeau.
 */
export function ResetDialog({ available }: { available: boolean }) {
  const qc = useQueryClient();
  const dialog = useRef<HTMLDialogElement>(null);
  const [open, setOpen] = useState(false);
  const [fields, setFields] = useState<Field[]>(["authorization", "file"]);
  const [typed, setTyped] = useState("");

  useEffect(() => {
    const d = dialog.current;
    if (!d) return;
    if (open && !d.open) d.showModal();
    if (!open && d.open) d.close();
  }, [open]);

  const preview = useQuery({
    queryKey: reenrolmentKeys.preview(fields),
    queryFn: async () => (await api<{ data: ResetPreview }>(previewUrl(fields))).data,
    enabled: open,
    staleTime: 0,
    gcTime: 0,
  });

  const reset = useMutation({
    mutationFn: async () => (await api<{ data: ResetResult }>(resetUrl, { method: "POST", body: { columns: fields, confirmation: typed } })).data,
    onSuccess: async (r) => {
      toast.success("Réinitialisation faite", { description: r.message });
      setOpen(false);
      setTyped("");
      await qc.invalidateQueries({ queryKey: reenrolmentKeys.root });
    },
    onError: (e) => toast.error("Réinitialisation impossible", { description: describe(e) }),
  });

  const ready = available && fields.length > 0 && confirmationOk(typed) && preview.isSuccess && !reset.isPending;
  const close = () => {
    setOpen(false);
    setTyped("");
  };

  return (
    <>
      <Button variant="destructive" disabled={!available} onClick={() => setOpen(true)} title={available ? undefined : "Indisponible : la table d’annulation n’existe pas encore sur cette base"}>
        <RotateCcw aria-hidden /> Tout réinitialiser
      </Button>
      <dialog
        ref={dialog}
        aria-labelledby="reset-title"
        onClose={close}
        onClick={(e) => e.target === dialog.current && close()}
        className="m-auto max-h-[90dvh] w-[min(34rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-xl border bg-card p-0 text-card-foreground shadow-xl backdrop:bg-black/50 open:flex"
      >
        <div className="flex shrink-0 items-center gap-2 border-b p-4">
          <TriangleAlert className="size-5 text-destructive" aria-hidden />
          <h2 id="reset-title" className="flex-1 text-lg font-extrabold">
            Tout réinitialiser
          </h2>
          <Button variant="ghost" size="icon" aria-label="Fermer" onClick={close}>
            <X aria-hidden />
          </Button>
        </div>
        <div className="grid min-h-0 gap-4 overflow-auto p-4">
          <p className="text-sm text-muted-foreground">
            Les colonnes choisies sont remises à « non coché » pour <strong>tous</strong> les étudiants, par exemple au changement d’année. Chaque changement est inscrit dans l’historique de l’étudiant, et la réinitialisation reste annulable depuis le bandeau pendant sept jours.
          </p>
          <fieldset className="grid gap-1">
            <legend className="mb-1 font-extrabold">Colonnes à remettre à zéro</legend>
            {(["authorization", "file"] as const).map((f) => (
              <label key={f} className="flex min-h-11 items-center gap-3 text-sm">
                <input
                  type="checkbox"
                  checked={fields.includes(f)}
                  onChange={() => setFields((cur) => (cur.includes(f) ? cur.filter((x) => x !== f) : [...cur, f]))}
                  className="size-5 accent-[var(--primary)]"
                />
                <span className="flex-1">{f === "authorization" ? "Autorisation de la caisse" : "Dossier validé par le registraire"}</span>
                <span className="text-muted-foreground tabular-nums">{preview.data?.by_field[f]?.toLocaleString("fr-FR") ?? ""}</span>
              </label>
            ))}
          </fieldset>
          <p aria-live="polite" className="rounded-lg bg-accent/60 p-3 text-sm font-semibold">
            {preview.isPending && fields.length > 0 ? "Calcul de la portée…" : preview.isError ? "La portée n’a pas pu être calculée." : preview.data ? resetScope(preview.data) : "Choisissez au moins une colonne à réinitialiser."}
          </p>
          <label className="grid gap-1 text-sm font-semibold">
            Pour confirmer, saisissez <span className="font-mono">RÉINITIALISER</span>
            <input
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
              autoComplete="off"
              spellCheck={false}
              autoCapitalize="characters"
              className="h-12 rounded-lg border border-input bg-background px-3 font-mono text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </label>
        </div>
        <div className="flex shrink-0 justify-end gap-2 border-t p-4">
          <Button variant="outline" onClick={close}>
            Annuler
          </Button>
          <Button variant="destructive" disabled={!ready} onClick={() => reset.mutate()}>
            {reset.isPending ? "Réinitialisation…" : "Réinitialiser"}
          </Button>
        </div>
      </dialog>
    </>
  );
}
