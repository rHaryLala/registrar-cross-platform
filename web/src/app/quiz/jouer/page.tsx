import type { Metadata } from "next";
import { QuizGame } from "@/components/quiz-game";
import { RequireStudent } from "@/components/require-student";

export const metadata: Metadata = { title: "Jouer au quiz" };

export default function QuizPlayPage() {
  return (
    <RequireStudent>
      <QuizGame />
    </RequireStudent>
  );
}
