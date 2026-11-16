import type { Metadata } from "next";
import { AttendanceReview } from "@/components/attendance-review";
import { RequireAttendanceReviewer } from "@/components/require-role";

export const metadata: Metadata = { title: "Assiduité" };

export default function AttendancePage() {
  return (
    <RequireAttendanceReviewer>
      <AttendanceReview />
    </RequireAttendanceReviewer>
  );
}
