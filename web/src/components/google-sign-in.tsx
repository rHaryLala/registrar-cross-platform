"use client";

import { useEffect, useRef } from "react";

const CLIENT_ID = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID ?? "";
const SCRIPT_SRC = "https://accounts.google.com/gsi/client";

type GoogleId = {
  initialize(options: { client_id: string; callback: (response: { credential: string }) => void; ux_mode?: "popup" }): void;
  renderButton(element: HTMLElement, options: Record<string, string | number>): void;
};

declare global {
  interface Window {
    google?: { accounts: { id: GoogleId } };
  }
}

function loadScript(): Promise<void> {
  return new Promise((resolve, reject) => {
    if (window.google?.accounts?.id) return resolve();
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${SCRIPT_SRC}"]`);
    const script = existing ?? Object.assign(document.createElement("script"), { src: SCRIPT_SRC, async: true, defer: true });
    script.addEventListener("load", () => resolve(), { once: true });
    script.addEventListener("error", () => reject(new Error("google-script")), { once: true });
    if (!existing) document.head.appendChild(script);
  });
}

/**
 * Bouton « Continuer avec Google » (Google Identity Services).
 * Le jeton reçu est envoyé tel quel à l'API, qui le vérifie : le navigateur ne décide de rien.
 */
export function GoogleSignIn({ onCredential, onUnavailable }: { onCredential: (credential: string) => void; onUnavailable?: () => void }) {
  const host = useRef<HTMLDivElement>(null);
  const callback = useRef(onCredential);
  const unavailable = useRef(onUnavailable);

  useEffect(() => {
    callback.current = onCredential;
    unavailable.current = onUnavailable;
  });

  useEffect(() => {
    if (!CLIENT_ID || !host.current) return;
    let cancelled = false;
    loadScript()
      .then(() => {
        if (cancelled || !host.current || !window.google) return;
        window.google.accounts.id.initialize({ client_id: CLIENT_ID, callback: (r) => callback.current(r.credential), ux_mode: "popup" });
        window.google.accounts.id.renderButton(host.current, {
          type: "standard",
          theme: "outline",
          size: "large",
          text: "continue_with",
          shape: "rectangular",
          logo_alignment: "left",
          width: Math.min(host.current.clientWidth || 320, 400),
          locale: "fr",
        });
      })
      .catch(() => unavailable.current?.());
    return () => {
      cancelled = true;
    };
  }, []);

  if (!CLIENT_ID) {
    return (
      <p className="rounded-lg border border-dashed border-border p-3 text-sm text-muted-foreground">
        La connexion Google n’est pas activée sur cet environnement. Utilisez votre matricule (ou votre adresse e-mail) et votre mot de passe.
      </p>
    );
  }

  return <div ref={host} className="flex min-h-11 w-full justify-center" aria-label="Continuer avec Google" />;
}
