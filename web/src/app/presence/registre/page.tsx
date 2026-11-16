import type { Metadata } from "next";
import { Suspense } from "react";
import { RequireTeacher } from "@/components/require-role";
import { TeacherRegister } from "@/components/teacher-register";

export const metadata: Metadata = { title: "Registre de présence" };

export default function RegisterPage() {
  return (
    <RequireTeacher>
      {/* useSearchParams exige une frontière Suspense dans un site exporté en statique. */}
      <Suspense fallback={null}>
        <TeacherRegister />
      </Suspense>
    </RequireTeacher>
  );
}
