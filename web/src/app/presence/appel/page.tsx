import type { Metadata } from "next";
import { Suspense } from "react";
import { RequireTeacher } from "@/components/require-role";
import { TeacherCall } from "@/components/teacher-call";

export const metadata: Metadata = { title: "Appel" };

export default function CallPage() {
  return (
    <RequireTeacher>
      <Suspense fallback={null}>
        <TeacherCall />
      </Suspense>
    </RequireTeacher>
  );
}
