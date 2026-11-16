import type { Metadata } from "next";
import { Suspense } from "react";
import { ApplicationSheet } from "@/components/application-sheet";
import { RequireAttendanceReviewer } from "@/components/require-role";

export const metadata: Metadata = { title: "Candidature" };

export default function ApplicationPage() {
  return (
    <RequireAttendanceReviewer>
      <Suspense fallback={null}>
        <ApplicationSheet />
      </Suspense>
    </RequireAttendanceReviewer>
  );
}
