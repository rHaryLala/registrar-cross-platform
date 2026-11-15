import type { Metadata } from "next";
import { RequireStudent } from "@/components/require-student";
import { StudentProfile } from "@/components/student-profile";

export const metadata: Metadata = { title: "Mes informations" };

export default function InformationsPage() {
  return (
    <RequireStudent>
      <StudentProfile />
    </RequireStudent>
  );
}
