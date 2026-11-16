"use client";

import { useQueryClient } from "@tanstack/react-query";
import { CheckCheck, Hourglass, Undo2 } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { api, ApiError } from "@/lib/api";
import { bulkOutcome, secondsLeft, type BulkApprove, type BulkFinalize, type BulkSkipped, type BulkUndo } from "@/lib/review";

const BASE = "/api/v1/review/remises/bulk";

type Waiting = { ids: number[]; deadline: number };

/**
 * Validation groupée avec le même filet que la feuille seule : « Valider » n'écrit rien, il ouvre un délai d'annulation de 10 secondes pour CHAQUE feuille ;
 * l'écran attend la fin du délai puis demande la finalisation (le serveur la ferait aussi tout seul si l'onglet se fermait).
 */
export function useBulkValidation(onSettled?: () => void) {
  const qc = useQueryClient();
  const [waiting, setWaiting] = useState<Waiting | null>(null);
  const [now, setNow] = useState(() => Date.now());
  const [busy, setBusy] = useState(false);
  const finalizing = useRef(false);
  const validated = useRef(0);
  const skipped = useRef<BulkSkipped[]>([]);

  const refresh = useCallback(async () => {
    await qc.invalidateQueries({ queryKey: ["review"] });
    onSettled?.();
  }, [qc, onSettled]);

  useEffect(() => {
    if (waiting === null) return;
    const t = window.setInterval(() => setNow(Date.now()), 250);
    return () => window.clearInterval(t);
  }, [waiting]);

  const remaining = waiting === null ? null : waiting.deadline - now;
  const due = remaining !== null && remaining <= 0;

  useEffect(() => {
    if (waiting === null || !due || finalizing.current) return;
    finalizing.current = true;
    void (async () => {
      try {
        const res = await api<{ data: BulkFinalize }>(`${BASE}/finalize`, { method: "POST", body: { ids: waiting.ids } });
        validated.current += res.data.validated.length;
        const notDue = res.data.skipped.filter((s) => s.code === "review.not_due");
        skipped.current.push(...res.data.skipped.filter((s) => s.code !== "review.not_due"));
        if (notDue.length > 0) {
          // l'horloge du serveur fait foi : on attend encore ce qu'il dit
          setNow(Date.now());
          setWaiting({ ids: notDue.map((s) => s.id), deadline: Date.now() + Math.max(...notDue.map((s) => s.remaining_ms ?? 1000)) });
          return;
        }
        const o = bulkOutcome(validated.current, skipped.current);
        (o.tone === "success" ? toast.success : o.tone === "warning" ? toast.warning : toast.error)(o.title, { description: o.description });
        setWaiting(null);
        await refresh();
      } catch (e) {
        setWaiting(null);
        toast.error("Validation interrompue", {
          description: e instanceof ApiError && !e.isOffline ? e.detail : "Connexion perdue : le serveur terminera lui-même les validations demandées dans la minute qui suit.",
        });
        await refresh();
      } finally {
        finalizing.current = false;
      }
    })();
  }, [waiting, due, refresh]);

  /** Demande la validation des feuilles choisies : rien n'est écrit, un délai d'annulation s'ouvre. */
  const start = useCallback(
    async (ids: number[]) => {
      setBusy(true);
      validated.current = 0;
      skipped.current = [];
      try {
        const res = await api<{ data: BulkApprove }>(`${BASE}/approve`, { method: "POST", body: { ids } });
        skipped.current = res.data.skipped;
        if (res.data.approved.length === 0) {
          const o = bulkOutcome(0, res.data.skipped);
          toast.error(o.title, { description: o.description });
          await refresh();
          return;
        }
        setNow(Date.now());
        setWaiting({ ids: res.data.approved.map((a) => a.id), deadline: Date.now() + Math.max(...res.data.approved.map((a) => a.remaining_ms)) });
        await refresh();
      } catch (e) {
        toast.error("Validation refusée", { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." });
      } finally {
        setBusy(false);
      }
    },
    [refresh],
  );

  /** Annule les demandes encore dans leur délai : aucune note n'a été écrite. */
  const cancel = useCallback(async () => {
    if (waiting === null) return;
    setBusy(true);
    try {
      const res = await api<{ data: BulkUndo }>(`${BASE}/undo`, { method: "POST", body: { ids: waiting.ids } });
      const late = res.data.skipped.filter((s) => s.code === "review.too_late");
      if (late.length > 0) {
        toast.warning(`${late.length} feuille${late.length < 2 ? "" : "s"} trop tard pour annuler`, { description: "Le délai était passé : ces validations aboutissent." });
        setWaiting({ ids: late.map((s) => s.id), deadline: Date.now() });
      } else {
        setWaiting(null);
      }
      if (res.data.undone.length > 0) toast("Validation annulée", { description: `${res.data.undone.length} feuille${res.data.undone.length < 2 ? "" : "s"} : aucune note n’a été écrite.` });
      await refresh();
    } catch (e) {
      toast.error("Annulation impossible", { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." });
    } finally {
      setBusy(false);
    }
  }, [waiting, refresh]);

  return { waiting, secondsLeft: remaining === null ? null : secondsLeft(remaining), busy, start, cancel };
}

/** Barre collée en bas de la file : combien de feuilles sont choisies et le bouton qui les valide, ou le compte à rebours avec « Annuler ». */
export function BulkBar({ count, selectable, bulk, onAll, onNone, onValidate }: { count: number; selectable: number; bulk: ReturnType<typeof useBulkValidation>; onAll: () => void; onNone: () => void; onValidate: () => void }) {
  if (bulk.waiting !== null) {
    const n = bulk.waiting.ids.length;
    return (
      <div role="status" aria-live="polite" className="sticky bottom-20 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-warning/50 bg-card p-3 shadow-lg md:bottom-4">
        <p className="flex items-center gap-2 text-sm font-bold">
          <Hourglass className="size-4 text-warning" aria-hidden />
          Validation de {n} feuille{n < 2 ? "" : "s"} dans <span className="tabular-nums">{bulk.secondsLeft} s</span>
        </p>
        <Button variant="outline" onClick={() => void bulk.cancel()} disabled={bulk.busy}>
          <Undo2 aria-hidden /> Annuler la validation
        </Button>
      </div>
    );
  }
  if (selectable === 0) return null;
  return (
    <div className="sticky bottom-20 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3 shadow-lg md:bottom-4">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <strong className="tabular-nums">{count}</strong> sélectionnée{count < 2 ? "" : "s"}
        <span className="text-muted-foreground">sur {selectable} prête{selectable < 2 ? "" : "s"}</span>
        {count < selectable && (
          <button type="button" onClick={onAll} className="min-h-11 px-2 font-bold text-accent-text hover:underline">
            Tout sélectionner
          </button>
        )}
        {count > 0 && (
          <button type="button" onClick={onNone} className="min-h-11 px-2 font-bold text-muted-foreground hover:underline">
            Vider
          </button>
        )}
      </div>
      <Button onClick={onValidate} disabled={count === 0 || bulk.busy}>
        <CheckCheck aria-hidden /> Valider la sélection{count > 0 ? ` (${count})` : ""}
      </Button>
    </div>
  );
}
