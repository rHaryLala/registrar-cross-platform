import type { Metadata } from "next";
import { RequireStudent } from "@/components/require-student";
import { StudentQuiz } from "@/components/student-quiz";

export const metadata: Metadata = { title: "Quiz" };

export default function QuizPage() {
  return (
    <RequireStudent>
      <StudentQuiz />
    </RequireStudent>
  );
}
