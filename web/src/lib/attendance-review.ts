/**
 * Assiduité vue du registrariat : formes des réponses de l'API (/api/v1/review/attendance) et petites fonctions pures.
 *
 * Trois choses à surveiller (Bulletin académique) : les étudiants au-delà de 15 % d'absences (§476), les enseignants arrivés plus de
 * 10 minutes en retard (§474), et les registres dont la base des 15 % manque.
 */
import type { Summary } from "@/lib/teacher-presence";

export type Flag = {
  id: number;
  student_id: string;
  last_name: string | null;
  first_name: string | null;
  course_id: number;
  code: string | null;
  title: string | null;
  semester: number | null;
  semester_label: string;
  teacher: string | null;
  flagged_at: string;
  processed_at: string | null;
  /** absences comptées au moment du signalement */
  counted_at_flag: number;
  planned_at_flag: number;
  /** le bilan d'aujourd'hui : une absence corrigée depuis a pu ramener l'étudiant sous le seuil */
  today: Summary;
};

export type Delay = {
  id: number;
  date: string;
  planned_time: string;
  arrival: string;
  late_minutes: number | null;
  warned: boolean;
  course_id: number;
  code: string | null;
  title: string | null;
  teacher: string | null;
};

export type WithoutBase = {
  sheet_id: number;
  course_id: number;
  code: string | null;
  title: string | null;
  semester: number | null;
  semester_label: string;
  teacher: string | null;
  held_sessions: number;
};

export type AttendanceOverview = {
  year: string;
  years: string[];
  /** signalements à traiter, toutes années confondues */
  pending_total: number;
  flags: Flag[];
  teacher_delays: Delay[];
  delays_by_teacher: { teacher: string; count: number }[];
  without_base: WithoutBase[];
  wait_minutes: number;
};

export const attendanceKeys = {
  overview: (year: string | null, processed: boolean) => ["review", "attendance", year ?? "current", processed] as const,
  all: ["review", "attendance"] as const,
};

export function overviewUrl(year: string | null, processed: boolean): string {
  const params = new URLSearchParams();
  if (year) params.set("year", year);
  if (processed) params.set("processed", "1");
  const qs = params.toString();
  return `/api/v1/review/attendance${qs ? `?${qs}` : ""}`;
}

/** Où en est l'étudiant AUJOURD'HUI par rapport à ce qui l'a fait signaler : c'est ce que le registraire veut savoir avant d'agir. */
export function flagStanding(flag: Pick<Flag, "today">): { label: string; tone: "bad" | "ok" | "warn" } {
  switch (flag.today.state) {
    case "exceeded":
      return { label: "Toujours au-delà", tone: "bad" };
    case "unknown":
      return { label: "Base non indiquée", tone: "warn" };
    default:
      return { label: "Revenu sous le seuil", tone: "ok" };
  }
}

/** « 3 sur 2 permises » / « 3 (base non indiquée) » : le compte, et ce qui était permis. */
export function countedText(s: Pick<Summary, "counted" | "allowed">): string {
  return s.allowed === null ? `${s.counted}` : `${s.counted} sur ${s.allowed} permises`;
}

/** « 14:00 » → « 14 h 00 » ; autre chose que HH:MM reste tel quel. */
export function clockText(time: string): string {
  const m = /^(\d{1,2}):(\d{2})/.exec(time);
  return m ? `${Number(m[1])} h ${m[2]}` : time;
}

/** 90 → « 1 h 30 » ; 45 → « 45 min ». */
export function delayText(minutes: number | null): string {
  if (minutes === null) return "—";
  if (minutes < 60) return `${minutes} min`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m === 0 ? `${h} h` : `${h} h ${String(m).padStart(2, "0")}`;
}

/** « 2026-10-05 14:30:00 » (heure du serveur, sans fuseau) → date lisible. */
export function stampDate(stamp: string | null): string {
  if (!stamp) return "";
  const d = new Date(stamp.replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? stamp : d.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" });
}

/** Marque un signalement comme traité dans la copie en cache, pour que l'écran réagisse sans attendre la réponse. */
export function markProcessed(data: AttendanceOverview, id: number, at: string, keepProcessed: boolean): AttendanceOverview {
  const target = data.flags.find((f) => f.id === id);
  if (!target || target.processed_at) return data;
  const flags = keepProcessed ? data.flags.map((f) => (f.id === id ? { ...f, processed_at: at } : f)) : data.flags.filter((f) => f.id !== id);
  return { ...data, flags, pending_total: Math.max(0, data.pending_total - 1) };
}
