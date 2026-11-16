import type { Metadata } from "next";
import { DirectoryList } from "@/components/directory-list";
import { RequireAttendanceReviewer } from "@/components/require-role";

export const metadata: Metadata = { title: "Enseignants" };

export default function DirectoryPage() {
  return (
    <RequireAttendanceReviewer>
      <DirectoryList kind="teachers" />
    </RequireAttendanceReviewer>
  );
}
