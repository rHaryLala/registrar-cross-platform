"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Check, Lightbulb, RotateCcw, Trophy, X } from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import { formatDuration, formatRank, plural } from "@/lib/format";
import { VERDICT_TEXT, type AnswerOutcome, type Question, type RoundResult, type StartedParty } from "@/lib/quiz";
import { cn } from "@/lib/utils";

type Phase = "intro" | "play" | "done" | "expired";
type Feedback = { chosen: string; outcome: AnswerOutcome };

function problem(e: unknown, fallback: string): string {
  return e instanceof ApiError && e.detail ? e.detail : fallback;
}

/**
 * Une partie de quiz. Le serveur est l'arbitre : il tire les questions, juge chaque réponse et calcule le score ;
 * cet écran ne fait qu'afficher ce qu'il renvoie. Rien n'est « enregistré » depuis le navigateur.
 */
export function QuizGame() {
  const qc = useQueryClient();
  const [phase, setPhase] = useState<Phase>("intro");
  const [party, setParty] = useState<{ id: number; total: number } | null>(null);
  const [question, setQuestion] = useState<Question | null>(null);
  const [score, setScore] = useState(0);
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [result, setResult] = useState<RoundResult | null>(null);
  const [busy, setBusy] = useState(false);
  const nextRef = useRef<HTMLButtonElement>(null);
  const titleRef = useRef<HTMLHeadingElement>(null);

  const start = useCallback(async () => {
    setBusy(true);
    try {
      const { data } = await api<{ data: StartedParty }>("/api/v1/me/quiz/parties", { method: "POST" });
      setParty({ id: data.party_id, total: data.total });
      setQuestion(data.question);
      setScore(0);
      setFeedback(null);
      setResult(null);
      setPhase("play");
    } catch (e) {
      toast.error("Impossible de lancer la partie", { description: problem(e, "Vérifiez votre connexion, puis réessayez.") });
    } finally {
      setBusy(false);
    }
  }, []);

  const choose = useCallback(
    async (option: string) => {
      if (busy || feedback || !party || !question) return;
      setBusy(true);
      try {
        const { data } = await api<{ data: AnswerOutcome }>(`/api/v1/me/quiz/parties/${party.id}/reponses`, {
          method: "POST",
          body: { position: question.position, answer: option },
        });
        setScore(data.score);
        setFeedback({ chosen: option, outcome: data });
        if (data.finished) {
          void qc.invalidateQueries({ queryKey: ["me", "quiz"] });
        }
      } catch (e) {
        const code = e instanceof ApiError ? e.code : "";
        if (code === "quiz.too_fast") {
          toast.info("Prenez le temps de lire la question", { description: "Vous pouvez répondre dans un instant." });
        } else if (["quiz.expired", "quiz.not_found", "quiz.finished"].includes(code)) {
          setPhase("expired");
        } else {
          toast.error("Réponse non enregistrée", { description: problem(e, "Vérifiez votre connexion, puis choisissez à nouveau.") });
        }
      } finally {
        setBusy(false);
      }
    },
    [busy, feedback, party, question, qc],
  );

  const next = useCallback(() => {
    if (!feedback) return;
    if (feedback.outcome.finished && feedback.outcome.result) {
      setResult(feedback.outcome.result);
      setPhase("done");
    } else if (feedback.outcome.next) {
      setQuestion(feedback.outcome.next);
      setFeedback(null);
    }
  }, [feedback]);

  // Raccourcis 1, 2, 3 pour répondre (en plus des boutons : jamais le seul moyen).
  useEffect(() => {
    if (phase !== "play" || !question || feedback) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      const i = ["1", "2", "3"].indexOf(e.key);
      if (i >= 0 && question.options[i]) void choose(question.options[i]);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [phase, question, feedback, choose]);

  // Le focus suit la partie : sur « Suivant » après une réponse, sur la nouvelle question ensuite.
  useEffect(() => {
    if (feedback) nextRef.current?.focus();
  }, [feedback]);
  useEffect(() => {
    if (phase === "play" && !feedback) titleRef.current?.focus({ preventScroll: true });
  }, [phase, question?.position, feedback]);

  return (
    <AppShell title="Quiz">
      <div className="mx-auto grid w-full max-w-2xl gap-5 p-4 md:p-6">
        {phase === "intro" && <Intro busy={busy} onStart={start} />}
        {phase === "play" && party && question && (
          <Play
            party={party}
            question={question}
            score={score}
            feedback={feedback}
            busy={busy}
            titleRef={titleRef}
            nextRef={nextRef}
            onChoose={choose}
            onNext={next}
          />
        )}
        {phase === "done" && result && <Done result={result} busy={busy} onAgain={start} />}
        {phase === "expired" && <Expired busy={busy} onAgain={start} />}
      </div>
    </AppShell>
  );
}

function Intro({ busy, onStart }: { busy: boolean; onStart: () => void }) {
  return (
    <Card className="gap-4 p-5">
      <h2 className="text-xl font-extrabold tracking-tight">Prêt à jouer ?</h2>
      <ul className="grid gap-2 text-sm text-muted-foreground [&>li]:ml-4 [&>li]:list-disc">
        <li>Dix questions tirées au hasard, trois réponses possibles, une seule est juste.</li>
        <li>Vous pouvez répondre avec les boutons ou avec les touches 1, 2 et 3.</li>
        <li>Votre score et votre temps sont calculés par le serveur et alimentent le classement.</li>
        <li>Quitter une partie en cours l’abandonne : elle ne compte pas.</li>
      </ul>
      <div className="flex flex-wrap gap-3">
        <Button size="lg" disabled={busy} onClick={onStart}>
          {busy ? "Préparation…" : "Commencer"}
        </Button>
        <Link href="/quiz/" className={buttonVariants({ variant: "outline", size: "lg" })}>
          Voir le classement
        </Link>
      </div>
    </Card>
  );
}

function Play({
  party,
  question,
  score,
  feedback,
  busy,
  titleRef,
  nextRef,
  onChoose,
  onNext,
}: {
  party: { id: number; total: number };
  question: Question;
  score: number;
  feedback: Feedback | null;
  busy: boolean;
  titleRef: React.RefObject<HTMLHeadingElement | null>;
  nextRef: React.RefObject<HTMLButtonElement | null>;
  onChoose: (option: string) => void;
  onNext: () => void;
}) {
  const answered = feedback ? question.position : question.position - 1;
  const last = question.position === party.total;

  return (
    <>
      <div className="flex items-center justify-between gap-3 text-sm">
        <span className="font-bold">
          Question {question.position} sur {party.total}
        </span>
        <span className="tabular-nums text-muted-foreground" aria-live="polite">
          {score} bonne{score > 1 ? "s" : ""} réponse{score > 1 ? "s" : ""}
        </span>
      </div>
      <div role="progressbar" aria-label="Progression de la partie" aria-valuemin={0} aria-valuemax={party.total} aria-valuenow={answered} className="flex gap-1">
        {Array.from({ length: party.total }, (_, i) => (
          <span key={i} aria-hidden className={cn("h-1.5 flex-1 rounded-full", i < answered ? "bg-brand" : i === answered ? "bg-foreground/40" : "bg-muted")} />
        ))}
      </div>

      <Card key={question.position} className="gap-5 p-5 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-200">
        <div>
          <p className="text-xs font-bold uppercase tracking-wide text-accent-text">{question.category}</p>
          <h2 ref={titleRef} tabIndex={-1} className="mt-1 text-xl leading-snug font-extrabold tracking-tight outline-none">
            {question.icon && (
              <span aria-hidden className="mr-2">
                {question.icon}
              </span>
            )}
            {question.text}
          </h2>
        </div>

        <ul className="grid gap-3">
          {question.options.map((option, i) => (
            <li key={option}>
              <OptionButton index={i} option={option} feedback={feedback} disabled={busy || feedback !== null} onChoose={onChoose} />
            </li>
          ))}
        </ul>

        {question.hint && !feedback && (
          <details className="group text-sm">
            <summary className="inline-flex min-h-11 cursor-pointer items-center gap-2 text-muted-foreground">
              <Lightbulb className="size-4" aria-hidden /> Un indice
            </summary>
            <p className="rounded-lg bg-muted p-3">{question.hint}</p>
          </details>
        )}

        <div aria-live="polite" className="grid gap-3">
          {feedback && (
            <>
              <p className={cn("font-bold", feedback.outcome.correct ? "text-success" : "text-destructive")}>
                {feedback.outcome.correct ? "Bonne réponse !" : `Raté. La bonne réponse était : ${feedback.outcome.correct_answer}`}
              </p>
              <div>
                <Button ref={nextRef} size="lg" onClick={onNext} className="w-full md:w-auto">
                  {last ? "Voir mon résultat" : "Question suivante"}
                </Button>
              </div>
            </>
          )}
        </div>
      </Card>
    </>
  );
}

function OptionButton({
  index,
  option,
  feedback,
  disabled,
  onChoose,
}: {
  index: number;
  option: string;
  feedback: Feedback | null;
  disabled: boolean;
  onChoose: (option: string) => void;
}) {
  const isRight = feedback?.outcome.correct_answer === option;
  const isChosen = feedback?.chosen === option;
  const wrongChoice = isChosen && !isRight;

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={() => onChoose(option)}
      className={cn(
        "flex min-h-14 w-full items-center gap-3 rounded-xl border bg-card px-4 py-3 text-left font-semibold transition-colors",
        !feedback && "hover:bg-accent focus-visible:bg-accent",
        feedback && !isRight && !wrongChoice && "opacity-60",
        isRight && "border-success bg-success/10",
        wrongChoice && "border-destructive bg-destructive/10",
      )}
    >
      <span aria-hidden className="grid size-8 shrink-0 place-items-center rounded-lg border text-sm font-bold text-muted-foreground tabular-nums">
        {isRight ? <Check className="size-4 text-success" /> : wrongChoice ? <X className="size-4 text-destructive" /> : index + 1}
      </span>
      <span className="flex-1">{option}</span>
      {isRight && <span className="text-xs font-bold text-success">Bonne réponse</span>}
      {wrongChoice && <span className="text-xs font-bold text-destructive">Votre choix</span>}
    </button>
  );
}

