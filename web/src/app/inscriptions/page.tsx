import type { Metadata } from "next";
import { RequireStudent } from "@/components/require-student";
import { StudentEnrollments } from "@/components/student-enrollments";

export const metadata: Metadata = { title: "Mes inscriptions" };

export default function EnrollmentsPage() {
  return (
    <RequireStudent>
      <StudentEnrollments />
    </RequireStudent>
  );
}
