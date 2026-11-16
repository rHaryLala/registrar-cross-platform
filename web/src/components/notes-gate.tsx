"use client";

import { GradingCourses } from "@/components/grading-courses";
import { RequireRole } from "@/components/require-role";
import { StudentGrades } from "@/components/student-grades";
import { useMe } from "@/lib/auth";

/** /notes/ : les notes de l'étudiant, ou la remise des notes de l'enseignant. */
export function NotesGate() {
  return (
    <RequireRole allow={["student", "teacher", "chef_mention"]} teacherSpace reserved="aux étudiants et aux enseignants">
      <ByRole />
    </RequireRole>
  );
}

function ByRole() {
  const me = useMe();
  return me.data?.role === "student" ? <StudentGrades /> : <GradingCourses />;
}
