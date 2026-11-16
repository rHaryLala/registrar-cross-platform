import type { Metadata } from "next";
import { RequireReviewer } from "@/components/require-role";
import { ReviewQueue } from "@/components/review-queue";

export const metadata: Metadata = { title: "Validation des notes" };

export default function ReviewPage() {
  return (
    <RequireReviewer>
      <ReviewQueue />
    </RequireReviewer>
  );
}
