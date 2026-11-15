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
cd api && php artisan test            # 474 tests : connexion, Google (vraie cryptographie), notes, assiduité et fiches d'inscription (parité avec l'ancien code), quiz (score côté serveur, classement), erreurs
cd web && npm test                    # 63 tests : client d'API (CSRF, réessai sur 419, hors réseau), formats, reprise après échec de chargement
cd web && npm run lint && npx tsc --noEmit
# De bout en bout (Chrome réel, 178 vérifications, dont le téléchargement d'un vrai PDF, la connexion par la fiche, Mes informations et Actualités) : base DE DÉMONSTRATION uniquement
.\start-dev.ps1 -Demo                 # puis, dans web/ : npm run build (avec NEXT_PUBLIC_API_URL=http://localhost:8000)
cd web && python -m http.server 3000 -d out   # (à la place de « npm run dev »)
cd api && php artisan db:seed --class=DemoSeeder   # AVANT chaque passage : remet à zéro le joueur de démonstration du quiz (base _demo seulement)
cd api && php artisan cache:clear     # remet à zéro la limitation de débit (10 essais / 15 min / identifiant)
cd web && npm run e2e
```

## Mes informations (profil) et menu « Plus »

`GET /api/v1/me/profile` : MA fiche d'étudiant, **en lecture seule** (l'ancienne page `student.info.php` n'écrivait rien non plus ; les modifications restent au registrariat). Aucun identifiant en paramètre, jamais de colonne secrète (le mot de passe n'est même pas lu), réponse jamais mise en cache. Règles de l'ancienne page reprises : ligne la plus récente non retirée ; niveau 0 à 5 ; type Nouveau (1) / Spécial (10) / Ancien ; sexe 0 = Féminin, vide = inconnu ; suspension (`suspended = 1`) et retrait (`retrait_universite >= 2`) ; région lue dans `region`. Contrôlé sur les 2 258 étudiants de la copie locale : 0 erreur, 6 suspendus et 13 retraits, identiques à un comptage SQL indépendant.

- **Écarts assumés** : la vue 3D « scroll » de l'ancienne page (gadget, peu accessible) est remplacée par des onglets ARIA (clavier : flèches, Début, Fin) ; une valeur vide s'écrit « — » ; les dates s'écrivent en français (« 12 mars 2001 ») et la date « zéro » de MySQL est ignorée (20 fiches sur 2 258 ont une date de naissance absente ou invalide) ; le **numéro de CIN est masqué par défaut** (4 derniers chiffres), avec un bouton pour l'afficher ; téléphones et e-mails sont des liens `tel:` / `mailto:`.
- **Menu « Plus »** : la barre du bas était pleine à 5 onglets ; elle est maintenant Accueil · Notes · Présence · Inscriptions · **Plus**. « Plus » (`/plus/`) range Mes informations et le Quiz, et reste allumé sur leurs pages ; sur ordinateur, l'en-tête les montre directement.

## Actualités synchronisées avec zurcher.edu.mg/actualites

La page « Actualités » a **deux sources**, sélectionnables : **Université** (par défaut : les actualités du site public, synchronisées) et **Registrariat** (les annonces internes `t_annonces`, voir plus bas).

- **Comment ça se synchronise** : `php artisan news:sync` (planifiée **toutes les 30 minutes**, `withoutOverlapping` ; exige la tâche cron `* * * * * php artisan schedule:run` sur le serveur) lit le **sitemap public** du site (autorisé par son `robots.txt`), compare la date de modification de chaque article et ne télécharge que les articles **nouveaux ou modifiés** (`--force` : tout ; `--dry-run` : dire ce qui changerait sans écrire). Une copie locale est tenue dans `t_site_news`, le journal des passages dans `t_site_news_sync` (tables ajoutées, aucune table existante modifiée). Un article retiré du site est **masqué**, jamais supprimé ; un sitemap vide, ou qui ferait disparaître plus de la moitié des articles, ne masque **rien** (jugé anormal). Si le site est injoignable, la dernière copie reste affichée : l'application ne dépend pas de la disponibilité du site pour s'afficher.
- **Aucun HTML venu du site n'est jamais réinjecté** : le corps de l'article est converti **côté serveur** en blocs (paragraphe, intertitre, liste, citation, image) par une liste blanche (`SiteArticleParser`), et l'écran les affiche sans `innerHTML` (re-vérifiant liens et images). `script`, `style`, `iframe`, `object`, `form`… disparaissent avec leur contenu ; un lien n'est gardé que s'il est http(s) ou mailto ; une image que si elle est en **https sur zurcher.edu.mg** (aucune image tierce). Le gabarit du site est reconnu par ses repères (`h1#nws-title`, `[data-nws-prose]`…) : s'il change, l'analyse échoue proprement, **la copie existante reste** et l'erreur est journalisée.
- **Hôte verrouillé** (`SITE_NEWS_BASE`, `https://zurcher.edu.mg`) : une adresse du sitemap vers un autre hôte est ignorée, une redirection vers un autre hôte est refusée, aucun lien du contenu n'est suivi, 15 s de délai, 3 Mo de réponse au plus, une requête toutes les 0,4 s, en-tête `User-Agent` générique (aucune donnée personnelle envoyée au site).
- **Validé sur le vrai site** (18 articles) : titre, date, auteur, résumé et nombre d'images identiques à une extraction indépendante (Python) des pages d'origine, et **texte intégral du corps identique pour les 18** (26 406 caractères) ; une 2ᵉ passe ne télécharge rien. Images : une **miniature ~685 px** pour la liste et une image ~1 170 px pour l'article, choisies parmi les variantes responsives du site (liste : ~0,7 Mo au lieu de ~3,7 Mo ; 16 articles sur 18 en ont, comme sur le site).
- **Limite** : le site n'expose pas d'API ni de flux (RSS/JSON) : on lit son HTML public. Le plus solide serait qu'il publie un flux JSON ; le code est isolé (`SiteArticleParser`) pour s'y brancher sans toucher au reste.

