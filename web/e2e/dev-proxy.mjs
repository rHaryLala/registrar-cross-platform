/**
 * Relais de développement (Windows) : node :8000 → PHP :8001.
 *
 * `php artisan serve` ne traite qu'UNE connexion à la fois sous Windows (pas de mode multi-processus).
 * Chrome ouvre des connexions « spéculatives » qui bloquent alors le serveur et font échouer des requêtes
 * au hasard. Ce relais accepte toutes les connexions du navigateur et n'en ouvre qu'une courte par requête.
 * Sans effet en production (nginx + PHP-FPM, multi-processus).
 *
 *   php artisan serve --host=127.0.0.1 --port=8001   (dans api/)
 *   node e2e/dev-proxy.mjs                            (dans web/)
 */
import http from "node:http";

const TARGET = { host: "127.0.0.1", port: Number(process.env.API_PORT ?? 8001) };
const LISTEN = Number(process.env.PROXY_PORT ?? 8000);

const handler = (req, res) => {
  const upstream = http.request(
    { ...TARGET, method: req.method, path: req.url, agent: false, headers: { ...req.headers, connection: "close" } },
    (r) => {
      res.writeHead(r.statusCode ?? 502, r.headers);
      r.pipe(res);
    },
  );
  upstream.on("error", (e) => {
    res.writeHead(502, { "Content-Type": "text/plain" });
    res.end(`Relais : API injoignable (${e.message})`);
  });
  req.pipe(upstream);
};

// BOUCLAGE LOCAL SEULEMENT (IPv4 et IPv6) : derrière ce relais peut se trouver une copie de vraies données d'étudiants,
// elle ne doit jamais être joignable depuis le réseau. (Écouter sur « :: » l'ouvrirait à toutes les interfaces.)
for (const host of ["127.0.0.1", "::1"]) {
  http.createServer(handler).listen(LISTEN, host, () => console.log(`relais http://[${host}]:${LISTEN} -> http://${TARGET.host}:${TARGET.port}`));
}
