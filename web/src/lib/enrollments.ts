/** Formes des réponses de l'API « Mes inscriptions » (/api/v1/me/enrollments…). */

export type EnrollmentSession = {
  session_id: number;
  session_name: string;
  level_label: string | null;
  mention: string | null;
  enrolled_on: string | null;
  courses: number;
  has_fees: boolean;
};

export type EnrollmentList = {
  visible: boolean;
  sheets_from: { L: number; M: number };
  years: { year: string; sessions: EnrollmentSession[] }[];
};

export type AmountRow = { label: string; amount: number };

export type SheetCourse = {
  code: string;
  title: string;
  credits: number;
  category: string;
  cost: number;
  lab: { cost: number; counted: boolean } | null;
};

export type Installment = { percent: number; amount: number; due_date: string | null; due_label: string | null };

export type SheetFees = {
  general_rows: AmountRow[];
  tuition: { rows: AmountRow[]; subtotal: number } | null;
  payment: { mode: string | null; mode_label: string | null; installments: Installment[] } | null;
  grand_total: number;
  grand_total_includes_tuition: boolean;
};

export type EnrollmentSheetData = {
  session: { id: number; name: string; year: string; semester: number };
  filename: string;
  student: {
    student_id: string;
    name: string;
    mention: string | null;
    track: string | null;
    level_label: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    residence: string | null;
    cafeteria: boolean | null;
    sponsor: string | null;
    has_photo: boolean;
  };
  is_master: boolean;
  courses: SheetCourse[];
  course_totals: { count: number; credits: number; cost: number; lab: number };
  fees: SheetFees | null;
  work_education: string | null;
  issued_on: string;
};
