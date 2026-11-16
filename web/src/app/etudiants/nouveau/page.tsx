import type { Metadata } from "next";
import { Suspense } from "react";
import { RequireStudentCreation } from "@/components/require-role";
import { StudentCreationForm } from "@/components/student-creation-form";

export const metadata: Metadata = { title: "Nouvel étudiant" };

export default function NewStudentPage() {
  return (
    <RequireStudentCreation>
      {/* useSearchParams exige une frontière Suspense dans un site exporté en statique. */}
      <Suspense fallback={null}>
        <StudentCreationForm />
      </Suspense>
    </RequireStudentCreation>
  );
}
