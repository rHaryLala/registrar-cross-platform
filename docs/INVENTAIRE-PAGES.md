# Inventaire des pages de l'ancienne application

*Généré le 14/11/2026 à partir de `routes.php` (127 routes déclarées), du menu par rôle (`init/menubar.php`) et du journal d'audit de production (15/09 → 14/11/2026). Aucune donnée personnelle : comptages seulement.*

**Priorité** : P1 = au moins 20 utilisateurs ou 500 appels en deux mois ; P2 = ouverte au moins une fois ; P3 = jamais ouverte (reste servie par l'ancien PHP tant que personne ne la demande). **Rappel** : 1 152 fichiers de pages existent dans `src/` ; seules ~74 adresses ont été ouvertes.

## Synthèse

| Module | Routes | P1 | P2 | P3 | Fait |
|---|---|---|---|---|---|
| Étudiant | 13 | 10 | 1 | 2 | 3 |
| Registrariat et administration | 33 | 3 | 25 | 5 | 0 |
| Authentification | 5 | 2 | 1 | 2 | 2 |
| Exports et impressions | 15 | 2 | 2 | 11 | 0 |
| API, services et application installable | 11 | 1 | 3 | 7 | 0 |
| Messagerie | 1 | 1 | 0 | 0 | 0 |
| Finance | 7 | 1 | 2 | 4 | 0 |
| Work Education | 1 | 1 | 0 | 0 | 0 |
| File d'attente | 4 | 1 | 3 | 0 | 0 |
| Public (vitrine, admissions, newsletter) | 9 | 0 | 2 | 7 | 1 |
| Admissions (personnel) | 3 | 0 | 3 | 0 | 0 |
| Outils (Studio UAZ, QR, retouche, fusion PDF) | 4 | 0 | 4 | 0 | 0 |
| Évaluations et sondages | 13 | 0 | 1 | 12 | 0 |
| Enseignant | 3 | 0 | 3 | 0 | 0 |
| Inscriptions | 2 | 0 | 2 | 0 | 0 |
| Divers | 3 | 0 | 0 | 3 | 0 |

Total : 127 routes (hors redirections) : P1 = 22, P2 = 52, P3 = 53.


## Étudiant

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/student` | P1 — très utilisée | 5373 | 24 | superadmin, admin, registraire, compta, média, chef mention | StudentController@show | À faire |
| `/student/home` | P1 — très utilisée | 1686 | 191 | superadmin, admin, registraire, chef mention, étudiant | StudentController@home | Fait : accueil étudiant (tranche 1) |
| `/student/notes` — mes notes | P1 — très utilisée | 517 | 127 | étudiant | StudentController@dashboard | Fait : Mes notes (tranche 2), parité vérifiée sur 180 étudiants |
| `/students/create` | P2 — utilisée | 234 | 8 | superadmin, admin, registraire | StudentController@create | À faire |
| `/student/info` | P1 — très utilisée | 181 | 98 | étudiant | StudentController@info | À faire |
| `/student/inscriptions` — mes fiches d'inscription, une par session | P1 — très utilisée | 112 | 40 | étudiant | StudentController@inscriptions | À faire |
| `/student/presence` — mon assiduite (§472 a §476) | P1 — très utilisée | 93 | 53 | étudiant | StudentController@presence | Fait : Mon assiduité (tranche 3), parité vérifiée : 94 registres, 0 écart |
| `/student/news` | P1 — très utilisée | 89 | 51 | étudiant | StudentController@news | À faire |
| `/student/quiz` | P1 — très utilisée | 64 | 50 | étudiant | StudentController@quiz | À faire |
| `/student/fiche-inscription` — la fiche d'une session, en PDF | P1 — très utilisée | 53 | 25 | étudiant | StudentController@ficheInscription | À faire |
| `/student/game` | P1 — très utilisée | 40 | 30 | étudiant | StudentController@game | À faire |
| `/student/transition` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | StudentController@transition | À faire |
| `/students/adresse-libre` — adresse universitaire libre pour un nouvel etudiant | P3 — jamais ouverte (2 mois) | 0 | 0 | — | StudentController@adresseLibre | À faire |

## Registrariat et administration

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/tableau-de-bord` — accueil du personnel : a traiter, chiffres cles | P1 — très utilisée | 1988 | 25 | superadmin, admin, registraire, compta, média, chef mention | DashboardController@tableauDeBord | À faire |
| `/etudiants` — liste des etudiants | P1 — très utilisée | 1017 | 23 | superadmin, admin, registraire, compta, média, chef mention | DashboardController@index | À faire |
| `/cours` — ====================================================================== | P1 — très utilisée | 894 | 9 | superadmin, registraire, chef mention | AdminController@course | À faire |
| `/courses` | P2 — utilisée | 409 | 10 | superadmin, registraire, chef mention | DashboardController@courses | À faire |
| `/settings` | P2 — utilisée | 340 | 9 | superadmin, registraire, compta | AdminController@settings | À faire |
| `/session-rankings` | P2 — utilisée | 144 | 10 | superadmin, admin, registraire, compta, chef mention | AdminController@sessionRankings | À faire |
| `/settings/logs` — journal d'activite (superadmin) | P2 — utilisée | 141 | 1 | superadmin | AdminController@logsSettings | À faire |
| `/courses-sessions` | P2 — utilisée | 101 | 6 | superadmin, registraire | AdminController@manageCoursesSessions | À faire |
| `/professors` | P2 — utilisée | 69 | 8 | superadmin, registraire, chef mention | DashboardController@professors | À faire |
| `/deans-list` | P2 — utilisée | 63 | 13 | superadmin, admin, registraire, compta, média, chef mention | AdminController@deansList | À faire |
| `/schedule` | P2 — utilisée | 58 | 15 | superadmin, admin, registraire, compta, média, chef mention | DashboardController@schedule | À faire |
| `/professor` | P2 — utilisée | 58 | 7 | superadmin, registraire, chef mention | AdminController@professor | À faire |
| `/settings/bulk-email` | P2 — utilisée | 53 | 8 | superadmin, admin, chef mention, enseignant | AdminController@bulkEmail | À faire |
| `/top-students` | P2 — utilisée | 50 | 12 | superadmin, admin, registraire, compta, média, chef mention | AdminController@topStudents | À faire |
| `/reglement` | P2 — utilisée | 47 | 13 | superadmin, admin, registraire, compta, média, chef mention, enseignant | AdminController@reglement | À faire |
| `/accounts/create` | P2 — utilisée | 45 | 1 | superadmin | AdminController@createAccount | À faire |
| `/settings/adresses-google` — adresses de la base face aux comptes Google | P2 — utilisée | 35 | 2 | superadmin | AdminController@adressesGoogle | À faire |
| `/my-account` | P2 — utilisée | 33 | 15 | superadmin, admin, registraire, compta, média, chef mention, enseignant, work ed. | AdminController@myAccount | À faire |
| `/remise-notes` — Assiduite : depassements des 15 % et retards des enseignants (§474, §4 | P2 — utilisée | 27 | 6 | superadmin, registraire | AdminController@remiseNotes | À faire |
| `/presence` — ====================================================================== | P2 — utilisée | 21 | 5 | superadmin, registraire | AdminController@presence | À faire |
| `/settings/mentions` — filieres de l'universite | P2 — utilisée | 14 | 1 | superadmin | AdminController@mentionsSettings | À faire |
| `/didacticiel` — visites guidees (data/didacticiel.php) | P2 — utilisée | 10 | 5 | superadmin, admin, registraire, chef mention | DashboardController@didacticiel | À faire |
| `/news` | P2 — utilisée | 10 | 1 | superadmin | AdminController@news | À faire |
| `/settings/espace-etudiant` — publication des notes aux etudiants | P2 — utilisée | 9 | 1 | superadmin | AdminController@espaceEtudiantSettings | À faire |
| `/settings/diplome` | P2 — utilisée | 4 | 2 | superadmin, registraire | AdminController@diplomeSettings | À faire |
| `/sessions` | P2 — utilisée | 3 | 1 | superadmin | AdminController@sessions | À faire |
| `/login-locations` | P2 — utilisée | 2 | 2 | superadmin, registraire | AdminController@loginLocations | À faire |
| `/settings/vitrine` — contenu de la page publique | P2 — utilisée | 1 | 1 | superadmin | AdminController@vitrineSettings | À faire |
| `/classrooms` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | DashboardController@classrooms | À faire |
| `/course` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | AdminController@course | À faire |
| `/settings/qr-generator` — ancienne adresse, redirige vers /qr-generator | P3 — jamais ouverte (2 mois) | 0 | 0 | — | AdminController@qrGenerator | À faire |
| `/reglement/telecharger` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | AdminController@reglementDownload | À faire |
| `/bulletin-academique` — alias explicite | P3 — jamais ouverte (2 mois) | 0 | 0 | — | AdminController@reglement | À faire |

## Authentification

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/login` | P1 — très utilisée | 1855 | 160 | superadmin, admin, registraire, compta, média, chef mention, enseignant, étudiant, work ed. | AuthController@login | Fait (tranche 1) |
| `/logout` | P1 — très utilisée | 161 | 75 | superadmin, admin, registraire, chef mention, enseignant, étudiant, work ed. | AuthController@logout | Fait (tranche 1) |
| `/choisir-espace` — superadmin, media... aussi enseignant : choisir son espace | P2 — utilisée | 44 | 5 | superadmin, admin, média, enseignant | AuthController@choisirEspace | À faire |
| `/loading` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | AuthController@loading | À faire |
| `/goodbye` — ====================================================================== | P3 — jamais ouverte (2 mois) | 0 | 0 | — | AuthController@goodbye | À faire |

## Exports et impressions

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/src/data.topdf` | P1 — très utilisée | 1539 | 12 | superadmin, admin, registraire, compta, chef mention | ExportController@pdf | À faire |
| `/src/data.topdf.php` | P1 — très utilisée | 646 | 4 | superadmin, registraire | ExportController@pdf | À faire |
| `/export/students/xlsx` — Legacy export aliases (backward compatibility) | P2 — utilisée | 32 | 5 | superadmin, registraire | ExportController@studentsXlsx | À faire |
| `/src/data.topdf_paysage` | P2 — utilisée | 2 | 2 | superadmin | ExportController@pdfLandscape | À faire |
| `/export` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@index | À faire |
| `/export/pdf` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@pdf | À faire |
| `/export/pdf-landscape` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@pdfLandscape | À faire |
| `/export/xlsx` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@xlsx | À faire |
| `/data.topdf.php` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@pdf | À faire |
| `/data.topdf` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@pdf | À faire |
| `/data.topdf_paysage.php` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@pdfLandscape | À faire |
| `/data.topdf_paysage` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@pdfLandscape | À faire |
| `/src/data.topdf_paysage.php` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@pdfLandscape | À faire |
| `/export/gen-pdf` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@genPdf | À faire |
| `/sheet` — ====================================================================== | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ExportController@sheet | À faire |

## API, services et application installable

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/api/google-auth` | P1 — très utilisée | 2141 | 204 | superadmin, admin, registraire, compta, média, chef mention, enseignant, étudiant, work ed. | ApiController@googleAuth | À faire |
| `/api/update-dossier` — ====================================================================== | P2 — utilisée | 479 | 6 | superadmin, registraire | ApiController@updateDossier | À faire |
| `/api/update-authorization` | P2 — utilisée | 474 | 5 | superadmin, compta | ApiController@updateAuthorization | À faire |
| `/espace` — ouverture de l'application installee : l'espace de chacun | P2 — utilisée | 45 | 15 | superadmin, étudiant | ApplicationController@ouvrir | À faire |
| `/manifest.webmanifest` — lu sans session par le navigateur | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ApplicationController@manifeste | À faire |
| `/api/schedule` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ApiController@schedule | À faire |
| `/api/services/document-verification` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ApiController@documentVerification | À faire |
| `/api/services/matricule` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ApiController@matriculeLive | À faire |
| `/api/services/parcours` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ApiController@parcoursLive | À faire |
| `/api/services/parcours/add-cours` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ApiController@parcoursAddCours | À faire |
| `/api/data` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | ApiController@data | À faire |

## Messagerie

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/messagerie` — messagerie instantanee (data/messagerie.php), pour tous les comptes | P1 — très utilisée | 177 | 78 | superadmin, admin, registraire, compta, média, chef mention, enseignant, étudiant, work ed. | MessagerieController@index | À faire |

## Finance

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/finance/authorized-reinscription` | P1 — très utilisée | 696 | 10 | superadmin, registraire, compta | FinanceController@authorizedReinscription | À faire |
| `/finance` | P2 — utilisée | 50 | 2 | superadmin | FinanceController@index | À faire |
| `/finance/echeances` — dates limites des tranches, par session | P2 — utilisée | 4 | 2 | superadmin, registraire | FinanceController@echeances | À faire |
| `/finance/account-balance` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | FinanceController@accountBalance | À faire |
| `/finance/account-balance/update` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | FinanceController@accountBalanceUpdate | À faire |
| `/finance/save` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | FinanceController@save | À faire |
| `/finance/insert` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | FinanceController@insert | À faire |

## Work Education

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/work-education` — tache de chaque etudiant, par session ; POST = enregistrer | P1 — très utilisée | 699 | 6 | superadmin, admin, registraire, work ed. | WorkEducationController@index | À faire |

## File d'attente

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/queue/api` — ====================================================================== | P1 — très utilisée | 1124 | 2 | superadmin | QueueController@api | À faire |
| `/queue` | P2 — utilisée | 66 | 2 | superadmin | QueueController@index | À faire |
| `/queue/plan` — plan du bureau et parcours des etudiants (ecran public) | P2 — utilisée | 12 | 1 | superadmin | QueueController@plan | À faire |
| `/queue/display` | P2 — utilisée | 9 | 1 | superadmin | QueueController@display | À faire |

## Public (vitrine, admissions, newsletter)

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/admissions` — any : accepte le POST du formulaire de pre-admission | P2 — utilisée | 78 | 0 | — | HomeController@admissions | À faire |
| `/newsletter` | P2 — utilisée | 6 | 3 | superadmin, admin, registraire | NewsletterController@index | À faire |
| `/` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | HomeController@index | Fait : accueil étudiant, connexion, déconnexion (tranche 1) |
| `/formations` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | HomeController@formations | À faire |
| `/admissions/verifier-email` — adresse du candidat verifiee pendant la frappe | P3 — jamais ouverte (2 mois) | 0 | 0 | — | HomeController@verifierEmail | À faire |
| `/campus` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | HomeController@campus | À faire |
| `/contact` — any : accepte le POST du formulaire de contact | P3 — jamais ouverte (2 mois) | 0 | 0 | — | HomeController@contact | À faire |
| `/newsletter/subscribe` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | NewsletterController@subscribe | À faire |
| `/newsletter/unsubscribe/{token}` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | NewsletterController@unsubscribe | À faire |

## Admissions (personnel)

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/candidatures` — liste admin des candidatures (personnel) | P2 — utilisée | 239 | 7 | superadmin, registraire, chef mention | AdmissionController@candidats | À faire |
| `/candidatures/statut` — instruction : en cours / accepte / refuse | P2 — utilisée | 53 | 4 | superadmin, registraire | AdmissionController@changerStatut | À faire |
| `/candidatures/archiver` — ranger / sortir des archives (n'affecte pas le statut) | P2 — utilisée | 16 | 3 | superadmin, registraire | AdmissionController@archiver | À faire |

## Outils (Studio UAZ, QR, retouche, fusion PDF)

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/retouche-photo` — outil : retouche photo et fond, dans le navigateur (Media et superadmi | P2 — utilisée | 52 | 3 | superadmin, média | DashboardController@retouchePhoto | À faire |
| `/qr-generator` — outil : QR Codes personnalises, dans le navigateur | P2 — utilisée | 11 | 7 | superadmin, registraire, compta, média, chef mention | DashboardController@qrGenerator | À faire |
| `/fusion-pdf` — outil : fusion de PDF, dans le navigateur | P2 — utilisée | 8 | 6 | superadmin, registraire, média, chef mention | DashboardController@fusionPdf | À faire |
| `/studio` — Studio UAZ : visuels a partir de presets, dans la charte (Media et sup | P2 — utilisée | 7 | 3 | superadmin, média | DashboardController@studio | À faire |

## Évaluations et sondages

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/evaluation` — Vue utilisateur | P2 — utilisée | 21 | 8 | admin, registraire, compta, chef mention | EvaluationController@index | À faire |
| `/evaluation/admin` — Vue admin dashboard | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@admin | À faire |
| `/evaluation/create` — Vue création formulaire | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@create | À faire |
| `/evaluation/edit/{id}` — Editer formulaire | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@edit | À faire |
| `/evaluation/update/{id}` — Sauver modification formulaire | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@update | À faire |
| `/evaluation/delete/{id}` — Supprimer formulaire | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@delete | À faire |
| `/evaluation/stats/{id}` — Voir les résultats | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@stats | À faire |
| `/evaluation/store` — Traitement création | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@store | À faire |
| `/evaluation/automate` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@automate | À faire |
| `/evaluation/form/{id}` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@viewForm | À faire |
| `/evaluation/submit/{id}` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@submitForm | À faire |
| `/api/evaluation/forms` — CRUD API | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@apiForms | À faire |
| `/api/evaluation/stats/{id}` — Stats API | P3 — jamais ouverte (2 mois) | 0 | 0 | — | EvaluationController@apiStats | À faire |

## Enseignant

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/teacher/presence` — registre de presence (§472 a §476) | P2 — utilisée | 204 | 11 | superadmin, chef mention, enseignant | TeacherController@presence | À faire |
| `/teacher/dashboard` | P2 — utilisée | 120 | 13 | chef mention, enseignant | TeacherController@dashboard | À faire |
| `/teacher/remise-notes` — saisie des notes par l'enseignant | P2 — utilisée | 92 | 12 | chef mention, enseignant | TeacherController@remiseNotes | À faire |

## Inscriptions

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/inscriptions/tardives` — calendrier des inscriptions, retards et penalites (§ 334) | P2 — utilisée | 138 | 7 | superadmin, admin, registraire, compta | InscriptionController@tardives | À faire |
| `/inscription` | P2 — utilisée | 33 | 8 | superadmin, admin, compta, chef mention | InscriptionController@index | À faire |

## Divers

| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |
|---|---|---|---|---|---|---|
| `/reel` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | AdminController@reel | À faire |
| `/button` | P3 — jamais ouverte (2 mois) | 0 | 0 | — | AdminController@button | À faire |
| `/verify` — ====================================================================== | P3 — jamais ouverte (2 mois) | 0 | 0 | — | AdminController@verify | À faire |

## Adresses ouvertes mais absentes de `routes.php` (fichiers appelés directement)

| Adresse | Appels | Utilisateurs | Rôles | Durée moy. (ms) |
|---|---|---|---|---|
| `/inscription/app/generate.stage.php` | 1851 | 12 | superadmin, admin, registraire, chef mention | 20 |
| `/dashboard` | 1389 | 114 | superadmin, registraire, compta, média, chef mention, étudiant | 30 |
| `/inscription/inscription` | 1330 | 22 | superadmin, admin, registraire, compta, média, chef mention | 38 |
| `/app/.student/updatenote` | 982 | 6 | superadmin, admin, registraire | 30 |
| `/student/dashboard` | 970 | 136 | étudiant | 222 |
| `/app/.student/checkCours.php` | 804 | 16 | superadmin, admin, registraire, chef mention | 98 |
| `/app/.student/updateStd` | 786 | 9 | superadmin, registraire | 74 |
| `/inscription/app/mode.payement.php` | 469 | 5 | superadmin, registraire, chef mention | 15 |
| `/inscription/app/recalculer-frais.php` | 452 | 3 | superadmin, registraire | 25 |
| `/inscription/app/generate.student.php` | 418 | 11 | superadmin, admin, registraire, chef mention | 42 |
| `/app/.student/updatePromotionNote` | 397 | 6 | superadmin, registraire | 23 |
| `/inscription/app/retrait.cours.php` | 325 | 7 | superadmin, chef mention | 24 |
| `/app/.cours/updateCours` | 285 | 8 | superadmin, registraire, chef mention | 58 |
| `/app/.presence/enregistrer.php` | 187 | 5 | chef mention, enseignant | 29 |
| `/app/.student/addStd` | 153 | 6 | superadmin, registraire | 1419 |
| `/app/.remise/enregistrer.php` | 147 | 3 | chef mention, enseignant | 32 |
| `/app/.cours/save_offered.php` | 107 | 6 | superadmin, registraire | 18 |
| `/app/.student/del-cours.momentanee.php` | 65 | 8 | superadmin, admin, registraire | 38 |
| `/app/.cours/addCours` | 65 | 3 | superadmin, registraire | 18 |
| `/app/finance-bareme.save.php` | 64 | 2 | superadmin | 24 |
| `/app/retouche-photo.profil.php` | 55 | 2 | superadmin, média | 107 |
| `/app/.student/updtateImgStd.php` | 54 | 1 | superadmin, média | 102 |
| `/src/gen.pdf` | 42 | 5 | superadmin, registraire | 125 |
| `/app/.user/updateUser` | 39 | 1 | superadmin | 91 |
| `/app/mentions.save.php` | 27 | 1 | superadmin | 16 |
| `/app/.student/updateCumulativePromotionNote` | 12 | 2 | registraire | 83 |
| `/app/espace-etudiant.save.php` | 10 | 1 | superadmin | 16 |
| `/app/.student/delStd` | 9 | 3 | superadmin, registraire | 13 |
| `/app/.prof/updateProf` | 9 | 2 | superadmin, registraire | 13 |
| `/app/studio.visuels.php` | 8 | 0 | — | 28 |
| `/src/genPDF/gen.mail_csv` | 8 | 2 | superadmin | 42 |
| `/app/.prof/addProf.php` | 6 | 2 | superadmin, registraire | 25 |
| `/app/.user/mon-compte.save.php` | 6 | 4 | chef mention, enseignant, work ed. | 123 |
| `/src/emploi-temps.api` | 6 | 2 | registraire | 19 |
| `/app/.user/add.user` | 4 | 1 | superadmin | 87 |
| `/app/.student/recup-cours` | 3 | 2 | superadmin, registraire | 29 |
| `/app/.annonces/update.annonce` | 3 | 1 | superadmin | 82 |
| `/app/.cours/del.cours` | 2 | 2 | superadmin, registraire | 11 |
| `/app/.student/retrait-universite.php` | 2 | 1 | registraire | 28 |
| `/app/.student/del-cours.definitive` | 1 | 1 | superadmin | 33 |
| `/evaluation/form/13` | 1 | 1 | admin | 9 |
| `/app/.annonces/add.annonce` | 1 | 1 | superadmin | 870 |
| `/app/.annonces/delete.annonce` | 1 | 1 | superadmin | 21 |
| `/app/.presence/traiter.php` | 1 | 0 | — | 4 |
| `/app/.student/annuler-retrait.php` | 1 | 1 | registraire | 47 |
