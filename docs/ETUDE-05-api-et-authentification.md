# Étude 05 — API `/api/v1`, authentification et hors ligne

*14/11/2026. Fondée sur la lecture du code (`data/middleware.php`, `src/api/google-auth.php`, `controllers/AdmissionController.php`, `app/.student/addStd.php`, `src/js/hors-ligne.js`, `sw.js`) et sur des agrégats de la production (aucun mot de passe ni hash lu).*

## 1. Ce que fait l'authentification aujourd'hui

| Sujet | Constat dans le code |
|---|---|
| Table des comptes | **`compt_utilisateur`** (et non `users`, erreur de l'étude 02, corrigée). 223 comptes, 217 actifs (`etat = 1`). Colonnes : `pseudo`, `mail`, `password`, `level` (1 à 9), `privilege`, `user_type` (enum de 9 valeurs), `student_id`, `teacher_uid`, `perimetre_cycle`, `perimetre_mention`. |
| Mots de passe | **bcrypt** (`password_verify`) pour 206 comptes actifs. **11 comptes actifs** ont encore l'**ancien format** `sha256(motdepasse + 'fixing_password')` : vérifié à la connexion puis converti en bcrypt (1 étudiant, 10 membres du personnel). |
| Identifiant | `pseudo`, sinon **adresse e-mail** si elle désigne **un seul** compte actif (adresse partagée : refusée, événement `login_email_ambigu`). Aucune adresse partagée aujourd'hui. |
| Google | Le client envoie un jeton ID ; le serveur le fait vérifier **par un appel HTTP à Google (`tokeninfo`, délai 10 s)** à chaque connexion. Règles : e-mail vérifié, domaine `@zurcher.edu.mg`, audience = `GOOGLE_CLIENT_ID`. Recherche du compte par e-mail → repli par la fiche enseignant (compte créé à la première connexion) → repli par la table des étudiants. |
| Session | Session PHP de **10 h**, cookie `HttpOnly`, `SameSite=Lax`, `Secure`, régénération de l'identifiant toutes les 30 min et à la connexion. Jeton CSRF par session. « Se souvenir de moi » : table `remember_tokens` (9 lignes). |
| Double espace | Une personne du personnel aussi enseignante a **deux comptes reliés par `teacher_uid`** ; la bascule d'espace ne redemande pas le mot de passe et ne vaut que pour ces deux comptes. |
| Journal | `security_logs` (6 051 lignes), `login_locations` (3 540 lignes). La page de connexion (`src/index.php`) **demande la position GPS** à chaque connexion. |
| Refus Google | Deux motifs seulement sont distingués (enseignant sans compte / adresse inconnue) ; le **motif réel n'est pas journalisé** dans l'audit des connexions. |

## 2. Authentification dans Laravel

1. **Modèle `User` sur `compt_utilisateur`** (table, clé et colonnes déclarées explicitement). Aucune colonne renommée.
2. **Mots de passe** : le hacheur bcrypt de Laravel lit les hashs existants (`$2y$`). Pour les 11 comptes hérités, un vérificateur dédié reproduit `sha256(mot de passe + sel)`, **convertit en bcrypt** à la première connexion réussie, comme aujourd'hui. Proposition : envoyer à ces 11 personnes un lien de réinitialisation, puis **supprimer ce chemin de code**.
3. **Identifiant** : mêmes règles (pseudo, puis e-mail non ambigu). Limitation de débit par couple identifiant + IP, avec verrouillage progressif.
4. **Google** : vérifier le jeton **localement** (signature contre les clés publiques de Google, mises en cache) au lieu d'un appel réseau par connexion : on retire une dépendance externe et environ 200 ms (`/api/google-auth` : 256 ms en moyenne, 2 139 appels). Les règles métier (domaine, repli enseignant, création de compte étudiant) sont portées dans un `GoogleLoginService` testé.
5. **Motif de refus journalisé** pour chaque échec (mot de passe, compte inconnu, e-mail ambigu, domaine refusé, jeton invalide, compte suspendu) dans `security_logs`, avec un code stable et **sans mot de passe**. C'est l'amélioration A2 : on pourra enfin expliquer les 19,9 % d'échecs.
6. **Session Sanctum « SPA »** : cookie de session `HttpOnly` + `Secure` + `SameSite=Lax` et jeton CSRF en cookie `XSRF-TOKEN` renvoyé en en-tête. Même domaine, donc pas de CORS. Durée **10 h** comme aujourd'hui ; « se souvenir de moi » : 30 jours (à valider, §7). Un client Tauri éventuel utiliserait des jetons personnels (même domaine d'API, autre transport).
7. **Rôles** : `user_type` + `level` → **Policies** Laravel et une matrice de tests rôle × action. La règle « un étudiant ne voit que ses données » (`studentCanAccessOwnData`) et le périmètre d'un chef de mention (cycle, mention) sont portés comme politiques, pas comme `if` dans les contrôleurs.
8. **Double espace** : `POST /api/v1/auth/switch-space` n'accepte que les deux comptes reliés par `teacher_uid`, sans mot de passe, et journalise la bascule.

