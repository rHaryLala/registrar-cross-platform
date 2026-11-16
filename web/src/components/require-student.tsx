"use client";

import type { ReactNode } from "react";
import { RequireRole } from "@/components/require-role";

/** Garde d'une page réservée aux étudiants (voir RequireRole). */
export function RequireStudent({ children }: { children: ReactNode }) {
  return (
    <RequireRole allow={["student"]} reserved="aux étudiants">
      {children}
    </RequireRole>
  );
}
