# Étude 01 — L'existant et le choix de la stack

*Registrar UAZ cross-plateforme — 14/11/2026 — rédigée à partir du code (`C:\laragon\www\registrar`, 479 commits) et du serveur de production (lecture seule, par SSH).*

## 1. Ce qui existe aujourd'hui

### Application
| Élément | Constat |
|---|---|
| Architecture | PHP MVC **rendu côté serveur** : `index.php` → `Router` → 18 contrôleurs → vues PHP (HTML + logique mélangés). ~66 routes déclarées, mais **1 152 pages** dans `src/` accessibles aussi directement (compatibilité arrière via nginx). |
| Volume | ~124 000 lignes de PHP métier (hors dompdf, symfony, google, react… ~380 000 lignes tierces embarquées), ~37 000 lignes de JS, ~23 000 de CSS. |
| Plus gros fichiers | `src/bulk-email.php` (4 200), `data/cursus.php` (4 066), `data/middleware.php` (2 575), `src/accueil.emploi-temps.php` (2 526), `data/messagerie.php` (1 961). Logique métier lourde, souvent dans des vues. |
| API JSON | Quasi absente : ~12 points d'entrée (`app/*.api.php`, `src/api/*`, `queue.api.php`, `ApiController`). **Le gros du travail est de créer une vraie API.** |
| Authentification | Sessions PHP + jeton CSRF + cookie « se souvenir de moi » + Google OAuth (comptes @zurcher.edu.mg). Niveaux de rôle : superadmin, admin, registraire, comptabilité, média, chef de mention, Work Education, enseignant (7), étudiant, plus un « choisir mon espace » pour le personnel aussi enseignant. |
| Domaines fonctionnels | Étudiants et inscriptions/réinscriptions, cours et sessions, notes (remise par les enseignants, validation, historique, bulletins), présence/assiduité, finance (barème, frais, échéances, soldes), admissions (candidatures, SMS/e-mail), emploi du temps, messagerie instantanée + push, évaluations/sondages, file d'attente (queue + affichage), Work Education, Studio UAZ (création de visuels), outils (QR, fusion PDF, retouche photo), newsletter, e-mail en masse, journal d'audit, vitrine publique. |
| Intégrations | Google (OAuth, Admin SDK, Gmail), Twilio (SMS), IMAP, Moodle (synchro), dompdf/PDF/XLSX, Web Push (VAPID écrit à la main), WebSocket Ratchet (`src/websocket-server.php`). |
| Tests | 68 fichiers dans `tests/` (PHP + scripts Node dans Chrome pour le Studio) : un filet de sécurité précieux pour la migration. |

### Ce qui est déjà « cross-plateforme » (partiellement)
- **PWA** : `sw.js` (offline-first pour la présence et la remise des notes, copies par compte, purge à la déconnexion), `manifest.webmanifest`, file d'attente locale (`src/js/hors-ligne.js`), push de messagerie. *Démarré aujourd'hui même (demande du 14/11/2026).* Ces **idées** (file locale, cache par compte) sont à reprendre ; le code lui-même est lié aux pages PHP.
- **Desktop Windows** : Electron qui lance un `php -S` + PHP portable de ~96 Mo embarqué, base MySQL « à externaliser ». Solution de dépannage : lourde, une base par poste, pas de macOS/mobile.

### Données
- **MySQL 8.0.46**, **143 tables, 138 Mo** (plus grosses : notes 67 k lignes, inscriptions 50 k, audit 35 k). Très petit : aucune contrainte de performance.
- **1,2 Go de photos d'étudiants** (`app/photosetudiants`) : à servir via CDN/cache et miniatures.
- ⚠ Seulement 72 `CREATE TABLE` dans le dépôt pour 143 tables en production : le schéma a dérivé des fichiers de migration. **Il faut un `mysqldump --no-data` de la production comme référence.**

### Serveur de production (SSH `registrar-vps`)
- VPS Ubuntu 24.04, 4 vCPU, 15 Go de RAM, 193 Go de disque (37 % utilisé), uptime 24 jours.
- nginx 1.24 + PHP 8.3-fpm + MySQL 8.0.46 ; `registrar` est dans `/var/www/registrar` (**pas de git sur le serveur** : déploiement manuel).
- Il héberge aussi Moodle, WordPress, bibliothèque, funding, inventory, `sa`, un site **Laravel** (`uaz-laravel` + worker de queue) et un site **Next.js** (`uaz-site`, Node 22 dans `/opt/node22`, derrière nginx sur le port 3100, géré par systemd).
- Conséquence : **Node et Laravel sont déjà exploités sur ce serveur**, et nginx peut router par chemin. Aucun changement d'hébergement nécessaire. Mais la machine est partagée : les limites de ressources comptent (Moodle y tourne).

## 2. Contraintes qui guident le choix

