import type { Metadata } from "next";
import { Suspense } from "react";
import { RequireReviewer } from "@/components/require-role";
import { ReviewSheet } from "@/components/review-sheet";

export const metadata: Metadata = { title: "Contrôle d’une feuille" };

export default function ReviewSheetPage() {
  return (
    <RequireReviewer>
      {/* useSearchParams exige une frontière Suspense dans un site exporté en statique. */}
      <Suspense fallback={null}>
        <ReviewSheet />
      </Suspense>
    </RequireReviewer>
  );
}
