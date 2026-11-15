# Étude 04 — Améliorations fonctionnelles (back et front)

*14/11/2026. Principe : on ne reconstruit pas à l'identique, on améliore là où les données de production montrent un vrai problème. Chiffres : journal d'audit du 15/09 au 14/11/2026 (36 975 requêtes, 212 utilisateurs), agrégats seulement.*

## 1. Ce que disent les mesures

| Mesure | Résultat | Lecture |
|---|---|---|
| Durée de réponse | médiane **30 ms**, p95 **288 ms**, moyenne 78 ms | Le serveur est rapide : **pas de chantier de performance général**. |
| Erreurs | 1 erreur 500 et 4 refus 403 en deux mois | L'application est stable : le risque est la régression, pas l'instabilité. |
| Pages lentes | `/app/.student/addStd` (création d'un étudiant) **1 419 ms** en moyenne, max **8 895 ms** (153 appels) ; `/candidatures/statut` **1 348 ms**, max 6 228 ms (53 appels) | Deux traitements synchrones qui font attendre l'utilisateur. |
| Connexions | 2 454 réussies, **609 échecs : 19,9 %** des tentatives | Un utilisateur sur cinq échoue, et **on ne sait pas pourquoi** : le pseudo n'est pas enregistré lors d'un échec. |
| Méthode de connexion | **2 139 appels** à `/api/google-auth` pour 2 454 connexions | La connexion Google domine (appels ≠ connexions réussies : ordre de grandeur). |
| Messagerie | 178 appels sur 29 jours, 78 utilisateurs | Usage modeste. |
| Autorisation de réinscription | `/finance/authorized-reinscription` : 696 consultations, une case à cocher par étudiant | Traitement unitaire, répétitif. |
| Appels AJAX | 34 % des requêtes (12 681) | L'ancienne interface sait déjà faire du « partiel » : la nouvelle API a un public prêt. |

## 2. Améliorations proposées

Chaque ligne : le problème observé → ce qu'on change. **B** = back-end (Laravel), **F** = front (Next.js).

### Priorité A — à inclure dans le socle ou le premier module
| # | Côté | Amélioration | Preuve | Effort |
|---|---|---|---|---|
| A1 | B | **File de tâches** (Laravel queue + worker systemd) pour les traitements lents : changement de statut d'une candidature (notification e-mail/SMS), création d'étudiant, exports lourds. L'API répond tout de suite, l'interface affiche « en cours » puis « terminé ». *Le serveur fait déjà tourner un worker Laravel (`uaz-laravel-queue`).* | 1,3 à 1,4 s en moyenne, jusqu'à 8,9 s | moyen |
| A2 | B+F | **Comprendre les échecs de connexion** : enregistrer le *motif* (mot de passe erroné, compte inconnu, domaine Google refusé, compte suspendu), limiter le débit, et afficher un message précis avec l'action à faire (« Utilisez votre adresse @zurcher.edu.mg avec Google »). | 19,9 % d'échecs, cause inconnue | faible |
| A3 | F | **Connexion « Google d'abord »** pour les étudiants et enseignants ; mot de passe en second. | 2 139 appels Google | faible |
| A4 | B | **Droits centralisés** : les 9 niveaux deviennent des *policies* Laravel testées par une matrice rôle × action, pour que la nouvelle API ne puisse jamais exposer les données d'un étudiant à un autre. | `data/middleware.php` 2 575 lignes de règles dispersées | moyen |
| A5 | B | **API documentée et typée de bout en bout** : OpenAPI généré par Laravel, client TypeScript généré pour Next.js (les types ne dérivent plus). | Évite la dérive entre front et back | faible |
| A6 | B+F | **Sync hors ligne fiable** : chaque changement mis en file porte un identifiant unique (`Idempotency-Key`) ; le serveur ignore un rejeu. Aucune note ni présence en double après une coupure de réseau. | Demande « offline first » du 14/11/2026 ; modules présence et remise de notes | moyen |

### Priorité B — premiers modules après le socle
| # | Côté | Amélioration | Preuve | Effort |
|---|---|---|---|---|
| B1 | F | **File « À traiter » unifiée + actions groupées + annulation** (validée dans la [maquette](maquettes/direction-visuelle.html)) : le registraire valide plusieurs remises de notes en un clic, annule pendant 6 s. Même principe pour l'autorisation de réinscription (une case par étudiant aujourd'hui). | Registraire = rôle le plus actif (12 489 requêtes) ; 696 consultations d'autorisation | moyen |
| B2 | F | **Recherche globale `Ctrl K`** (étudiants, cours, enseignants, par matricule) dans toute l'application. | Une recherche rapide existe déjà (`app/recherche.rapide.php`), mais n'est pas partout | faible |
| B3 | F | **Accueil étudiant « ce qui a changé »** : nouvelles notes, échéance, prochain cours, assiduité face à la limite de 15 % (maquette). | `/student/home` : 191 étudiants ; `/student/dashboard` : 136 | moyen |
| B4 | B | **Notifications unifiées** (Laravel Notifications) : push web, e-mail, SMS, avec préférences par utilisateur, pour « note publiée », « échéance de frais », « remise à valider ». Remplace le code VAPID écrit à la main (`data/push.php`). | Le push n'existe aujourd'hui que pour la messagerie | moyen |
| B5 | F | **États systématiques** : chargement (squelettes), vide (avec action), erreur (avec réessai), hors ligne (« données du 14 nov., 08 h 12 », file de synchronisation visible). | Usage mobile 73 % chez les étudiants, réseau variable | faible, mais à tenir partout |
| B6 | F | **Tableaux → cartes empilées** sous 768 px ; aide d'installation de la PWA pour iOS (« Ajouter à l'écran d'accueil »). | Android dominant, iOS ≈ 4 % mais sans push sans installation | faible |

