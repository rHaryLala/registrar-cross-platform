"use client";

import { useGSAP } from "@gsap/react";
import gsap from "gsap";
import type { RefObject } from "react";

gsap.registerPlugin(useGSAP);

/**
 * Apparition en cascade des éléments `selector` dans `scope` (30 ms entre deux, 300 ms chacun).
 *
 * - transform et opacité seulement : aucune animation de mise en page ;
 * - rien du tout si la personne a demandé moins de mouvement ;
 * - se relance quand `ready` passe à vrai (données arrivées), jamais pendant un chargement ;
 * - `clearProps` : aucun style résiduel après l'animation, le contenu reste interactif pendant.
 */
export function useEntrance(scope: RefObject<HTMLElement | null>, selector: string, ready: boolean) {
  useGSAP(
    () => {
      if (!ready) return;
      const mm = gsap.matchMedia();
      mm.add("(prefers-reduced-motion: no-preference)", () => {
        gsap.from(selector, { opacity: 0, y: 8, duration: 0.3, stagger: 0.03, ease: "power2.out", clearProps: "transform,opacity" });
      });
    },
    { scope, dependencies: [ready] },
  );
}
