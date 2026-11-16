import type { Metadata } from "next";
import { Suspense } from "react";
import { GradingSheet } from "@/components/grading-sheet";
import { RequireTeacher } from "@/components/require-role";

export const metadata: Metadata = { title: "Feuille de notes" };

export default function GradingSheetPage() {
  return (
    <RequireTeacher>
      {/* useSearchParams exige une frontière Suspense dans un site exporté en statique. */}
      <Suspense fallback={null}>
        <GradingSheet />
      </Suspense>
    </RequireTeacher>
  );
}
