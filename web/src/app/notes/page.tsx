import type { Metadata } from "next";
import { RequireStudent } from "@/components/require-student";
import { StudentGrades } from "@/components/student-grades";

export const metadata: Metadata = { title: "Mes notes" };

export default function NotesPage() {
  return (
    <RequireStudent>
      <StudentGrades />
    </RequireStudent>
  );
}
