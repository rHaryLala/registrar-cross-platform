import type { Metadata } from "next";
import { DirectoryList } from "@/components/directory-list";
import { RequireStudentsDirectory } from "@/components/require-role";

export const metadata: Metadata = { title: "Étudiants" };

export default function DirectoryPage() {
  return (
    <RequireStudentsDirectory>
      <DirectoryList kind="students" />
    </RequireStudentsDirectory>
  );
}
