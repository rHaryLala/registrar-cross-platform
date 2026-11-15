# Registrar UAZ — nouvelle application

Refonte de l'application Registrar de l'Université Adventiste Zurcher (`registrar.zurcher.edu.mg`) : web, Android, iOS, Windows et macOS à partir d'une seule application (PWA). L'ancien PHP reste en service pendant toute la migration, sur la **même base de données**.

| Dossier | Contenu |
|---|---|
| `api/` | API Laravel 13 (`/api/v1`) sur la base MySQL existante. Session par cookie (Sanctum SPA). |
| `web/` | Application Next.js 16 en **export statique** (SPA/PWA) : shadcn/ui, Tailwind 4, GSAP, TanStack Query. |
| `docs/` | Études 01 à 05, schéma de production (structure seule), maquettes. **Lire d'abord** `docs/ETUDE-05-api-et-authentification.md`. |
| `deploy/` | Exemples de configuration serveur (nginx, PHP-FPM, variables). **Non appliqués, non testés.** |

## État

Trois tranches vérifiées de bout en bout dans un navigateur **et sur une copie de vos vraies données** :
1. connexion (identifiant + mot de passe, Google côté API) → accueil étudiant → déconnexion ;
2. **Mes notes** : sessions publiées, moyennes par session et cumulative, notes de promotion, navigation (barre du bas sur téléphone, en-tête sur ordinateur). Parité avec l'ancienne page sur les **180 comptes étudiants actifs** : 0 écart, 0 session masquée exposée.

3. **Mon assiduité** : présences, retards, absences par cours, seuil des 15 % (§472 à §476), séances sans pénalité (§474). Parité avec l'ancien code sur vos **76 étudiants et 94 registres** : 0 écart ; plus 500 scénarios aléatoires comparés champ par champ aux vraies fonctions de l'ancienne application.

Plan des pages suivantes : [docs/PLAN-PAGES.md](docs/PLAN-PAGES.md) ; inventaire des 127 routes avec leur usage réel : [docs/INVENTAIRE-PAGES.md](docs/INVENTAIRE-PAGES.md) (régénérable : `python tools/inventaire.py`).
Les autres rôles voient une page d'attente honnête. Non faits : repli enseignant et création de compte étudiant à la première connexion Google, billet de pont vers l'ancien PHP (`legacy-login.php`), « se souvenir de moi », hors ligne (service worker), tous les autres écrans.

## Prérequis (poste Windows / Laragon)

- PHP **8.3** (`C:\laragon\bin\php\php-8.3.30-…`), pas celui du PATH (8.5, sans `pdo_mysql`). La production est en 8.3.6 : `composer.json` verrouille la plateforme.
- Composer, Node 24+, MySQL de Laragon.
- Base de développement `registrar-crossplatform` et base de test `registrar-crossplatform_test` : **structure seule** copiée de la production (`docs/reference/schema-prod-*.sql`). Aucune donnée réelle d'étudiant n'est jamais chargée.

## Bases de données locales (MySQL de Laragon)

| Base | Contenu | Usage |
|---|---|---|
| `registrar-crossplatform` | **Copie locale de la sauvegarde réelle** (stockée hors du dépôt, importée le 14/11/2026) | Développement : se connecter avec ses vrais identifiants |
| `registrar-crossplatform_demo` | Structure + 4 comptes **fictifs** | Test de bout en bout (`.\start-dev.ps1 -Demo`) |
| `registrar-crossplatform_test` | Structure seule, vide | Tests PHP (annulés à la fin de chaque test) |

