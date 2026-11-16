/*
 * Service worker de Registrar : garde la COQUE de l'application (pages, scripts, styles) pour la rouvrir sans réseau.
 *
 * Il ne garde JAMAIS de données : toute requête vers l'API (/api/…) ou vers une autre origine passe par le réseau, sans
 * aucune copie ici. Les données d'un compte (copies des registres, file des changements) vivent dans IndexedDB, rangées par
 * compte et effacées à la déconnexion (src/lib/offline-db.ts).
 *
 *  - fichiers /_next/static/… : le nom contient l'empreinte du contenu, donc « cache d'abord » est sans risque ;
 *  - pages et charges de navigation : « réseau d'abord » (on a toujours la dernière version en ligne), la copie ne sert
 *    qu'en cas de coupure. La chaîne de requête est ignorée : /presence/appel/?seance=3 et ?seance=4 sont la même page.
 *
 * Ce fichier doit être servi avec « Cache-Control: no-cache » pour que les mises à jour arrivent (voir deploy/README.md).
 */
const CACHE = "registrar-shell-v1";

self.addEventListener("install", () => self.skipWaiting());

self.addEventListener("activate", (event) => {
  event.waitUntil(
    (async () => {
      for (const key of await caches.keys()) if (key !== CACHE) await caches.delete(key);
      await self.clients.claim();
    })(),
  );
});

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith("/api/")) return; // le réseau seul décide
  event.respondWith(url.pathname.includes("/_next/static/") ? cacheFirst(req) : networkFirst(req));
});

async function cacheFirst(req) {
  const cache = await caches.open(CACHE);
  const hit = await cache.match(req);
  if (hit) return hit;
  const res = await fetch(req);
  if (res.ok) await cache.put(req, res.clone());
  return res;
}

async function networkFirst(req) {
  const cache = await caches.open(CACHE);
  try {
    const res = await fetch(req);
    // Pas de redirection ni de réponse partielle dans la copie : seulement une page complète.
    if (res.ok && res.status === 200 && !res.redirected) await cache.put(req, res.clone());
    return res;
  } catch (error) {
    const hit = await cache.match(req, { ignoreSearch: true });
    if (hit) return hit;
    throw error;
  }
}
