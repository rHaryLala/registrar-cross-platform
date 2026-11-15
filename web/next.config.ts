import type { NextConfig } from "next";

/**
 * Application statique (SPA) : `next build` produit le dossier `out/`, servi tel quel par nginx.
 * Aucun serveur Node en production : toutes les données viennent de l'API Laravel, côté navigateur.
 *
 * NEXT_PUBLIC_BASE_PATH : "" (racine) en développement ; "/app" pendant la coexistence avec l'ancien PHP.
 */
const basePath = process.env.NEXT_PUBLIC_BASE_PATH ?? "";

const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true, // /login/ -> out/login/index.html : simple à servir avec nginx
  images: { unoptimized: true }, // pas de serveur d'optimisation d'images en export statique
  basePath: basePath || undefined,
  reactStrictMode: true,
  // Tailwind v4 sous Turbopack : sans ce chargeur, @theme et les classes utilitaires ne sont pas traités.
  turbopack: {
    rules: {
      "*.css": { loaders: ["@tailwindcss/turbopack"], as: "*.css" },
    },
  },
};

export default nextConfig;
