# Plan des pages : de l'inventaire à « toutes les pages »

*14/11/2026. Fondé sur l'[inventaire](INVENTAIRE-PAGES.md) (127 routes : 22 très utilisées, 52 utilisées, 53 jamais ouvertes en deux mois) et sur la taille réelle du code de l'ancienne application derrière chaque page. Les tailles sont des lignes PHP lues, pas des estimations d'effort ; elles servent à ordonner, pas à promettre des dates.*

## Définition de « une page est faite »
1. **Règles reprises du code, puis prouvées** : parité contre l'ancienne logique sur les vraies données (pour « Mes notes » : 180 comptes, 0 écart), et test comparant directement à la fonction de l'ancien code quand elle existe.
2. **Confidentialité dans la requête de l'API**, jamais dans l'écran (sessions publiées, rubriques masquables, rôle, périmètre).
3. **Tests qui échouent quand on casse la règle** (mutations injectées).
4. **Test de bout en bout dans Chrome** (téléphone et ordinateur), clavier, lecteur d'écran, contraste, cibles de 44 px.
5. Les décisions métier ambiguës sont **signalées, pas tranchées** (exemple : le 0/20 dans la moyenne).

## Lots, dans l'ordre de l'usage réel

| Lot | Pages | Usage (2 mois) | Code ancien | État / remarques |
|---|---|---|---|---|
| **1** | Connexion, déconnexion, accueil étudiant | 191 étudiants | — | ✅ fait |
| **2a** | **Mes notes** | 127 étudiants | `student.dashboard.php` 994 | ✅ fait (parité 180/180) |
| 2b | **Présence** (assiduité, limite 15 %) | 53 | `student.presence.php` 196 + `data/presence.php` 467 | ✅ fait (94 registres comparés à l'ancien code : 0 écart) |
| 2c | **Mes inscriptions** + **fiche en PDF** | 40 / 25 | 173 + 169 (+ dompdf) | ✅ fait (15/11/2026) : liste, fiche lisible dans l'app, PDF serveur (dompdf) ; parité des montants prouvée sur 482 fiches (0 écart) ; photo non reprise ; décision ouverte sur le costume/voyage (voir FONCTIONNALITES.md) |
| 2d | **Mes informations** (profil) | 98 | `student.info.php` 1 797 | ✅ fait (15/11/2026) : lecture seule comme l'ancienne page ; onglets accessibles ; CIN masqué ; menu « Plus » créé |
| 2e | **Actualités** | 51 | `student.actus.php` 1 380 | ✅ fait (15/11/2026) : annonces du registrariat (liste filtrable, détail, images réduites ; parité de la requête sur 10 dates) **+ synchronisation avec zurcher.edu.mg/actualites** (copie locale toutes les 30 min, corps converti en blocs assainis, vérifiée sur les 18 articles réels) |
| 2f | **Quiz** ✅ ; **Jeu** ✅ (15/11/2026 : Rubik's Cube reconstruit, moteur testé, victoire et record enfin détectés) | 50 / 30 | 1 338 / 720 | **Quiz fait** (15/11/2026) : parties jugées par le serveur, scores en base, classement semaine / tout, option « ne pas apparaître » (voir FONCTIONNALITES.md). **Jeu** : à lire d'abord ; à confirmer qu'on le garde |
| **3** | **Enseignants** : tableau de bord, présence ✅ (15/11/2026), remise de notes ✅ (15/11/2026, colonnes libres) ; reste le tableau de bord enseignant | 13 / 12 / 7 | 221 ; 219+341+83 ; 1 077 + 783 | écritures sensibles, **hors ligne** (modèle d'étude 05), validation côté registrariat. **À ne pas oublier : le signalement automatique au registrariat** quand un étudiant dépasse 15 % (`presenceSignalerSiDepasse`, `data/presence.php`). La page étudiante dit « le registrariat en a été informé » : cette phrase n'est vraie que tant que ce signalement existe, y compris dans la nouvelle saisie des présences |
| **4** (en cours : **validation des remises de notes ✅ 16/11/2026**, **assiduité ✅ 16/11/2026**, **validation groupée ✅ 16/11/2026**, **listes et fiches ✅**, **tableau de bord ✅**, **candidatures ✅**, **création d'étudiant ✅** : lot 4 terminé) | **Registrariat** : tableau de bord, étudiants, cours, enseignants, validation des remises de notes, assiduité, candidatures, création d'étudiant | 25 / 23 / 9 / 8 / 12 / 7 / 8 | 600 ; 78 ; 61 ; 74 ; 783 ; 467 ; 763 ; `addStd` 606 | liste + détail, actions groupées (maquette v2) ; `addStd` passe en **file de tâches** (1,4 s, max 8,9 s aujourd'hui) |
| **5** (en cours : **autorisation de réinscription ✅ 16/11/2026**, **soldes ✅ 16/11/2026**, **échéances ✅ 17/11/2026**, **barème et frais supplémentaires ✅ 18/11/2026** : lot 5 terminé) | **Finance** : autorisation de réinscription, soldes, frais, échéances | 10 (696 appels) | 329 ; 639 | actions groupées ; droits niveaux 1, 3, 4 |
| **6** | **Exports et impressions** | ~2 200 appels | `data.topdf` (multiples variantes) | **décision de fond** : vrai PDF serveur pour les documents officiels, XLSX/CSV pour les listes (étude 05 §7) |
| **7** | Messagerie, inscriptions au guichet, file d'attente, Work Education, emploi du temps, outils (Studio, QR, retouche, fusion PDF) | 78 / 22 / 2 / 6 / 15 / 3 | 1 961 ; 1 226 ; — ; 380 ; 2 526 + 625 | messagerie : temps réel et push ; emploi du temps : très gros |
| **8** | Les **53 pages jamais ouvertes** (évaluations, vitrine, newsletter, etc.) | 0 | — | **restent sur l'ancien PHP** tant que personne ne les demande |

## Prérequis transverses (à traiter avec le premier lot qui en a besoin)
- **Voir comme un étudiant** (pour les comptes superadmin et registrariat) : sans cela, ces comptes n'ont aucun écran réel dans les pages étudiantes. Doit être **en lecture seule, journalisé, avec bandeau permanent** ; décision de conception à valider.
- **Pont de session vers l'ancien PHP** (`legacy-login.php`, étude 05) : indispensable dès qu'un rôle a des pages encore sur l'ancienne version.
- **Service worker et file hors ligne** : au lot 3 (enseignants), en reprenant les règles de `hors-ligne.js`.
- **File de tâches Laravel** : au lot 4 (création d'étudiant, notifications de candidature).
- **Règle du 0/20** : décision du registraire, avant que d'autres pages ne recalculent des moyennes.

## Ce que cette approche garantit (et ce qu'elle ne garantit pas)
- Garanti : chaque page livrée est testée contre le comportement actuel et ne montre rien de ce que l'ancienne page cachait.
- **Non garanti** : « toutes les pages » ne veut pas dire les 1 152 fichiers. 74 adresses sont réellement utilisées ; la grande majorité des fichiers restent servis par l'ancien PHP jusqu'à ce qu'on les demande. La coexistence (nginx : `/app` et `/api/v1` vers le nouveau, le reste vers l'ancien) est faite pour cela.