function Done({ result, busy, onAgain }: { result: RoundResult; busy: boolean; onAgain: () => void }) {
  const scope = useRef<HTMLDivElement>(null);
  useEntrance(scope, "[data-enter]", true);

  return (
    <div ref={scope} className="grid gap-5">
      <Card data-enter className="items-center gap-2 p-8 text-center">
        <Trophy className="size-8 text-accent-text" aria-hidden />
        <h2 className="text-sm font-bold text-muted-foreground">Partie terminée</h2>
        <p className="text-5xl font-extrabold tabular-nums" aria-label={`${result.score} bonnes réponses sur ${result.total}`}>
          {result.score}
          <span className="text-2xl text-muted-foreground">/{result.total}</span>
        </p>
        <p className="text-lg font-bold">{VERDICT_TEXT[result.verdict]}</p>
        <p className="text-sm text-muted-foreground">
          {result.percent} % de bonnes réponses en {formatDuration(result.duration_ms)}
        </p>
        {result.personal_best && <StatusChip tone="ok">Nouveau record personnel</StatusChip>}
      </Card>

      <Card data-enter className="gap-2 p-5 text-sm">
        <h3 className="font-extrabold">Votre place</h3>
        {result.rank_week === null && result.rank_all === null ? (
          <p className="text-muted-foreground">Vous n’apparaissez pas au classement, car vous l’avez désactivé. Vous pouvez le réactiver depuis la page du quiz.</p>
        ) : (
          <ul className="grid gap-1">
            {result.rank_week !== null && (
              <li>
                <strong className="tabular-nums">{formatRank(result.rank_week)}</strong> sur {plural(result.players_week, "joueur")} cette semaine
              </li>
            )}
            {result.rank_all !== null && (
              <li>
                <strong className="tabular-nums">{formatRank(result.rank_all)}</strong> sur {plural(result.players_all, "joueur")} depuis toujours
              </li>
            )}
          </ul>
        )}
        <p className="text-xs text-muted-foreground">Le classement garde la meilleure partie de chaque joueur.</p>
      </Card>

      <div data-enter className="flex flex-wrap gap-3">
        <Button size="lg" disabled={busy} onClick={onAgain}>
          <RotateCcw aria-hidden /> {busy ? "Préparation…" : "Rejouer"}
        </Button>
        <Link href="/quiz/" className={buttonVariants({ variant: "outline", size: "lg" })}>
          Voir le classement
        </Link>
      </div>
    </div>
  );
}

function Expired({ busy, onAgain }: { busy: boolean; onAgain: () => void }) {
  return (
    <Card role="alert" className="gap-3 p-5">
      <p className="font-bold">Cette partie n’est plus valide</p>
      <p className="text-sm text-muted-foreground">Elle a expiré (30 minutes maximum) ou une autre partie a été lancée depuis. Elle ne compte pas : lancez-en une nouvelle.</p>
      <div className="flex flex-wrap gap-3">
        <Button disabled={busy} onClick={onAgain}>
          Nouvelle partie
        </Button>
        <Link href="/quiz/" className={buttonVariants({ variant: "outline" })}>
          Retour au quiz
        </Link>
      </div>
    </Card>
  );
}
