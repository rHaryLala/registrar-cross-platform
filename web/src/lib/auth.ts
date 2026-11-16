"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, ApiError } from "@/lib/api";

export type Role =
  | "superadmin"
  | "admin"
  | "registrar"
  | "comptabilite"
  | "media"
  | "chef_mention"
  | "teacher"
  | "student"
  | "work_education";

export type User = {
  id: number;
  first_name: string;
  last_name: string;
  display_name: string;
  email: string | null;
  role: Role | null;
  level: number;
  student_id: string | null;
  /** Espace enseignant : un enseignant ou chef de mention, ou un membre du personnel qui est aussi enseignant. */
  teacher_space: boolean;
  /** Validation des remises de notes : le registrariat (niveaux 1 à 3). */
  review_space: boolean;
  /** Assiduité du registrariat (signalements au-delà de 15 %, retards des enseignants) : superadmin et registrariat. */
  attendance_space: boolean;
  /** Listes du registrariat que ce compte peut ouvrir (étudiants : tout le personnel ; cours et enseignants : superadmin, registrariat). */
  /** Tableau de bord du personnel (à traiter, chiffres clés). */
  dashboard: boolean;
  directory: ("students" | "courses" | "teachers")[];
  /** Candidatures de pré-admission : superadmin et registrariat. */
  applications: boolean;
  /** Créer un étudiant : superadmin, administration et registrariat (niveaux 1 à 3). */
  student_creation: boolean;
  /** Finance : autorisation de réinscription (niveaux 1, 3 et 4, avec ce que le compte peut y écrire) et solde de compte (niveaux 1 à 4) et échéances de paiement (niveaux 1 à 4) ; null : aucune page. */
  finance: { reenrolment?: { authorization: boolean; file: boolean; reset: boolean }; balances?: boolean; deadlines?: boolean; tariff?: boolean } | null;
};

export const meKey = ["me"] as const;

const REMEMBERED = "registrar:me";

/** Dernière identité confirmée par le serveur (nom, rôle) : sert à rouvrir l'application sans réseau. Effacée à la déconnexion. */
function rememberUser(user: User | null) {
  try {
    if (user) window.localStorage.setItem(REMEMBERED, JSON.stringify(user));
    else window.localStorage.removeItem(REMEMBERED);
  } catch {
    /* stockage indisponible : on s'en passe */
  }
}

function rememberedUser(): User | null {
  try {
    const raw = window.localStorage.getItem(REMEMBERED);
    return raw ? (JSON.parse(raw) as User) : null;
  } catch {
    return null;
  }
}

/** `null` = personne n'est connecté ; `undefined` pendant le chargement. */
export function useMe() {
  return useQuery({
    queryKey: meKey,
    queryFn: async (): Promise<User | null> => {
      try {
        const user = (await api<{ data: User }>("/api/v1/auth/me")).data;
        rememberUser(user);
        return user;
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) {
          rememberUser(null);
          return null;
        }
        // Sans réseau, on garde l'identité connue : l'application se rouvre (copies, changements en attente). Chaque écriture
        // reste vérifiée par le serveur à l'envoi : cette identité ne donne aucun droit.
        if (e instanceof ApiError && e.isOffline) {
          const known = rememberedUser();
          if (known) return known;
        }
        throw e;
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  });
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { identifier: string; password: string }) =>
      api<{ data: User }>("/api/v1/auth/login", { method: "POST", body: v }),
    onSuccess: ({ data }) => qc.setQueryData(meKey, data),
  });
}

export function useGoogleLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (credential: string) => api<{ data: User }>("/api/v1/auth/google", { method: "POST", body: { credential } }),
    onSuccess: ({ data }) => qc.setQueryData(meKey, data),
  });
}

export function useLogout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api("/api/v1/auth/logout", { method: "POST" }),
    // Seulement quand le SERVEUR a confirmé la fermeture de la session : sinon la personne croirait
    // avoir quitté un appareil partagé alors que sa session y reste ouverte.
    // Sur un appareil partagé, rien de la personne précédente ne doit rester en mémoire.
    onSuccess: () => {
      rememberUser(null);
      qc.clear();
      qc.setQueryData(meKey, null);
    },
  });
}
