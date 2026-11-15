/** Formes des réponses de l'API Quiz (/api/v1/me/quiz…). Le serveur est l'arbitre : le front n'invente jamais un score. */

export type Round = { score: number; total: number; percent: number; duration_ms: number; finished_at: string };

export type QuizProfile = {
  rounds_played: number;
  best: Round | null;
  average_percent: number | null;
  last_rounds: Round[];
  visible_in_ranking: boolean;
  available_questions: number;
};

export type LeaderEntry = {
  rank: number;
  name: string;
  score: number;
  total: number;
  percent: number;
  duration_ms: number;
  rounds_played: number;
  average_percent: number;
  is_me: boolean;
};

export type Leaderboard = {
  period: "all" | "week";
  players: number;
  visible_in_ranking: boolean;
  entries: LeaderEntry[];
  me: LeaderEntry | null;
};

export type Question = { position: number; text: string; icon: string | null; hint: string | null; category: string; options: string[] };

export type StartedParty = { party_id: number; total: number; expires_at: string; question: Question };

export type Verdict = "perfect" | "excellent" | "good" | "fair" | "review";

export type RoundResult = {
  score: number;
  total: number;
  percent: number;
  duration_ms: number;
  verdict: Verdict;
  personal_best: boolean;
  rank_all: number | null;
  rank_week: number | null;
  players_all: number;
  players_week: number;
};

export type AnswerOutcome = {
  correct: boolean;
  correct_answer: string;
  score: number;
  answered: number;
  total: number;
  finished: boolean;
  next: Question | null;
  result: RoundResult | null;
};

export const VERDICT_TEXT: Record<Verdict, string> = {
  perfect: "Sans faute !",
  excellent: "Excellent",
  good: "Bien joué",
  fair: "Pas mal, vous pouvez mieux",
  review: "À revoir : réessayez, vous allez progresser",
};