1. Domaine unique `registrar.zurcher.edu.mg`, et l'ancienne version doit rester en service pendant la migration.
2. Cinq cibles : web, Android, iOS, Windows, macOS.
3. L'application est une **application de gestion dense** (tableaux, formulaires, PDF, exports) pour le personnel, mais aussi **mobile d'abord** pour étudiants et enseignants (notes, présence, messagerie, file d'attente).
4. 124 k lignes de logique PHP à ne pas réécrire d'un coup : le risque est la régression sur notes, finance et inscriptions.
5. Hors ligne utile (enseignants en salle, connexion malgache variable).

## 3. Options de stack

| | A. Web moderne + Capacitor + Tauri | B. Flutter partout | C. React Native/Expo (+ web) | D. Garder PHP, moderniser l'habillage |
|---|---|---|---|---|
| Une seule base de code | ✅ web, mobile, bureau | ✅ | ⚠ web et mobile ; bureau immature | ❌ |
| Web (tableaux denses, PDF, impression, SEO vitrine) | ✅ natif, DOM | ⚠ rendu canvas, lourd, accessibilité et impression médiocres | ⚠ react-native-web acceptable | ✅ |
| Réutilise l'existant (Tailwind, JS, PWA, logique) | ✅ Tailwind déjà là | ❌ tout réécrire en Dart | ⚠ | ✅ |
| Apps iOS/Android en magasin | ✅ Capacitor (WebView + plugins natifs : push, caméra, biométrie, stockage) | ✅ | ✅ | ⚠ simple coque d'un site distant : risque de refus App Store |
| Bureau Windows/macOS | ✅ Tauri 2 (~10 Mo, remplace Electron + PHP de 96 Mo) | ✅ | ⚠ | ❌ |
| Hors ligne | ✅ SQLite/IndexedDB + file de synchronisation | ✅ | ✅ | ⚠ |
| Compétences | TypeScript (courant, recrutable) | Dart (niche) | TypeScript | PHP (déjà là) |
| Effort total | Élevé mais progressif | Très élevé | Élevé | Faible, mais n'atteint pas « vraiment modernisé » |

### Recommandation : option A

**Front unique** TypeScript + **Vue 3 ou React** + Vite + Tailwind (déjà utilisé) + une bibliothèque de composants (shadcn-vue / shadcn/ui), livré comme **PWA** sur `registrar.zurcher.edu.mg`, puis emballé :
- **Capacitor** → Android et iOS (notifications push natives, caméra pour photos/QR, empreinte, stockage hors ligne) ;
- **Tauri 2** → Windows et macOS (installeur léger, mises à jour automatiques) ;
- le même code sert le web.

**Back : une API JSON versionnée `/api/v1`** devant la base MySQL existante.
- *Option A1 (recommandée) : Laravel 12* (PHP 8.3 déjà sur le serveur, `uaz-laravel` prouve l'exploitation) : Sanctum (jetons pour apps + cookies pour web), policies = niveaux de rôle actuels, queues pour e-mail/SMS/push, Eloquent sur les tables existantes. On **extrait** la logique des `data/*.php` vers des services testés au lieu de la réécrire en TypeScript.
- *Option A2 : Node (NestJS/Fastify)* : un seul langage partout, mais il faut tout porter 124 k lignes de PHP en TypeScript, sans aucun gain fonctionnel.

**Stratégie de migration « étrangleur » (module par module)** :
nginx envoie `/app/*` (nouveau front) et `/api/v1/*` (nouvelle API) vers le nouveau système ; **tout le reste continue vers l'ancien PHP**, avec la même base. Connexion unique (le nouveau front peut ouvrir l'ancien module dans un cadre tant qu'il n'est pas porté). Pas de big-bang, pas de gel de l'existant, retour arrière immédiat.

## 4. Ordre de migration proposé (à valider)

0. **Socle** : design system (jetons de couleurs, composants, thème sombre), auth + rôles, API de base, pipeline de build/déploiement, schéma de référence.
1. **Enseignants** (déjà partiellement hors ligne) : présence, remise des notes, mes cours. C'est le cas d'usage le plus mobile et le plus utile hors ligne.
2. **Étudiants** : accueil, notes, présence, fiches d'inscription, actualités, messagerie + push.
3. **Messagerie, notifications, file d'attente** (temps réel : WebSocket/SSE).
4. **Personnel** : tableau de bord, étudiants, cours, sessions, admissions.
5. **Finance** et **inscriptions/réinscriptions** (les plus sensibles : en dernier, avec parité de tests).
6. **Outils** (Studio, QR, retouche photo, bulk e-mail) et vitrine publique (SSR/statique pour le SEO).

## 5. Risques identifiés

- **Schéma dérivé** (143 tables, 72 migrations) : relever d'abord le schéma réel.
- **Logique dans les vues** : chaque écran demande d'abord d'extraire la règle métier, puis de la tester contre l'ancien comportement.
- **Génération PDF** (dompdf, ~300 fichiers) : à garder côté serveur au début.
- **Magasins d'applications** : compte Apple Developer (≈ 99 $/an) et Google Play (25 $ une fois) ; exigence de fonctionnalités réelles (pas un simple site encapsulé) et politique de suppression de compte.
- **Serveur partagé** avec Moodle : surveiller CPU/RAM, isoler en systemd/PHP-FPM pool dédié.
- **Pas de git ni de déploiement reproductible** en production aujourd'hui : à mettre en place avant la première livraison.
- **Notifications push iOS** : exigent l'app installée (PWA ajoutée à l'écran d'accueil ou app Capacitor + APNs).

## 6. Prochaines études

1. Relevé du schéma réel + carte des rôles/permissions par page.
2. Inventaire des 1 152 pages : utilisées ou mortes (journal d'accès nginx sur 90 jours).
3. Maquettes du design system (direction visuelle, thème, navigation mobile/bureau).
4. Spécification de l'API `v1` et du modèle hors ligne (quoi synchroniser, résolution des conflits).
5. Prototype vertical : une page enseignante de bout en bout sur les 5 cibles.

> **Correction du 14/11/2026.** Cette étude disait « MariaDB 10.11 » pour la base de production, d'après la version du *client* en ligne de commande du serveur. Le *serveur* de base de données est en réalité **MySQL 8.0.46** (vérifié par `SELECT VERSION()` et par l'en-tête de la sauvegarde du 12/11/2026). Conséquence : le schéma s'importe sans adaptation dans MySQL 8.4 (Laragon), et Laravel utilise simplement son pilote `mysql`.
