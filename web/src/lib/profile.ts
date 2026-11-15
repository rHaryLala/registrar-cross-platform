/** Forme de la réponse « Mes informations » (/api/v1/me/profile). Toute valeur absente est `null` : c'est l'écran qui écrit « — ». */

export type Profile = {
  student_id: string;
  first_name: string | null;
  last_name: string | null;
  has_photo: boolean;
  level_label: string;
  student_type: "Nouveau" | "Spécial" | "Ancien";
  status: { suspended: boolean; suspension_reason: string | null; withdrawn: boolean };
  identity: { birth_date: string | null; birth_place: string | null; sex: string | null; nationality: string | null; origin_country: string | null };
  id_document: { cin_number: string | null; cin_date: string | null; cin_region: string | null };
  contact: { phone: string | null; email: string | null; address: string | null; region: string | null };
  academic: {
    mention: string | null;
    track: string | null;
    level_label: string;
    school_year: string | null;
    residence: string | null;
    religion: string | null;
    cafeteria: boolean;
  };
  family: {
    father_name: string | null;
    father_job: string | null;
    mother_name: string | null;
    mother_job: string | null;
    parents_phone: string | null;
    parents_address: string | null;
  };
  civil: { situation: string | null; spouse: string | null; children: string | null };
  sponsor: { last_name: string | null; first_name: string | null; phone: string | null; address: string | null };
};
