"use client";

import { RequireRole } from "@/components/require-role";
import { StudentAttendance } from "@/components/student-attendance";
import { TeacherCourses } from "@/components/teacher-courses";
import { useMe } from "@/lib/auth";

/** /presence/ : l'assiduité de l'étudiant, ou les registres de présence de l'enseignant. */
export function PresenceGate() {
  return (
    <RequireRole allow={["student", "teacher", "chef_mention"]} teacherSpace reserved="aux étudiants et aux enseignants">
      <ByRole />
    </RequireRole>
  );
}

function ByRole() {
  const me = useMe();
  return me.data?.role === "student" ? <StudentAttendance /> : <TeacherCourses />;
}
