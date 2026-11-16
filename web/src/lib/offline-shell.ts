"use client";

/**
 * Coque hors ligne : enregistrement du service worker, et « réchauffage » des pages de l'espace enseignant.
 *
 * Le service worker ne copie que ce qu'il a vu passer. Sans réchauffage, une page jamais ouverte avec du réseau (par exemple
 * l'écran d'appel, que l'enseignant ouvre pour la première fois dans un amphi sans connexion) ne serait pas disponible.
 * On demande donc ces pages, avec leurs scripts et leurs styles, une fois connecté en ligne.
 */

const BASE = process.env.NEXT_PUBLIC_BASE_PATH ?? "";

/** Enregistre le service worker (production seulement : en développement, il masquerait les changements de code). */
export function registerServiceWorker(): void {
  if (typeof navigator === "undefined" || !("serviceWorker" in navigator) || process.env.NODE_ENV !== "production") return;
  navigator.serviceWorker.register(`${BASE}/sw.js`, { scope: `${BASE}/` }).catch(() => undefined);
}

/** Pages de l'espace enseignant à garder pour un usage sans réseau. */
const TEACHER_PAGES = ["/", "/presence/", "/presence/registre/", "/presence/appel/", "/notes/", "/notes/feuille/"];

const ASSET = /\/_next\/static\/[^"'\\\s)]+\.(?:js|css)/g;

let warmed = false;

/** Charge les pages de l'enseignant et leurs fichiers pour que le service worker les garde. Sans effet hors ligne. */
export async function warmTeacherShell(): Promise<void> {
  if (warmed || typeof navigator === "undefined" || !navigator.onLine || process.env.NODE_ENV !== "production") return;
  warmed = true;
  try {
    // Le service worker doit être en place pour garder ce qui passe : on l'attend (au plus 5 s), sans bloquer l'application.
    if ("serviceWorker" in navigator) await Promise.race([navigator.serviceWorker.ready, new Promise((resolve) => setTimeout(resolve, 5000))]);
    const assets = new Set<string>();
    for (const page of TEACHER_PAGES) {
      const res = await fetch(`${BASE}${page}`, { credentials: "same-origin" });
      if (!res.ok) continue;
      for (const m of (await res.text()).matchAll(ASSET)) assets.add(m[0]);
    }
    for (const asset of assets) await fetch(`${BASE}${asset}`, { credentials: "same-origin" }).catch(() => undefined);
  } catch {
    warmed = false; // coupure pendant le réchauffage : on réessaiera à la prochaine ouverture
  }
}
