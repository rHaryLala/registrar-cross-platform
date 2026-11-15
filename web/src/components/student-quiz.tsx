"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Eye, EyeOff, Play, Trophy } from "lucide-react";
import Link from "next/link";
import { useRef, useState } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import { formatDuration, plural } from "@/lib/format";
import type { LeaderEntry, Leaderboard, QuizProfile, Round } from "@/lib/quiz";
import { cn } from "@/lib/utils";

type Period = "week" | "all";

export function StudentQuiz() {
  const scope = useRef<HTMLDivElement>(null);
  const profile = useQuery({
    queryKey: ["me", "quiz"],
    queryFn: async () => (await api<{ data: QuizProfile }>("/api/v1/me/quiz")).data,
  });
  useEntrance(scope, "[data-enter]", profile.isSuccess);

  return (
    <AppShell title="Quiz">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        {profile.isPending && <QuizSkeleton />}
        {profile.isError && <QuizError error={profile.error} onRetry={() => profile.refetch()} />}
        {profile.isSuccess && <QuizContent profile={profile.data} />}
      </div>
    </AppShell>
  );
}

function QuizContent({ profile }: { profile: QuizProfile }) {
  return (
    <>
      <Card data-enter className="gap-4 p-5">
        <div className="flex items-start gap-3">
          <Trophy className="mt-1 size-6 shrink-0 text-accent-text" aria-hidden />
          <div>
            <h2 className="text-lg font-extrabold tracking-tight">Quiz UAZ</h2>
            <p className="text-sm text-muted-foreground">
              Dix questions tirées au hasard, trois réponses possibles, une seule est juste. Votre score et votre temps sont enregistrés et entrent au classement des étudiants.
            </p>
          </div>
        </div>
        {profile.available_questions === 0 ? (
          <p role="status" className="text-sm text-muted-foreground">
            Aucune question n’est disponible pour le moment.
          </p>
        ) : (
          <div>
            <Link href="/quiz/jouer/" className={cn(buttonVariants({ size: "lg" }), "w-full md:w-auto")}>
              <Play aria-hidden />
              {profile.rounds_played === 0 ? "Jouer ma première partie" : "Jouer une partie"}
            </Link>
          </div>
        )}
      </Card>

      <MyStats profile={profile} />
      <Ranking visibleInRanking={profile.visible_in_ranking} />
      <PrivacySwitch visible={profile.visible_in_ranking} />
    </>
  );
}

function MyStats({ profile }: { profile: QuizProfile }) {
  if (profile.rounds_played === 0) {
    return (
      <Card data-enter className="p-5 text-sm text-muted-foreground">
        Vous n’avez pas encore terminé de partie. Votre meilleur score, votre moyenne et vos dernières parties apparaîtront ici.
      </Card>
    );
  }
  return (
    <Card data-enter className="gap-0 p-0">
      <h2 className="border-b p-4 font-extrabold">Mes parties</h2>
      <dl className="grid grid-cols-3 gap-px bg-border">
        <Figure value={String(profile.rounds_played)} label={profile.rounds_played < 2 ? "partie terminée" : "parties terminées"} />
        <Figure
          value={profile.best ? `${profile.best.score}/${profile.best.total}` : "—"}
          label={profile.best ? `meilleur score · ${formatDuration(profile.best.duration_ms)}` : "meilleur score"}
        />
        <Figure value={profile.average_percent === null ? "—" : `${Math.round(profile.average_percent)} %`} label="moyenne" />
      </dl>
      <details className="group border-t">
        <summary className="flex min-h-12 cursor-pointer items-center px-4 text-sm text-muted-foreground">{profile.last_rounds.length === 1 ? "Ma dernière partie" : `Mes ${profile.last_rounds.length} dernières parties`}</summary>
        <ul className="divide-y border-t">
          {profile.last_rounds.map((r, i) => (
            <RoundRow key={`${r.finished_at}-${i}`} round={r} />
          ))}
        </ul>
      </details>
    </Card>
  );
}

function Figure({ value, label }: { value: string; label: string }) {
  return (
    <div className="bg-card px-4 py-3">
      <dd className="text-2xl font-extrabold tabular-nums">{value}</dd>
      <dt className="text-xs text-muted-foreground">{label}</dt>
    </div>
  );
}

const dayTime = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });

function RoundRow({ round }: { round: Round }) {
  return (
    <li className="flex items-baseline justify-between gap-3 px-4 py-3 text-sm">
      <span className="text-muted-foreground">{dayTime.format(new Date(round.finished_at))}</span>
      <span className="font-semibold tabular-nums">
        {round.score}/{round.total}
        <span className="font-normal text-muted-foreground"> · {formatDuration(round.duration_ms)}</span>
      </span>
    </li>
  );
}