### Priorité C — à envisager plus tard
- **Double authentification** (Laravel Fortify) pour les niveaux 1 à 4 (superadmin, admin, registraire, comptabilité), qui modifient notes et finances : environ 20 comptes, effort faible, mais à décider avec vous.
- **Rappels d'échéance** automatiques par push/SMS (les tables d'échéances existent) — à confirmer après lecture du schéma.
- **Cache** du tableau de bord du personnel (159 ms en moyenne, 1 986 appels) : gain faible, inutile au départ.

## 3. Ce que je ne propose pas (pour l'instant)
- **Temps réel WebSocket pour la messagerie** : 178 appels en 29 jours ; le polling actuel suffit. On garde la porte ouverte (Laravel Reverb) si l'usage croît.
- **Porter les ~1 078 pages jamais ouvertes en deux mois** : elles restent servies par l'ancien PHP tant que personne ne les demande.
- **Réécriture de tous les « PDF »** : vérifié, `/src/data.topdf` (22 à 44 ms) renvoie une page HTML à imprimer. On ne génère un vrai PDF serveur que pour les documents officiels (étude 05, §7).

## 4. Effet sur le plan de migration
Le socle (étude 02) gagne trois éléments : **file de tâches (A1)**, **droits centralisés (A4)** et **API typée (A5)**. Le journal de connexion (A2) et la connexion Google d'abord (A3) sont les premières livraisons visibles, parce qu'elles touchent tout le monde, sont petites et corrigent un problème mesuré.

## 5. Limites
- Deux mois de journal d'audit, uniquement les utilisateurs connectés. Les 609 échecs de connexion ne peuvent pas être attribués à un compte (pseudo non enregistré) : « 19,9 % » est un taux de tentatives, pas d'utilisateurs.
- ~~Hypothèse sur les pages lentes~~ **Confirmée par la lecture du code (étude 05, §5)** : `changerStatut` envoie l'e-mail et le SMS Twilio pendant la requête ; `addStd` appelle l'API Google Admin pendant la requête.
- ~~PDF à vérifier~~ **Confirmé** : `/src/data.topdf` renvoie une page HTML à imprimer ; seule la fiche d'inscription utilise dompdf (étude 05, §7).