- La sauvegarde reste sur `G:` : **elle n'est jamais copiée dans le dépôt** (`.gitignore` écarte aussi `registrar_db.sql`). Les vraies données d'étudiants ne sortent pas de ce poste.
- **Ne jamais lancer `DemoSeeder` sur la base réelle** (il ajouterait de faux comptes dans votre copie).
- Les tests PHP refusent de tourner sur une base dont le nom ne finit pas par `_test`.
- Les serveurs de développement n'écoutent que sur le **bouclage local** (127.0.0.1 / ::1) : la copie des vraies données ne doit jamais être joignable depuis le réseau. Ne pas les relancer avec `--host=0.0.0.0` ni `next dev -H 0.0.0.0`.
- La production est un serveur **MySQL 8.0.46** (et non MariaDB, erreur corrigée dans l'étude 01).

## Lancer en local

```powershell
.\start-dev.ps1          # base réelle locale ; ouvre 3 fenêtres : API, relais, application
.\start-dev.ps1 -Demo    # base de démonstration (mot de passe commun : mot-de-passe-test)
# puis http://localhost:3000
```

Connexion par identifiant + mot de passe (Google n'est pas activé en local). **Seuls les comptes étudiants ont un accueil** dans cette première tranche ; les autres rôles (dont superadmin et registrariat) arrivent sur une page « arrive bientôt » qui renvoie vers l'ancienne version.

Lancement manuel (dossier `api/`, PHP 8.3 explicite) : `php artisan serve --host=127.0.0.1 --port=8001`, puis dans `web/` : `node e2e/dev-proxy.mjs` et `NEXT_PUBLIC_API_URL=http://localhost:8000 npm run dev`.

## Tests

```bash
cd api && php artisan test            # 165 tests : connexion, Google (vraie cryptographie), notes et assiduité étudiantes (parité avec l'ancien code), erreurs
cd web && npm test                    # 30 tests : client d'API (CSRF, réessai sur 419, hors réseau), formats, reprise après échec de chargement
cd web && npm run lint && npx tsc --noEmit
# De bout en bout (Chrome réel, 61 vérifications) : base DE DÉMONSTRATION uniquement
.\start-dev.ps1 -Demo                 # puis, dans web/ : npm run build (avec NEXT_PUBLIC_API_URL=http://localhost:8000)
cd web && python -m http.server 3000 -d out   # (à la place de « npm run dev »)
cd api && php artisan cache:clear     # remet à zéro la limitation de débit (10 essais / 15 min / identifiant)
cd web && npm run e2e
```

## Règles à ne pas casser

- **Une note n'est visible d'un étudiant que si sa session est publiée** (`t_notes_publication.publiee = 1`) : la règle vit dans la requête (`api/app/Domain/Student/StudentHome.php`), jamais dans l'écran. La moyenne n'inclut jamais une session masquée.
- **Aucune migration destructrice** : on ne renomme ni ne supprime de table ou de colonne tant que l'ancien PHP tourne (Moodle lit des vues `v_moodle_*`). Les nouvelles migrations ne font qu'ajouter.
- **Un seul journal** : les connexions s'écrivent dans `security_logs`, comme l'ancienne application, avec le motif d'échec. Jamais de mot de passe ni d'identifiant saisi inconnu dans le journal.
- Les erreurs de l'API sont en `application/problem+json` avec un `code` stable ; le front décide d'après le code, jamais d'après le texte.

## Windows : deux pièges rencontrés

1. **`127.0.0.1` refusé par intermittence** (erreur 10013 / `ERR_NETWORK_ACCESS_DENIED`) pour PHP et Chrome, pas pour Node ni le client MySQL. Cause non identifiée (filtre réseau du poste). Contournements : base en `DB_HOST=localhost`, serveurs de test en double pile IPv4/IPv6, et le test navigateur relance une page *uniquement* sur cette erreur précise et l'indique.
2. **`php artisan serve` ne traite qu'une connexion à la fois** : les connexions « spéculatives » de Chrome le bloquent. D'où le relais `web/e2e/dev-proxy.mjs`. Sans objet en production (nginx + PHP-FPM).

## Nouveautés de versions (14/11/2026)

Laravel est en **13.x** (pas 12), Next.js en **16.4**, shadcn utilise le style `base-nova` (Base UI) et le paquet `cn` de shadcn. Le `AGENTS.md` généré par Next.js demande de lire `node_modules/next/dist/docs/` avant de coder : c'est fait pour l'export statique. Le squelette Laravel proposait en outre d'installer « Laravel Boost » et des installeurs `curl | bash` : **non installés**, fichiers supprimés.