### Pont avec l'ancien PHP (pendant la coexistence)
Deux systèmes, deux cookies de session (`PHPSESSID`, `laravel_session`) : une page ancienne ne voit pas la session du nouveau. Solution proposée : **billet à usage unique**.
- L'identité de référence est celle du nouveau système. Pour ouvrir une page ancienne, le front appelle `POST /api/v1/legacy/ticket` ; le serveur renvoie une URL `/legacy-login?t=…` portant un billet **signé (HMAC), valable 60 secondes, à usage unique**.
- Un petit fichier **ajouté** à l'ancien code (`legacy-login.php`, aucune modification de l'existant) vérifie le billet et ouvre la session ancienne via `Middleware::loginUser()`.
- Sens inverse : tant que l'ancienne page de connexion existe, elle redirige vers `/app` après connexion avec le même type de billet.
- À écrire et tester d'abord : c'est le point le plus sensible de la coexistence. À supprimer en fin de migration.

## 3. Conventions de l'API

| Sujet | Règle |
|---|---|
| Base | `/api/v1`, JSON UTF-8, noms de champs en `snake_case` anglais, libellés d'erreur en français |
| Erreurs | Format **RFC 9457** (`application/problem+json`) : `type`, `title`, `status`, `detail`, `code` stable (`grades.out_of_range`), `errors` par champ |
| Dates | ISO 8601 **avec décalage** (`2026-11-14T08:00:00+03:00`) : le serveur est à l'heure de Madagascar (`data/fuseau.php`) |
| Concurrence | `ETag` / `If-Match` sur les ressources modifiables (feuille de présence, remise de notes) : modification obsolète → **409** avec la version du serveur |
| Idempotence | En-tête `Idempotency-Key` (UUID) obligatoire sur toute écriture rejouable par la file hors ligne : un rejeu renvoie la réponse d'origine, sans doublon |
| Liste | Pagination par curseur ; `?filter[type]=notes&sort=-received_at` ; jamais plus de 100 éléments |
| Longs traitements | **202 Accepted** + ressource d'état (`status: pending / done / failed`), exécutés par la file de tâches |
| Documentation | OpenAPI générée par le code ; client TypeScript généré pour Next.js |
| Limitation | 60 req/min par compte, 10 tentatives de connexion / 15 min par identifiant + IP |

## 4. Premiers points d'entrée (selon l'usage réel)

| Module | Points d'entrée |
|---|---|
| Authentification | `POST /auth/login` · `POST /auth/google` · `POST /auth/logout` · `GET /auth/me` · `POST /auth/switch-space` · `POST /legacy/ticket` |
| Étudiant | `GET /me/home` (agrégat : à faire maintenant, prochain cours, nouveautés, suivi) · `GET /me/grades` · `GET /me/attendance` · `GET /me/registrations` |
| Enseignant | `GET /me/courses` · `GET/PUT /courses/{id}/attendance-sheets/{date}` · `GET/PUT /courses/{id}/grade-submission` · `POST …/submit` |
| Registrariat | `GET /review-queue?filter[type]=` · `GET /review-queue/{id}` · `POST /review-queue/{id}/approve` · `POST /review-queue/{id}/return` (motif obligatoire) · `POST /review-queue/bulk-approve` · `POST /review-queue/{id}/undo` |
| Admissions | `POST /applications/{id}/status` → **202** (notification e-mail/SMS en file) |
| Étudiants | `POST /students` → **201** + `google_account.status` (`pending`/`created`/`failed`) |

