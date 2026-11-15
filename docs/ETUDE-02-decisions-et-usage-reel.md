# Étude 02 — Décisions, usage réel et stack retenue

*14/11/2026. Fait suite à l'[étude 01](ETUDE-01-existant-et-stack.md). Sources : journal d'audit de la production (`t_audit_requete`, du 15/09 au 14/11/2026, agrégats seulement, aucune donnée personnelle extraite), schéma de la base de production, poste de développement.*

## 1. Décisions prises

| # | Décision | Date |
|---|---|---|
| D1 | **Back-end : Laravel** (API JSON `/api/v1`, base MySQL existante partagée) | 14/11/2026 |
| D2 | **Front : Next.js + shadcn/ui + Tailwind + GSAP**, en **SPA / PWA** | 14/11/2026 |
| D3 | **Pas de magasins d'applications** : mobile = PWA installée ; bureau = installeurs | 14/11/2026 |
| D4 | Domaine unique `registrar.zurcher.edu.mg`, migration module par module (l'ancien PHP reste en service) | 14/11/2026 |

## 2. Ce que montrent les données d'usage

Période : 15/09 → 14/11/2026 (2 mois, début de semestre). 36 975 requêtes, 212 utilisateurs distincts.

### 2.1 Le périmètre réel est bien plus petit que le code
- **74 pages distinctes seulement** ont été ouvertes (sur 1 152 fichiers dans `src/`), et **71 points d'entrée POST**.
- Réserve : deux mois ne couvrent pas un cycle universitaire (diplômes, bulletins de fin de session, inscriptions). Le journal ne remonte qu'au 15/09. On **ne supprime rien** : l'ancien PHP reste le filet de sécurité, et on ne porte une page rare que lorsqu'elle est demandée.

### 2.2 Qui utilise quoi (niveaux : 1 superadmin, 2 admin, 3 registraire, 4 comptabilité, 5 média, 6 chef de mention, 7 enseignant, 8 étudiant, 9 Work Education)
| Rôle | Utilisateurs | Requêtes | Part mobile |
|---|---|---|---|
| Étudiants (8) | **179** (84 % des utilisateurs) | 6 046 | **73 %** |
| Registraire (3) | 7 | 12 489 | ~0 % |
| Superadmin (1) | 4 | 9 014 | testeurs/développeurs |
| Chefs de mention (6) | 8 | 4 788 | ~2 % |
| Comptabilité (4) | 5 | 1 877 | 2 % |
| Enseignants (7) | 7 | 464 | 3 % |
| Admin (2), Média (5), Work Ed. (9) | 6 | 1 573 | faible |

→ **Deux publics très différents** : une masse d'étudiants **sur téléphone Android**, et un petit personnel très actif **sur ordinateur Windows**. Le design doit être *mobile d'abord* pour les espaces étudiant/enseignant, et *dense, clavier et tableaux* pour le personnel.

### 2.3 Appareils et navigateurs
- Système : Windows 95 utilisateurs, **Android 137**, macOS 15, Linux 11, **iOS 8**. (Une personne peut avoir plusieurs appareils : la somme dépasse 212.)
- Navigateur : Chrome 78 %, Edge 16 %, Safari 3,6 %, Firefox 1,9 %.
- **Conséquence de D3** : la PWA sur Android/Chrome (le cas dominant) offre l'installation, le push et la synchronisation en arrière-plan. **iOS ne concerne que ~4 % des utilisateurs** : ses limites (§4) sont acceptables.

### 2.4 Charge et écritures
- Pointe : 8 h–11 h et 14 h–16 h (≈ 5 000 requêtes/heure, soit ~1,4/s). Aucune contrainte de performance côté serveur.
- Tables les plus écrites : `t_2023_notes` (5 387), `t_2024_cours_finance` (4 014), `t_2024_inscription_session` (3 170), historique étudiant (3 076), `t_presence_ligne` (2 809), finance étudiant (2 750). **Notes, finance, inscriptions et présence = le cœur sensible**, à migrer avec parité de tests.
- **Exports PDF très présents** (`/src/data.topdf` ≈ 1 800 appels, 2 034 actions « export ») : la génération PDF serveur doit faire partie du socle de l'API, pas arriver en fin de projet.

### 2.5 Pages les plus utilisées (premiers écrans à reconstruire)
`/student` (accueil étudiant), `/tableau-de-bord`, `/student/home`, `/inscription/inscription`, `/etudiants`, `/student/dashboard`, `/cours`, `/finance/authorized-reinscription`, `/student/notes`, `/courses`, `/settings`, `/candidatures`, `/students/create`, `/work-education`, `/messagerie`, `/teacher/*`. La liste complète (45 premiers) est dans [reference/usage-agrege-2026-09-15_2026-11-14.txt](reference/usage-agrege-2026-09-15_2026-11-14.txt).

## 3. Le schéma de production ([reference/schema-prod-2026-11-14.sql](reference/schema-prod-2026-11-14.sql))

- **139 tables + 4 vues**, toutes InnoDB, **seulement 16 clés étrangères**, 2 tables sans clé primaire (`provinces`, `t_account_code_import_20260413`).
- **Jeux de caractères mélangés** : 92 tables en utf8mb4, 29 en utf8mb3, **18 en latin1**. Risque : accents corrompus, jointures entre collations différentes. **On ne modifie pas ces tables en production** ; les modèles Laravel s'y adaptent et on convertit plus tard, table par table, avec sauvegarde.
- **Noms hétérogènes** (`tbl_2024_etudiant`, `t_2023_notes`, `std_inscription`, `users`…) : chaque modèle Eloquent déclare sa table explicitement.
- **Moodle lit la base par des vues** (`v_moodle_cours`, `v_moodle_inscriptions`) : elles sont un contrat externe, à ne jamais casser.
- **Règle de coexistence** : Laravel ne renomme ni ne supprime rien. Les nouvelles migrations ne font qu'**ajouter**. Le schéma de production sert de migration de base.
- **Journal d'audit** : l'ancien code enregistre chaque requête et chaque écriture (`t_audit_requete`, `t_audit_modification`). Laravel doit alimenter les **mêmes tables** (écouteur de requêtes) pour garder un journal unique.

## 4. Conséquences des décisions

### D2 — Next.js en SPA : mode « export statique »
Pour une SPA servie par nginx **sans processus Node** (et compatible avec Tauri), on utilise `output: 'export'` : `next build` produit des fichiers statiques. Cela fixe des règles :
- **Pas** de rendu serveur, de middleware, de routes API ni de composants serveur dynamiques : toutes les données viennent de l'API Laravel, côté client (TanStack Query recommandé).
- **Routes dynamiques** (`/etudiants/[id]`) : en export, chaque valeur doit être connue au build. On utilisera des **paramètres de requête** (`/etudiants/fiche?id=123`) ou un routage client, jamais `generateStaticParams` sur des identifiants.
- Images : `unoptimized`. La vitrine publique (SEO) peut être pré-rendue au build, ou rester sur l'ancien PHP au début.
- Remarque honnête : **shadcn/ui n'exige pas Next.js** ; il fonctionne aussi avec Vite + React. Next.js reste un bon choix (routage par fichiers, mises en page imbriquées, écosystème), à condition d'accepter les limites ci-dessus. Si elles gênent, passer à Vite ne coûterait presque rien en composants.
- **GSAP** (gratuit, y compris pour un usage commercial, depuis 2025 : à reconfirmer sur gsap.com à l'installation) : avec `@gsap/react` (`useGSAP`). Règles : animer seulement `transform` et `opacity` ; respecter `prefers-reduced-motion` ; charger les plugins à la demande ; **tester sur un Android d'entrée de gamme**, c'est l'appareil de la majorité des étudiants. Les animations de sortie entre pages sont délicates avec l'App Router : on utilisera des transitions d'entrée, ou un gestionnaire de transition dédié.

### D3 — Pas de magasins : ce que cela implique
- **Android** : PWA installable (WebAPK), push web, synchronisation en arrière-plan : couvre la grande majorité des étudiants.
- **iOS (≈ 4 %)** : l'app doit être **ajoutée à l'écran d'accueil** pour recevoir le push (iOS 16.4+) ; **pas de Background Sync** (la file hors ligne se vide à l'ouverture de l'app). À documenter dans l'aide aux utilisateurs.
- **Bureau — attention** : « sans magasin » ne supprime pas les contraintes de **signature** :
  - *Windows* : un installeur non signé déclenche l'avertissement SmartScreen (certificat de signature payant, ou service type Azure Trusted Signing).
  - *macOS (15 utilisateurs)* : une app non signée/notarisée est bloquée par Gatekeeper ; la notarisation exige un compte Apple Developer (≈ 99 $/an), même hors App Store.
  - **Alternative sans frais** : **installer la PWA depuis Chrome/Edge** (« Installer l'application ») donne déjà une fenêtre dédiée sur Windows et macOS, sans installeur ni signature. **Tauri n'est utile que pour ce que la PWA ne sait pas faire** (démarrage automatique, impression silencieuse, écran d'affichage de la file d'attente en kiosque…).
- Proposition : **PWA d'abord sur toutes les plateformes ; installeurs Tauri en phase ultérieure**, si un besoin concret le justifie.

### D1 — Laravel
- Version : Laravel 12.x (à confirmer au moment de la création du projet).
- **Fixer PHP 8.3** : le serveur est en 8.3.6, le poste en 8.5.5 → `"config": {"platform": {"php": "8.3.6"}}` dans `composer.json` pour ne pas livrer du code incompatible.
- Authentification : **Sanctum**, cookie pour la PWA (même domaine), jetons personnels pour un éventuel client Tauri. Les empreintes de mots de passe actuelles (table **`compt_utilisateur`**, colonne `level`) sont reprises sans réinitialisation, Google OAuth conservé : voir l'[étude 05](ETUDE-05-api-et-authentification.md) (11 comptes à l'ancien format sha256 à convertir). *Correction : cette étude disait d'abord « table `users` ».*
- Un **pool PHP-FPM dédié** à l'API (comme `funding` et `moodle` ont le leur) pour isoler la charge de Moodle.

### Routage nginx pendant la coexistence
```
/api/v1/*   → Laravel (nouveau, PHP-FPM dédié)
/app/*      → fichiers statiques Next.js (nouveau, basePath '/app')
tout le reste → ancien PHP (inchangé, même base de données)
```
À la fin de la migration, l'application prend la racine `/` et l'ancien PHP disparaît. Les pages pas encore portées s'ouvrent depuis le nouveau menu en navigation complète (pas d'iframe : sessions et CSRF différents) ; le pont de session fait l'objet de l'étude 03.

## 5. Poste de développement (vérifié)
Node 24.14, npm 11.9, PHP 8.5.5, Composer 2.9, Git 2.53, **Rust 1.98 + Cargo** (prêt pour Tauri), Laragon (MySQL, nginx, Redis, Mailpit). Absent : pnpm (à installer, recommandé pour le front). Versions courantes au 14/11/2026 : Next.js 16.4.6, GSAP 3.15.1, CLI shadcn 4.23.0.

## 6. Limites de cette étude
- Les **logs nginx** (`/var/log/nginx`) sont réservés à root et au groupe `adm` ; le compte SSH n'y a pas accès. Le journal d'audit de l'application a suffi, mais ne couvre que les utilisateurs connectés (et seulement depuis le 15/09). Pour des chiffres complets (pages publiques, bots, parcours d'inscription des candidats), il faudrait ajouter le compte au groupe `adm` — votre décision.
- Le nombre de **comptes étudiants au total** n'est pas encore relevé (seuls les actifs sur 2 mois sont comptés).

## 7. Prochaines études
- **03 — Contrat d'API et authentification** : conventions `/api/v1`, pont de connexion avec l'ancien PHP, Google OAuth, rôles → policies Laravel, génération PDF.
- **04 — Direction visuelle et design system** : thème, jetons, navigation mobile et bureau, grammaire d'animations GSAP, maquettes des 5 premiers écrans.
- **05 — Modèle hors ligne** : que synchroniser (présence, notes), file d'attente, conflits, comportement iOS.
- **Prototype vertical** : l'accueil étudiant, de bout en bout, sur Android, Windows et iOS.
