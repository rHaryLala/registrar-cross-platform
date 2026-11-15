"use client";

import { useState } from "react";
import { API_BASE } from "@/lib/api";
import { cn } from "@/lib/utils";

/**
 * MA photo, servie par l'API (cookie de session, réduite côté serveur). `use-credentials` : en développement le site et l'API
 * ne sont pas sur le même port. Si l'image ne charge pas (fichier disparu entre-temps), on la retire plutôt que d'afficher une
 * icône cassée.
 */
export function StudentPhotoImage({ className }: { className?: string }) {
  const [failed, setFailed] = useState(false);
  if (failed) return null;
  return (
    // eslint-disable-next-line @next/next/no-img-element -- image protégée par la session, réduite côté serveur : next/image n'apporte rien ici
    <img
      src={`${API_BASE}/api/v1/me/photo`}
      alt="Photo de l’étudiant"
      width={84}
      height={108}
      crossOrigin="use-credentials"
      onError={() => setFailed(true)}
      className={cn("h-27 w-21 shrink-0 rounded-lg border object-cover", className)}
    />
  );
}
