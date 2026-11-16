import type { Metadata } from "next";
import { ApplicationsList } from "@/components/applications-list";
import { RequireAttendanceReviewer } from "@/components/require-role";

export const metadata: Metadata = { title: "Candidatures" };

export default function ApplicationsPage() {
  return (
    <RequireAttendanceReviewer>
      <ApplicationsList />
    </RequireAttendanceReviewer>
  );
}
