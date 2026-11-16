/**
 * Menu du personnel : les pages que CE compte peut ouvrir (jamais de lien mort), dans un ordre fixe.
 *
 * Sur téléphone la barre du bas a 5 places au plus : si tout ne tient pas, les quatre premières pages y figurent et le reste passe par
 * « Plus » (page /plus/). Sur grand écran l'en-tête a la place de tout montrer.
 */
import type { User } from "@/lib/auth";

export type NavIcon = "home" | "presence" | "notes" | "review" | "attendance" | "students" | "courses" | "teachers" | "applications" | "reenrolment" | "balances" | "deadlines" | "tariff" | "more";
export type NavEntry = { href: string; label: string; icon: NavIcon; hint: string };

export const HOME_ENTRY: NavEntry = { href: "/", label: "Accueil", icon: "home", hint: "" };
export const MORE_ENTRY: NavEntry = { href: "/plus/", label: "Plus", icon: "more", hint: "" };

/** Toutes les pages du compte, dans l'ordre d'affichage (l'accueil en tête). */
export function staffEntries(me: (Pick<User, "teacher_space" | "review_space" | "attendance_space" | "directory" | "applications"> & { finance?: User["finance"] }) | null | undefined): NavEntry[] {
  if (!me) return [HOME_ENTRY];
  const lists = me.directory ?? [];
  return [
    HOME_ENTRY,
    ...(me.teacher_space
      ? [
          { href: "/presence/", label: "Présence", icon: "presence" as const, hint: "Faire l’appel, suivre les absences de vos étudiants et la limite des 15 %. Fonctionne aussi sans réseau." },
          { href: "/notes/", label: "Notes", icon: "notes" as const, hint: "Saisir les notes de vos cours, les transmettre au registrariat et suivre leur validation. Fonctionne aussi sans réseau." },
        ]
      : []),
    ...(me.review_space ? [{ href: "/validations/", label: "Validations", icon: "review" as const, hint: "Contrôler les feuilles transmises par les enseignants, les valider (annulable 10 secondes) ou les renvoyer avec un motif." }] : []),
    ...(me.attendance_space ? [{ href: "/assiduite/", label: "Assiduité", icon: "attendance" as const, hint: "Étudiants au-delà de 15 % d’absences à traiter, retards des enseignants, registres sans séances prévues." }] : []),
    ...(lists.includes("students") ? [{ href: "/etudiants/", label: "Étudiants", icon: "students" as const, hint: "Rechercher et consulter les fiches des étudiants." }] : []),
    ...(lists.includes("courses") ? [{ href: "/cours/", label: "Cours", icon: "courses" as const, hint: "Les cours, leur enseignant, leur niveau et leurs crédits." }] : []),
    ...(lists.includes("teachers") ? [{ href: "/enseignants/", label: "Enseignants", icon: "teachers" as const, hint: "Les fiches des enseignants et leurs cours." }] : []),
    ...(me.applications ? [{ href: "/candidatures/", label: "Candidatures", icon: "applications" as const, hint: "Instruire les candidatures : en cours, acceptée, refusée." }] : []),
    ...(me.finance?.reenrolment ? [{ href: "/finance/reinscription/", label: "Réinscription", icon: "reenrolment" as const, hint: "Autoriser la réinscription (caisse) et valider les dossiers (registraire), étudiant par étudiant ou par groupe." }] : []),
    ...(me.finance?.balances ? [{ href: "/finance/soldes/", label: "Soldes", icon: "balances" as const, hint: "Dette ou avance de chaque étudiant inscrit à une session ; correction du solde enregistré." }] : []),
    ...(me.finance?.deadlines ? [{ href: "/finance/echeances/", label: "Échéances", icon: "deadlines" as const, hint: "La date limite de chaque tranche de paiement, session par session : elle s’imprime sur la fiche d’inscription." }] : []),
    ...(me.finance?.tariff ? [{ href: "/finance/bareme/", label: "Barème", icon: "tariff" as const, hint: "Les tarifs de chaque filière, niveau et statut, ajustables à chaque session ; frais supplémentaires." }] : []),
  ];
}

/** Les entrées de la barre du bas (5 au plus) et celles rangées sous « Plus ». */
export function staffBar(entries: readonly NavEntry[]): { bar: NavEntry[]; more: NavEntry[] } {
  if (entries.length <= 5) return { bar: [...entries], more: [] };
  return { bar: [...entries.slice(0, 4), MORE_ENTRY], more: entries.slice(4) };
}

/** Une adresse allume une entrée : l'accueil ne correspond qu'à lui-même, les autres aussi à leurs sous-pages. */
export function matches(href: string, pathname: string): boolean {
  const current = pathname.replace(/\/+$/, "") || "/";
  const target = href.replace(/\/+$/, "") || "/";
  return target === "/" ? current === "/" : current === target || current.startsWith(`${target}/`);
}
