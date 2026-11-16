import type { Metadata } from "next";
import { DirectoryList } from "@/components/directory-list";
import { RequireAttendanceReviewer } from "@/components/require-role";

export const metadata: Metadata = { title: "Cours" };

export default function DirectoryPage() {
  return (
    <RequireAttendanceReviewer>
      <DirectoryList kind="courses" />
    </RequireAttendanceReviewer>
  );
}
