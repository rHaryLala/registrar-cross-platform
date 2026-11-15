import type { Metadata } from "next";
import { Suspense } from "react";
import { RequireStudent } from "@/components/require-student";
import { SiteNewsDetail } from "@/components/site-news-detail";

export const metadata: Metadata = { title: "Actualité de l’université" };

export default function SiteNewsDetailPage() {
  return (
    <RequireStudent>
      {/* useSearchParams exige une frontière Suspense dans un site exporté en statique. */}
      <Suspense fallback={null}>
        <SiteNewsDetail />
      </Suspense>
    </RequireStudent>
  );
}
