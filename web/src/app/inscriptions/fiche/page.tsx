import type { Metadata } from "next";
import { Suspense } from "react";
import { EnrollmentSheet } from "@/components/enrollment-sheet";
import { RequireStudent } from "@/components/require-student";

export const metadata: Metadata = { title: "Fiche d’inscription" };

export default function EnrollmentSheetPage() {
  return (
    <RequireStudent>
      {/* useSearchParams exige une frontière Suspense dans un site exporté en statique. */}
      <Suspense fallback={null}>
        <EnrollmentSheet />
      </Suspense>
    </RequireStudent>
  );
}