function Ranking({ visibleInRanking }: { visibleInRanking: boolean }) {
  const [period, setPeriod] = useState<Period>("week");
  const board = useQuery({
    queryKey: ["me", "quiz", "board", period],
    queryFn: async () => (await api<{ data: Leaderboard }>(`/api/v1/me/quiz/classement?period=${period}`)).data,
  });

  return (
    <Card data-enter className="gap-0 p-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
        <h2 className="font-extrabold">Classement</h2>
        <div role="group" aria-label="Période du classement" className="inline-flex rounded-lg border p-0.5">
          {(
            [
              ["week", "Cette semaine"],
              ["all", "Depuis toujours"],
            ] as const
          ).map(([value, label]) => (
            <button
              key={value}
              type="button"
              aria-pressed={period === value}
              onClick={() => setPeriod(value)}
              className="min-h-10 rounded-md px-3 text-sm font-bold text-muted-foreground transition-colors aria-pressed:bg-accent aria-pressed:text-accent-text"
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {board.isPending && <Skeleton className="m-4 h-40 rounded-xl" aria-label="Chargement du classement" />}
      {board.isError && (
        <div role="alert" className="grid gap-3 p-4">
          <p className="text-sm text-muted-foreground">Le classement n’a pas pu se charger.</p>
          <div>
            <Button variant="outline" onClick={() => board.refetch()}>
              Réessayer
            </Button>
          </div>
        </div>
      )}
      {board.isSuccess && <BoardList board={board.data} period={period} visibleInRanking={visibleInRanking} />}
    </Card>
  );
}

function BoardList({ board, period, visibleInRanking }: { board: Leaderboard; period: Period; visibleInRanking: boolean }) {
  if (board.entries.length === 0) {
    return (
      <p className="p-5 text-sm text-muted-foreground">
        {period === "week" ? "Personne n’a encore terminé de partie cette semaine. Soyez le premier : le classement repart chaque lundi." : "Aucune partie terminée pour le moment."}
      </p>
    );
  }

  const meInList = board.entries.some((e) => e.is_me);
  return (
    <>
      <ol aria-label={period === "week" ? "Classement de la semaine" : "Classement général"} className="divide-y">
        {board.entries.map((e) => (
          <EntryRow key={`${e.rank}-${e.name}-${e.duration_ms}-${e.is_me}`} entry={e} />
        ))}
      </ol>
      {board.me && !meInList && (
        <div className="border-t-2">
          <p className="px-4 pt-3 text-xs text-muted-foreground">Votre place</p>
          <ol>
            <EntryRow entry={board.me} />
          </ol>
        </div>
      )}
      <p className="border-t px-4 py-3 text-xs text-muted-foreground">
        {plural(board.players, "joueur")} · meilleure partie de chacun : le pourcentage de bonnes réponses, puis le temps le plus court.
        {!visibleInRanking && " Vous n’apparaissez pas dans ce classement (réglage ci-dessous)."}
      </p>
    </>
  );
}

function EntryRow({ entry: e }: { entry: LeaderEntry }) {
  return (
    <li className={cn("flex min-h-14 items-center gap-3 px-4 py-2", e.is_me && "bg-accent")}>
      <span className="w-10 shrink-0 text-lg font-extrabold tabular-nums text-muted-foreground">
        <span className="sr-only">Rang </span>
        {e.rank === 1 ? "1er" : e.rank}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate font-bold">
          {e.name}
          {e.is_me && <StatusChip tone="info" className="ml-2 align-middle">Vous</StatusChip>}
        </span>
        <span className="block text-xs text-muted-foreground">
          {plural(e.rounds_played, "partie")} · moyenne {Math.round(e.average_percent)} %
        </span>
      </span>
      <span className="text-right">
        <span className="block font-extrabold tabular-nums">
          {e.score}/{e.total}
        </span>
        <span className="block text-xs tabular-nums text-muted-foreground">{formatDuration(e.duration_ms)}</span>
      </span>
    </li>
  );
}

function PrivacySwitch({ visible }: { visible: boolean }) {
  const qc = useQueryClient();
  const save = useMutation({
    mutationFn: (value: boolean) => api<{ data: { visible_in_ranking: boolean } }>("/api/v1/me/quiz/reglages", { method: "PUT", body: { visible_in_ranking: value } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["me", "quiz"] }),
    onError: (e) => toast.error("Réglage non enregistré", { description: e instanceof ApiError && e.detail ? e.detail : "Vérifiez votre connexion, puis réessayez." }),
  });
  const Icon = visible ? Eye : EyeOff;

  return (
    <Card data-enter className="p-0">
      <label className="flex min-h-14 cursor-pointer items-start gap-3 p-4">
        <input
          type="checkbox"
          role="switch"
          checked={visible}
          disabled={save.isPending}
          onChange={(e) => save.mutate(e.target.checked)}
          className="mt-0.5 size-5 shrink-0 cursor-pointer accent-[var(--accent-text)]"
        />
        <span className="text-sm">
          <span className="flex items-center gap-2 font-bold">
            <Icon className="size-4" aria-hidden /> Apparaître au classement
          </span>
          <span className="mt-1 block text-muted-foreground">
            {visible
              ? "Les autres étudiants voient votre prénom, l’initiale de votre nom, votre score et votre temps. Rien d’autre."
              : "Vous n’apparaissez plus aux yeux des autres. Vos parties et vos statistiques restent à vous."}
          </span>
        </span>
      </label>
    </Card>
  );
}

function QuizSkeleton() {
  return (
    <div className="grid gap-5" aria-busy="true" aria-label="Chargement du quiz">
      <Skeleton className="h-36 w-full rounded-xl" />
      <Skeleton className="h-56 w-full rounded-xl" />
    </div>
  );
}

function QuizError({ error, onRetry }: { error: Error; onRetry: () => void }) {
  const offline = error instanceof ApiError && error.isOffline;
  return (
    <Card role="alert" className="gap-3 p-4">
      <p className="font-bold">{offline ? "Vous êtes hors ligne" : "Le quiz n’a pas pu se charger"}</p>
      <p className="text-muted-foreground">{error instanceof ApiError && error.detail ? error.detail : "Réessayez dans un instant."}</p>
      <div>
        <Button onClick={onRetry}>Réessayer</Button>
      </div>
    </Card>
  );
}
