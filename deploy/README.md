# Déploiement (exemples)

> **Rien ici n'est appliqué au serveur ni testé.** Ce sont des points de départ à relire avant toute mise en production, qui demande l'accord explicite du propriétaire du serveur.

État relevé sur le VPS `registrar-vps` le 14/11/2026 : Ubuntu 24.04, nginx 1.24, PHP 8.3.6-fpm, MySQL 8.0.46, Node 22 dans `/opt/node22`. `registrar.zurcher.edu.mg` est servi depuis `/var/www/registrar` (pas de git sur le serveur). Le vhost actuel redirige tout vers l'ancien `index.php`.

Ordre prudent :
1. Créer un **utilisateur MySQL dédié** à l'API (jamais celui de l'ancienne application, jamais root) avec seulement SELECT/INSERT/UPDATE/DELETE sur la base.
2. Déployer `api/` dans `/var/www/registrar-api` (sans `.env` dans le dépôt), `composer install --no-dev`, droits sur `storage/` et `bootstrap/cache/`.
3. Créer un **pool PHP-FPM dédié** (`php-fpm/registrar-api.pool.conf.example`) pour que l'API ne prive pas Moodle de processus.
4. Construire `web/` avec `NEXT_PUBLIC_BASE_PATH=/app` et copier `out/` dans `/var/www/registrar-app`.
5. Ajouter les deux `location` de `nginx/registrar-coexistence.conf.example` au vhost existant, `nginx -t`, recharger.
6. Retour arrière : retirer les deux `location` et recharger : l'ancien site n'a pas été touché.
