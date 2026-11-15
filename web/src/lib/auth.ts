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
};

export const meKey = ["me"] as const;

/** `null` = personne n'est connecté ; `undefined` pendant le chargement. */
export function useMe() {
  return useQuery({
    queryKey: meKey,
    queryFn: async (): Promise<User | null> => {
      try {
        return (await api<{ data: User }>("/api/v1/auth/me")).data;
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null;
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
      qc.clear();
      qc.setQueryData(meKey, null);
    },
  });
}
