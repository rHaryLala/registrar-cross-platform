"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiError } from "@/lib/api";
import { readLocal, teacherGet, type Fetched } from "@/lib/offline-sync";
import { localSessionPath } from "@/lib/offline-sync";
import { gradingKeys, sheetPath, type GradingCourses, type Sheet } from "@/lib/grading";
import { keys, registerPath, type CoursesData, type RegisterData, type SessionData } from "@/lib/teacher-presence";

/** Un refus du serveur ne se corrige pas en réessayant ; une coupure de réseau, si (mais on rouvre alors la copie, sans attendre). */
const retry = (count: number, error: Error) => !(error instanceof ApiError && (error.status === 0 || (error.status >= 400 && error.status < 500))) && count < 2;

export function useCourses(year: string | null) {
  return useQuery({
    queryKey: keys.courses(year),
    queryFn: (): Promise<Fetched<CoursesData>> => teacherGet<CoursesData>(`/api/v1/teacher/courses${year ? `?year=${encodeURIComponent(year)}` : ""}`),
    retry,
  });
}

export function useRegister(course: number, year: string, semester: number | null) {
  return useQuery({
    queryKey: keys.register(course, year, semester),
    queryFn: (): Promise<Fetched<RegisterData>> => teacherGet<RegisterData>(registerPath(course, year, semester)),
    enabled: Number.isInteger(course) && course > 0 && year !== "",
    retry,
  });
}

/** L'appel d'une séance. Une séance créée hors ligne (identifiant négatif) n'existe que sur l'appareil : on la relit là. */
export function useSessionData(id: number) {
  return useQuery({
    queryKey: keys.session(id),
    queryFn: async (): Promise<Fetched<SessionData>> => {
      if (id < 0) {
        const local = await readLocal<SessionData>(localSessionPath(id));
        if (local) return local;
        throw new ApiError(404, "presence.not_found", "Séance introuvable", "Cette séance n’existe plus sur cet appareil.");
      }
      return teacherGet<SessionData>(`/api/v1/teacher/sessions/${id}`);
    },
    enabled: Number.isInteger(id) && id !== 0,
    retry,
  });
}

export function useGradingCourses(year: string | null) {
  return useQuery({
    queryKey: gradingKeys.courses(year),
    queryFn: (): Promise<Fetched<GradingCourses>> => teacherGet<GradingCourses>(`/api/v1/teacher/grading/courses${year ? `?year=${encodeURIComponent(year)}` : ""}`),
    retry,
  });
}

/** La feuille de notes d'un cours (copie gardée pour la rouvrir sans réseau). */
export function useSheet(course: number, year: string, semester: number | null) {
  return useQuery({
    queryKey: gradingKeys.sheet(course, year, semester),
    queryFn: (): Promise<Fetched<Sheet>> => teacherGet<Sheet>(sheetPath(course, year, semester)),
    enabled: Number.isInteger(course) && course > 0 && year !== "",
    retry,
  });
}
