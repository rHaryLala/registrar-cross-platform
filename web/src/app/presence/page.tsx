import type { Metadata } from "next";
import { RequireStudent } from "@/components/require-student";
import { StudentAttendance } from "@/components/student-attendance";

export const metadata: Metadata = { title: "Mon assiduité" };

export default function PresencePage() {
  return (
    <RequireStudent>
      <StudentAttendance />
    </RequireStudent>
  );
}
