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

À prévoir aussi (ajouté le 15/11/2026) :
- `STUDENT_PHOTOS_PATH` dans `.env` : dossier `app/photosetudiants` de l'ancienne application (lecture seule pour l'utilisateur PHP-FPM de l'API). Sans lui, les fiches s'impriment avec un cadre « Photo » vide.
- `storage/app/dompdf` doit être inscriptible (cache des polices du PDF) ; extensions PHP : `gd` (réduction des photos), `exif` (redressement), `mbstring`, `dom`.
- **Actualités synchronisées** : ajouter la tâche cron `* * * * * cd /var/www/registrar-api && php artisan schedule:run >> /dev/null 2>&1` (le planificateur lance `news:sync` toutes les 30 minutes) ; le serveur doit pouvoir joindre `https://zurcher.edu.mg` en sortie (HTTPS, port 443). Premier remplissage : `php artisan news:sync`. Supervision : `t_site_news_sync` (un statut `failed` répété = site injoignable ou gabarit changé).
- Les tables `t_quiz_*` sont créées par `php artisan migrate` (ajout seulement) ; la banque de questions par `php artisan quiz:import-questions`.