### « Annuler » doit être vrai côté serveur
Dans la maquette, l'annulation est locale. Or valider une remise de notes l'inscrit dans l'historique et le bulletin : on ne peut pas se contenter de masquer l'interface. Conception : `approve` place la demande en **« validation en cours, annulable jusqu'à T + 10 s »** ; une tâche différée de la file de Laravel la valide réellement au bout de ce délai, sauf `undo` reçu avant. Aucune écriture définitive dans les notes ni le bulletin pendant la fenêtre d'annulation. Si l'onglet se ferme, la validation aboutit quand même (elle est côté serveur).

## 5. Les deux pages lentes : cause confirmée

| Page | Cause dans le code | Correction |
|---|---|---|
| `POST /candidatures/statut` (1,35 s en moyenne, max 6,2 s) | Après le `UPDATE`, la requête **envoie l'e-mail** (`admissionNotifierCandidat`) **puis le SMS Twilio** (`admissionNotifierCandidatSms`) avant de répondre. Le commentaire du code parle d'envoi « au mieux », mais il est bien synchrone. | Répondre **202** après l'`UPDATE`, envoyer e-mail et SMS par deux tâches de la file (nouvelles tentatives, état `notifie_le` / `notifie_sms_le` conservé) |
| `POST addStd` (1,4 s en moyenne, max 8,9 s) | Appelle l'**API Google Admin** (`googleAdminCreerEtudiant`) pendant la requête pour créer l'adresse de l'étudiant. | Créer l'étudiant (**201**), puis créer le compte Google par une tâche ; état visible (`pending` → `created` ou `failed` avec bouton « Réessayer »), repli déjà prévu par le code (`/settings/adresses-google`) |

## 6. Hors ligne : on garde le modèle actuel, on le fiabilise
`hors-ligne.js` et `sw.js` décident déjà l'essentiel, et c'est bien conçu : **file locale (IndexedDB) propre au compte**, envoi dans l'ordre avec un jeton frais, **le serveur fait foi** (409 → « Appliquer quand même » ou « Ignorer »), session expirée → la file attend, **purge à la déconnexion** (avec avertissement s'il reste des changements), copies de pages gardées 14 jours et jamais montrées à un autre compte. On reprend ces règles telles quelles dans le front Next.js et on ajoute :
- une **clé d'idempotence** par changement (aucun doublon après un rejeu) ;
- `If-Match` pour détecter une modification obsolète sans écraser ;
- sur **Android/Chrome**, la synchronisation en arrière-plan ; sur **iOS**, envoi à l'ouverture, au retour sur l'onglet et toutes les 30 s (comme aujourd'hui) ;
- périmètre limité aux modules où le terrain l'exige : **présence** et **remise de notes** des enseignants (lecture seule hors ligne pour les notes et l'emploi du temps des étudiants).

## 7. À décider
1. **Position GPS à la connexion** : la page de connexion la demande à chaque fois (3 540 positions enregistrées). C'est intrusif pour des étudiants, et une donnée personnelle sensible. Garder, la limiter au personnel, ou la remplacer par la ville déduite de l'adresse IP (que le code sait déjà faire) ?
2. **« Se souvenir de moi »** : 30 jours pour tous, ou plus court pour les rôles à droits étendus (niveaux 1 à 4) ?
3. **Les 11 comptes à ancien mot de passe** : lien de réinitialisation envoyé, puis suppression du code hérité : d'accord ?
4. **Billet de pont** : j'ajoute un fichier `legacy-login.php` à l'ancien dépôt (aucun fichier existant modifié). D'accord ?
5. **Documents officiels** (fiche d'inscription, bulletin, attestation) : le seul vrai PDF d'aujourd'hui est la fiche d'inscription (dompdf). Les « exports PDF » (`/src/data.topdf`, ~1 950 appels) sont des **pages HTML à imprimer**. Proposition : documents officiels = PDF généré par le serveur (rendu identique pour tous) ; listes = XLSX/CSV + feuille de style d'impression.

## 8. Limites
- Je n'ai pas lu le code de création de compte étudiant à la première connexion Google (lignes 1716+) ni `findTeacherUserByEmail` en détail : leur port demandera une lecture ligne à ligne et des tests avec de vrais cas.
- Les 11 comptes à ancien mot de passe sont comptés sans lire aucun hash ; je n'ai pas vérifié depuis quand ils ne se sont pas connectés.
- Le délai d'annulation de 10 s est une proposition, pas une règle métier validée par le registraire.
