import type { Metadata } from "next";
import { RequireStudent } from "@/components/require-student";
import { StudentNews } from "@/components/student-news";

export const metadata: Metadata = { title: "Actualités" };

export default function NewsPage() {
  return (
    <RequireStudent>
      <StudentNews />
    </RequireStudent>
  );
}
