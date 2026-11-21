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

## Espace enseignant : hors ligne

- **`/sw.js`** (service worker) doit être servi avec `Cache-Control: no-cache` (et jamais en cache longue durée) : c'est lui qui porte les mises à jour de l'application. Les fichiers `/_next/static/…` portent leur empreinte : cache long possible.
- Le service worker ne copie **que** la coque de l'application ; il laisse passer `/api/…` sans rien garder. Si l'API est servie sur une AUTRE origine, rien à régler non plus (il ignore les autres origines).
- Les écritures de présence passent par `PUT/POST/PATCH/DELETE /api/v1/teacher/…` (limite : 600 par minute et par compte, pour qu'une file hors ligne se rejoue en rafale). Chaque écriture ajoute des lignes au journal d'activité (`t_audit_*`).

## Remise de notes : migration à appliquer

`php artisan migrate --force` crée **deux tables nouvelles** (`t_remise_notes_composante`, `t_remise_notes_valeur`) ; aucune table existante n'est modifiée (l'ancien PHP continue de lire `t_remise_notes` et `t_remise_notes_ligne`). À faire AVANT d'ouvrir la remise de notes aux enseignants dans la nouvelle application. Les 5 premières colonnes d'une feuille sont recopiées dans les colonnes historiques à chaque écriture : l'ancienne validation du registrariat reste exacte jusqu'à 5 colonnes et refuse (total ≠ 100 %) au-delà, jamais une note fausse.

## Validation des remises : migration et planificateur

- `php artisan migrate --force` crée aussi `t_remise_validation_attente` (une ligne par validation en cours, supprimée dès qu'elle aboutit ou est annulée). Aucune table existante n'est modifiée.
- La tâche cron `* * * * * cd /var/www/registrar-api && php artisan schedule:run` (déjà requise pour les actualités) lance chaque minute `remises:finalize-due` : elle termine les validations dont le délai d'annulation (10 s) est passé et dont l'onglet a été fermé. Sans elle, une validation n'aboutit que si l'écran qui l'a demandée reste ouvert.

## Lot 4 (registrariat) : migrations, planificateur, courriel, SMS, Google

- `php artisan migrate --force` crée **deux tables nouvelles** : `t_candidat_notification` (notifications aux candidats) et `t_etudiant_creation` (jeton anti-doublon et état du compte Google d'un étudiant créé). Aucune table existante n'est modifiée.
- Le planificateur (même cron `* * * * * … php artisan schedule:run` que pour les actualités et les remises) lance deux commandes de plus, chaque minute : `applications:notify` (courriels et SMS aux candidats) et `students:google-accounts` (comptes Google). Sans le cron, **les décisions sont enregistrées mais aucun message ne part**.
- **Courriel** : `MAIL_MAILER=smtp` et `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` (reprendre les réglages SMTP de l'ancienne application, `data/config.local.php`). Avec `MAIL_MAILER=log` (défaut du développement) rien ne part, les messages sont écrits dans le journal.
- **SMS** : `SMS_PROVIDER=journal` (défaut) n'envoie RIEN et écrit `storage/logs/sms.log` ; pour envoyer, `SMS_PROVIDER=twilio` avec `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM`. Le fournisseur « passerelle » de l'ancien code n'est pas repris.
- **Google Admin** (comptes des étudiants) : `GOOGLE_ADMIN_KEY` = chemin de la clé JSON du compte de service (déjà utilisée par l'ancienne application : `data/prive/google-admin.json`, **hors dépôt**, lisible par l'utilisateur PHP), `GOOGLE_ADMIN_SUBJECT` (administrateur emprunté, `it@zurcher.edu.mg` par défaut), `GOOGLE_ADMIN_DOMAIN`. Sortie HTTPS vers `oauth2.googleapis.com` et `admin.googleapis.com`. Sans clé : aucun compte n'est créé, la fiche le dit.
- Le cache applicatif sert au jeton Google (une heure) : le pilote de cache par défaut convient.

## Lot 5 (finance) : autorisation de réinscription

- **Aucune migration.** L'écran lit et écrit `tbl_2024_etudiant` (drapeaux `authorized_reinscription`, `dossier_ok` et leurs auteurs/dates), `t_student_modification_history` et `t_reinscription_reinitialisation` : trois tables de l'ancienne application. **Vérifier avant la mise en ligne** que `t_reinscription_reinitialisation` existe en production (`data/migration_reinscription_reinitialisation.sql` de l'ancien dépôt, rejouable) : sans elle la réinitialisation globale est refusée (409), le reste fonctionne.
- Pas de tâche planifiée, pas de réglage nouveau. Le journal d'activité reçoit une ligne par bascule, par action groupée, par réinitialisation et par annulation (les mêmes tables `t_audit_*` que l'ancienne application).

### Soldes de compte

- **Aucune migration** (table `finance` de l'ancienne application). Vérifier que la colonne `finance.balance_amount` existe en production (`data/sql_finance_balance_amount.sql` de l'ancien dépôt) : sans elle les soldes sont calculés (total − versements) et la correction est refusée (409).
- Accès niveaux 1 à 4 : la comptabilité y a accès (l'ancienne page s'arrêtait au niveau 3).

### Échéances de paiement

- **Aucune migration** : table `t_paiement_echeance` de l'ancienne application (`data/migration_paiement_echeances.sql`, rejouable). Elle est aussi lue par la fiche d'inscription de l'espace étudiant : à vérifier en production avant toute mise en ligne.
- Accès niveaux 1 à 4 (la comptabilité y a accès, l'ancienne page s'arrêtait au niveau 3).

### Barème financier et frais supplémentaires

- **Une migration** : `php artisan migrate --force` joue `2026_11_18_000001_create_extra_fee_tables`, qui crée `t_frais_perso`, `t_frais_perso_montant` et `t_2024_etudiant_frais_perso` **seulement si elles manquent** (l'ancienne application les créait à la première ouverture de sa page « Gestion finance » : si cette page a déjà été ouverte en production, rien ne change). Aucune table existante n'est modifiée.
- Le barème est dans `t_2024_finance_detail_licence` (table de l'ancienne application, colonne `session_id` : 0 = barème de base).
- Accès niveaux 1 à 3 (comme l'ancienne page). Journal d'activité : une entrée par enregistrement, reprise, retour à la base et réglage de frais.

### Documents du personnel (lot 6)

- **Aucune migration.** Le PDF est fabriqué par dompdf : le dossier `storage/app/dompdf` doit être **inscriptible** par l'utilisateur de PHP-FPM (polices et fichiers temporaires). La photo est lue dans `STUDENT_PHOTOS_PATH`, comme pour la fiche de l'espace étudiant.
- Accès niveaux 1 à 4. La réponse n'est jamais mise en cache : ne pas ajouter de cache de réponses devant `/api/v1/staff/students/*/enrollment-sheets/*/pdf`. Limite de 30 PDF par minute et par compte.
