import type { Metadata } from "next";
import { Suspense } from "react";
import { DirectoryFiche } from "@/components/directory-fiche";
import { RequireAttendanceReviewer } from "@/components/require-role";

export const metadata: Metadata = { title: "Fiche — Cours" };

export default function FichePage() {
  return (
    <RequireAttendanceReviewer>
      <Suspense>
        <DirectoryFiche kind="courses" />
      </Suspense>
    </RequireAttendanceReviewer>
  );
}
