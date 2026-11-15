import type { Metadata } from "next";
import { Suspense } from "react";
import { NewsDetail } from "@/components/news-detail";
import { RequireStudent } from "@/components/require-student";

export const metadata: Metadata = { title: "Actualité" };

export default function NewsDetailPage() {
  return (
    <RequireStudent>
      {/* useSearchParams exige une frontière Suspense dans un site exporté en statique. */}
      <Suspense fallback={null}>
        <NewsDetail />
      </Suspense>
    </RequireStudent>
  );
}
