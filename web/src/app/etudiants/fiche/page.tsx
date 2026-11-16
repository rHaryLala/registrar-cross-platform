import type { Metadata } from "next";
import { Suspense } from "react";
import { DirectoryFiche } from "@/components/directory-fiche";
import { RequireStudentsDirectory } from "@/components/require-role";

export const metadata: Metadata = { title: "Fiche — Étudiants" };

export default function FichePage() {
  return (
    <RequireStudentsDirectory>
      <Suspense>
        <DirectoryFiche kind="students" />
      </Suspense>
    </RequireStudentsDirectory>
  );
}