## Actualités : annonces du registrariat

`GET /api/v1/me/news` (liste, `?category=` filtre), `GET …/{id}` (détail) et `GET …/{id}/image` (image réduite à 1 200 × 800 px au plus, ETag, jamais le fichier d'origine). Lecture seule, comme l'ancienne page `student.actus.php`.

- **Qui voit quoi** : une annonce est visible si elle est active, déjà publiée (`publish_date` vide, passée ou du jour) et non expirée (`expire_date` vide, du jour ou future) ; « aujourd'hui » est la date de Madagascar. Une annonce masquée (programmée, expirée, désactivée) ou inconnue répond la même chose (404), y compris pour son image. Épinglées d'abord, puis la plus récente, puis l'identifiant (ordre total). **Parité** avec la vraie requête de l'ancienne page : mêmes annonces et même ordre sur 10 dates de la copie locale (dont les dates limites), 0 écart.
- **Contenu = texte brut** : l'API renvoie le texte tel quel, l'écran l'affiche comme du texte (React échappe). Une annonce contenant `<script>` ou `javascript:` s'affiche en toutes lettres et n'exécute rien (testé dans Chrome). Seules les adresses `http(s)` deviennent des liens (nouvel onglet, `noopener noreferrer nofollow`) ; la séquence littérale `\n` de la base devient un vrai saut de ligne, comme dans l'ancienne page.
- **Images** : le nom de fichier vient de la base, donc validé comme les photos d'étudiants (`App\Support\SafeImage` : pas de `..`, confinement dans `ANNOUNCEMENT_IMAGES_PATH`, type lu dans le fichier, taille et pixels plafonnés). Sur la copie locale, 1 des 2 images d'annonces est absente du dossier de l'ancienne app : l'écran la masque sans icône cassée.
- **Écarts assumés** : une catégorie = une icône ET un mot (jamais la couleur seule ; l'application n'a qu'une teinte d'accent, l'« Urgent » utilise le rouge d'erreur) ; l'extrait ne reçoit « … » que s'il a été coupé, en caractères et non en octets ; la vue « galerie » à glisser est remplacée par une liste filtrable et accessible.
- Rangée sous « Plus » (voir ci-dessus).

## Connexion étudiante par la fiche (sans Google)

Un étudiant peut entrer avec **son matricule ou l'adresse e-mail de sa fiche** et **le mot de passe de sa fiche** (`tbl_2024_etudiant.password`), même s'il n'a encore aucun compte utilisateur : le compte `compt_utilisateur` est alors créé, comme l'ancien code le fait à la première connexion Google (niveau 8, rôle étudiant, mot de passe aléatoire). La fiche n'est jamais modifiée.

- **Ce qui est protégé** : ce mot de passe n'ouvre **que** un compte de rôle étudiant (un compte du personnel rattaché à un matricule est refusé) ; compte inactif, étudiant retiré, pseudo déjà pris : refusés ; seule la fiche la plus récente d'un étudiant compte ; comparaison en temps constant ; même message pour « inconnu » et « mauvais mot de passe » ; mêmes limites de débit que le reste de la connexion ; jamais de mot de passe dans le journal. Interrupteur : `REGISTRAR_STUDENT_PASSWORD_LOGIN=false`.
- **ALERTE relevée le 15/11/2026 sur la copie locale** : ces mots de passe sont stockés **en clair** (aucune empreinte), et sur 2 263 fiches actives il n'y a que **672 mots de passe distincts : un seul est partagé par 1 584 étudiants** (le mot de passe initial). Avec le matricule, qui n'a rien de secret, ce mot de passe ouvrirait ~70 % des comptes à quiconque le connaît. L'ancienne application ne s'en servait pas pour la connexion (Google seulement) : l'activer tel quel créerait une faille que la production n'a pas aujourd'hui.
- **Règle par défaut** (`REGISTRAR_STUDENT_PASSWORD_MAX_SHARING=1`) : un mot de passe porté par 2 étudiants ou plus **n'ouvre aucun compte**, et la réponse est identique à celle d'un mauvais mot de passe (pas d'oracle), avec un message qui oriente vers Google ou le registrariat. Sur un échantillon de 600 étudiants réels : 30 % entrent avec leur fiche (mot de passe personnel), 68 % sont refusés (mot de passe commun). Mettre `0` lève la règle : à ne faire qu'en connaissance de cause.
- **Pour que tous puissent entrer** : donner à chaque étudiant un mot de passe **personnel** (réinitialisation en masse par le registrariat, ou changement de mot de passe par l'étudiant connecté via Google), puis, idéalement, stocker des empreintes (bcrypt, déjà accepté) au lieu du clair. À planifier.
- **Adresses partagées** (147 adresses sont portées par plusieurs fiches) : par e-mail, le mot de passe désigne l'étudiant ; si deux étudiants ont la même adresse et le même mot de passe, la connexion est refusée (utiliser le matricule). Le compte créé ne copie pas une adresse partagée.

## Quiz et classement

Le **serveur est l'arbitre** : il tire les 10 questions (`POST /api/v1/me/quiz/parties`), juge chaque réponse (`POST …/parties/{id}/reponses`) et calcule seul le score et la durée. Il n'existe aucune route « enregistrer mon score ». La bonne réponse n'est révélée qu'après la réponse du joueur ; une réponse en moins de 0,8 s est refusée ; une partie expire après 30 minutes ; en lancer une nouvelle abandonne la précédente ; au plus 30 parties par heure et par compte. Seules les parties **terminées** comptent.

- **Classement** (`GET /api/v1/me/quiz/classement?period=week|all`) : la meilleure partie de chaque joueur (pourcentage, puis temps le plus court ; ex æquo = même rang). « Cette semaine » repart chaque lundi (heure de Madagascar). Les autres ne voient que « Prénom N. », jamais le matricule ni le nom complet.
- **Confidentialité** : chaque joueur peut ne plus apparaître au classement (`PUT /api/v1/me/quiz/reglages`) ; il garde ses statistiques mais ne prend plus de place dans les rangs.
- **Tables** (ajout seulement) : `t_quiz_question`, `t_quiz_partie`, `t_quiz_partie_question`, `t_quiz_joueur`. Banque de 53 questions : `php artisan quiz:import-questions` (idempotent ; l'état actif/inactif d'une question est conservé).
- Limite connue : les questions de l'ancienne page étaient lisibles dans son code ; ici la bonne réponse ne quitte jamais le serveur avant la réponse, mais rien n'empêche de rejouer en notant les réponses vues. Le classement est un jeu, pas un examen.

## Mes inscriptions et fiche d'inscription (PDF)

`GET /api/v1/me/enrollments` (une fiche par session, groupées par année), `GET …/{session}` (la fiche en JSON, affichée dans l'application) et `GET …/{session}/pdf` (le PDF, fabriqué **sur le serveur** avec dompdf : même rendu partout, plus de dépendance à un CDN, jamais mis en cache).

- **Qui voit quoi** : le matricule vient du compte, jamais de la requête. Une session d'un autre étudiant, inconnue, ou trop ancienne répond la même chose (404), sans rien révéler. Visibilité : Licence à partir de 2026-2027, Master à partir de 2025-2026 (décision du registrariat du 07/10/2026, `EnrollmentRules::SHEETS_FROM`) ; la rubrique « fiches_inscription » peut être masquée par le registrariat (`t_espace_etudiant_config`).
- **Montants** : portés de l'ancienne fiche. Preuve de parité : la VRAIE fiche de l'ancien code a été exécutée sur la base locale pour les 482 inscriptions visibles, et comparée à la nôtre (cours, crédits, laboratoire, sous-total, tranches, total général) : **0 écart**. Règles : seuls les deux premiers cours à laboratoire sont facturés ; frais de graduation seulement au 2ᵉ semestre pour un étudiant diplômant ; en Master les lignes à 0 se taisent et le bloc « scolarité » disparaît s'il est à 0 ; une session sans ligne financière est **nommée** (« ne vaut pas justificatif de paiement »), jamais escamotée.
- **Écarts assumés avec l'ancienne fiche** : (1) le niveau est celui de l'inscription, pas le niveau actuel du dossier (432 inscriptions diffèrent) ; (2) la ligne « Frais costume » s'affiche dès qu'elle est comptée dans le total (avant : comptée sans être montrée, 102 lignes concernées) ; (3) un cours disparu du catalogue s'écrit « — » (avant : « Général ») ; (4) la photo n'est pas reprise pour l'instant.
- **Photo** : la photo de la fiche étudiante (`tbl_2024_etudiant.image_student`, dossier de l'ancienne app, `STUDENT_PHOTOS_PATH`) figure sur la fiche dans l'app et en haut à droite du PDF, réduite (420 × 540 px au plus, JPEG, redressée selon l'EXIF). Le chemin est une donnée saisie : validé (pas de `..`, pas de chemin absolu ni d'antislash), confiné dans le dossier (`realpath`), type lu dans le fichier, taille et pixels plafonnés. Seule MA photo est servie (`GET /api/v1/me/photo`, aucun identifiant en paramètre). Relevé : 915 fiches sur 2 263 ont une photo utilisable (790 noms simples, 125 chemins avec sous-dossier) ; les autres s'impriment avec un cadre « Photo » vide.
- **Décision métier ouverte** : dans le module d'inscription, `cout_costume` et `cout_voyage` sont EXCLUS du total ; dans la fiche imprimée ils sont INCLUS. On suit la fiche imprimée (le document déjà remis aux étudiants). À trancher par le registrariat.

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
