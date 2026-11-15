# Étude 03 — Direction visuelle « Affiche »

*14/11/2026. Décision D5 : l'identité visuelle de la nouvelle application reprend la **palette et la grammaire du gabarit d'affiche « Semaine de prière »** du Studio d'`uaz-website`, à la place de la charte marine/or de l'ancien registrar.*

> **Révision v2 (14/11/2026) : ce qui suit la §0 remplace les §3 (grammaire de l'affiche) et §6 (navigation).** La palette, les contrastes (§1, §2), la typographie (§4) et le mappage shadcn (§5) restent valables.

> **Révision v3 (14/11/2026) : fonds sombres neutres.** Le fond marine était « trop bleu ». Référence : la capture de l'application Tahiry (fond quasi noir, cartes à peine plus claires, bordures très discrètes, un seul accent de couleur). Le **cyan** `#00c4d4` de l'affiche reste le seul accent ; le marine ne sert plus de fond. Valeurs de référence, dans `web/src/app/globals.css` : sombre = fond `#0a0b0f`, carte `#10131a`, bordure blanche à 10 %, texte `#f2f4f8`, secondaire `#98a0b3` ; clair = fond `#f4f5f7`, carte `#ffffff`, bouton principal marine `#1a2b6a`, texte cyan `#00727e` (5,2:1). Contrastes recalculés : tous ≥ 4,5:1 (texte 16,9 à 17,9:1 ; secondaire 7,1:1 ; cyan 8,7 à 9,2:1). **Les valeurs de fond des §1 et §5 ci-dessous sont remplacées par celles-ci.**

## 0. Révision v2 : palette seule, structure ergonomique

**Décision D5 précisée.** De l'affiche, on ne garde que la **palette** (marine, cyan, lavande, blanc, filet) et la police Inter. **L'or est abandonné** (question tranchée). La grammaire de l'affiche (grand bloc de titre, trait + étiquette, titre bicolore) est **abandonnée comme structure** : elle occupe environ 300 px avant le premier contenu (plus d'un tiers d'un écran de téléphone) et annonce le nom de la page au lieu de l'action à faire. La structure est repensée pour l'ergonomie.

**Maquette v2** : [maquettes/structure-v2.html](maquettes/structure-v2.html) (`#clair` thème clair, `#statique` sans animation). Clavier : `J`/`K` parcourir, `V` valider, `R` renvoyer.

### Principes (chacun est appliqué dans la maquette)
1. **La tâche d'abord.** Le premier écran répond à « que dois-je faire maintenant ? ». Étudiant : *À faire maintenant → Aujourd'hui → Nouveautés → Mon suivi* (urgence, puis chronologie, puis récapitulatif).
2. **Le tableau de bord du registrariat est la file de travail**, pas un écran de chiffres : le registraire est le rôle le plus actif (12 489 requêtes en deux mois).
3. **Liste + détail** : on traite sans changer de page ; après chaque action, la demande suivante s'ouvre toute seule. Une demande coûte **un clic ou une touche** (`V`). Sur téléphone, la même vue devient deux écrans (liste, puis détail avec retour).
4. **Un seul bouton principal par écran** ; l'action secondaire est discrète. Le risque se gère par l'**annulation (6 s)** plutôt que par des fenêtres de confirmation.
5. **Le système prépare la décision** : anomalies détectées avant de valider (« une note manque », « la moyenne monte de 6,1 points ») et bouton « Valider les 3 sans point d'attention ». Le bouton principal change de libellé quand il y a une anomalie : « Valider malgré tout ».
6. **Reconnaître plutôt que se souvenir** : icône + libellé partout, 5 onglets maximum, entrée courante toujours marquée, filtres avec compteurs, chemin affiché (« Accueil / À traiter »).
7. **Zone du pouce** : sur téléphone, actions principales pleine largeur (48 px), onglets de 56 px en bas, aucun geste obligatoire.
8. **Retour d'état permanent** : « À jour » / « En ligne », toasts annulables, états vides utiles (« Tout est traité »), erreurs qui disent quoi corriger (« Indiquez le motif : l'enseignant le recevra avec la remise »).
9. **Clavier complet** : `J`/`K`, `V`, `R`, flèches entre onglets, `Ctrl K` ; le focus n'est jamais perdu.
10. **Cohérence** : mêmes jetons, même pastille, même toast partout.

### Structure de navigation par rôle (d'après les pages réellement utilisées)
| Rôle | Navigation | Contenu de « Plus » ou du menu compte |
|---|---|---|
| Étudiant (téléphone) | **Accueil · Notes · Présence · Messages · Plus** | Mes inscriptions, informations, actualités, règlement, aide, déconnexion |
| Enseignant (téléphone) | **Mes cours · Présence · Notes · Messages · Plus**, avec l'état de synchronisation toujours visible | Emploi du temps, aide, déconnexion |
| Registrariat (bureau) | Rail : **À traiter · Étudiants · Cours et notes · Admissions · Finance · Assiduité · Messages** | Paramètres, journal d'activité et compte dans le menu en bas du rail |
Chaque rôle ne voit que ses entrées (la comptabilité ouvre sur Finance).

### Vérifié et corrigé pendant la construction
Test piloté dans Chrome (navigation clavier, validation, annulation, validation groupée, renvoi avec motif obligatoire, filtre, état vide, thèmes sombre et clair, émulation mobile 390 px sans débordement), 0 erreur JavaScript. **Trois défauts trouvés et corrigés** : accord (« 1 demande accept**é** »), **focus clavier perdu** après un filtre ou après la dernière demande, pastilles qui chevauchaient le texte de la liste.
**Non fait** : vue liste + détail sur téléphone (décrite, pas maquettée), règles d'anomalie réelles (celles de la maquette sont inventées : à définir avec le registraire), test avec de vrais utilisateurs. Je recommande un test court avec 5 étudiants et 3 membres du personnel avant de figer les écrans.

**Maquette v1** (conservée pour mémoire) : [maquettes/direction-visuelle.html](maquettes/direction-visuelle.html) (thème sombre et clair, animation GSAP ; `#statique` coupe l'animation, `#clair` force le thème clair). Écrans : accueil étudiant sur téléphone, tableau de bord du registrariat sur bureau.

## 1. Source de la palette
`uaz-website/public/js/admin/studio/moteur.js` (`COULEURS`), police Inter variable auto-hébergée (`public/fonts/studio/inter-latin.woff2`, `inter-latin-ext.woff2`) : on peut reprendre ces fichiers tels quels.

| Jeton | Valeur | Rôle |
|---|---|---|
| `marine-3` | `#0e1c4d` | fond de l'application (sombre) |
| `marine-2` | `#13235b` | surfaces, fond de l'affiche |
| `marine-1` | `#1a2b6a` | début du dégradé de l'affiche, éléments surélevés |
| `cyan` | `#00c4d4` | accent : traits, actions, mot-clé du titre |
| `blanc` | `#ffffff` | texte principal en sombre |
| `doux` | `#c9d1f2` | texte secondaire |
| `pâle` | `#9aa6dc` | libellés, aides |
| `filet` | `rgba(255,255,255,.13)` | tous les séparateurs et bordures (sombre) |
| *(dérivé)* `cyan-texte` | `#007d8a` | cyan lisible comme texte sur fond clair |

Le **marine et l'or** de l'ancien back-office et du site (`#222e65`, `#88724b`, `#c9a84c`) disparaissent de l'interface. Le site institutionnel reste doré : voir §8, point 1.

## 2. Contrastes calculés (WCAG)
**Thème sombre : tout passe** (blanc/marine 13 à 16:1 ; doux 8,7 à 9,8:1 ; pâle 5,5 à 6,9:1 ; cyan sur marine 6,2 à 7,6:1 ; succès 7,7, alerte 8,9, danger 5,4).

**Thème clair : le cyan du gabarit ne tient pas.**
| Couple | Rapport | Verdict |
|---|---|---|
| `#00c4d4` sur blanc | 2,13:1 | ✗ texte et contour de focus |
| `#00a9b8` (cyan du CSS du Studio) sur blanc | 2,85:1 | ✗ |
| `#008c99` sur blanc | 4,03:1 | gros texte seulement |
| **`#007d8a` sur blanc** | **4,88:1** | ✓ retenu pour le texte et le focus |
| blanc sur cyan `#00c4d4` | 2,13:1 | ✗ |
| **marine `#0e1c4d` sur cyan** | **7,62:1** | ✓ le texte d'un bouton cyan est toujours marine |

Règles qui en découlent : (1) jamais de texte blanc sur cyan ; (2) en clair, le cyan `#00c4d4` sert seulement aux aplats, traits et à l'affiche marine ; le texte et le focus utilisent `#007d8a` ; (3) l'état n'est jamais porté par la couleur seule (une pastille porte toujours un mot : « À valider », « En retard »).

## 3. Grammaire : l'application s'ouvre comme une affiche
Ce que l'affiche fait, l'interface le reprend :
1. **L'affiche d'ouverture.** Chaque écran principal commence par un bloc marine en dégradé (`marine-1` → `marine-3`) : une **étiquette** en capitales espacées précédée d'un **trait cyan**, un **titre en 900 sur deux tons** dont un seul mot-clé est cyan, un sous-titre en `doux`. Il reste marine dans les deux thèmes : c'est la signature. Exemples de la maquette : « 2 nouvelles **notes** » (étudiant), « 12 remises de notes à **valider** » (registrariat). Le titre dit l'état utile du moment, pas le nom de la page.
2. **Des filets, pas des ombres.** Structure par traits fins de 1 px (`filet`) et cartes translucides (`rgba(255,255,255,.055)`) en sombre ; cartes blanches bordées en clair. Aucune ombre portée hors du cadre du téléphone.
3. **En-tête de marque** identique à l'affiche : sceau rond, « UNIVERSITÉ ADVENTISTE » en capitales, « Zurcher » en cyan.
4. **Chiffres tabulaires** pour notes, montants et dates.
5. **Pastilles à mot** (`chip`) : point + libellé, couleur d'état seulement en renfort.
6. **Frise de session** (4 jalons, aujourd'hui en cyan) : l'information la plus utile du registrariat est le calendrier (réinscription, examens, délibérations).
7. Réservé pour plus tard : la **bande de photos verticales** de l'affiche, pour l'écran de connexion et les actualités.

## 4. Typographie
**Inter variable** (400 à 900), unique famille (Inter est déjà celle du back-office actuel, du site et du Studio). JetBrains Mono (déjà utilisée par l'ancien registrar) seulement pour matricules et codes, si on les garde.
| Rôle | Réglage |
|---|---|
| Titre d'affiche | 900, interlignage 0,98, espacement −0,035 em, 46 px téléphone, 56–60 px bureau |
| Étiquette / eyebrow | 800, capitales, +0,16 à +0,24 em, 12 px minimum |
| Corps | 400–500, 15 px (jamais moins sur téléphone) |
| Chiffres clés | 900, tabulaires, 26–34 px |
Auto-héberger les woff2 (aucun appel à Google, utile hors ligne).

## 5. Correspondance avec shadcn/ui (variables CSS)
| Variable shadcn | Sombre | Clair |
|---|---|---|
| `--background` | `#0e1c4d` | `#f3f5fb` |
| `--foreground` | `#ffffff` | `#1d2233` |
| `--card` | `#ffffff0e` | `#ffffff` |
| `--popover` | `#13235b` | `#ffffff` |
| `--primary` / `-foreground` | `#00c4d4` / `#0e1c4d` | `#1a2b6a` / `#ffffff` |
| `--secondary` | `#1a2b6a` | `#e8ecf7` |
| `--muted-foreground` | `#9aa6dc` | `#5b6483` |
| `--accent` (survol) | `#ffffff18` | `#eef1fa` |
| `--border` / `--input` | `#ffffff21` | `#dfe3ef` |
| `--ring` | `#00c4d4` | `#007d8a` |
| `--destructive` | `#f87171` | `#b91c1c` |
Jetons de statut en plus : `--success` (`#34d399` / `#047857`), `--warning` (`#fbbf24` / `#b45309`). Le bouton principal est **cyan à texte marine en sombre**, **marine à texte blanc en clair** (un bouton cyan sur fond blanc n'aurait qu'un contour à 2,13:1).

Composants à personnaliser : `Button` (variantes principal/secondaire/fantôme), `Badge` → pastille à mot, `Card` (translucide), `Table` (en-têtes en capitales espacées, survol), `Sheet` (menu mobile), `Command` (recherche `Ctrl K`, déjà esquissée dans la maquette), `Tabs`. Icônes : **lucide-react** (remplace Bootstrap Icons).

## 6. Navigation
- **Téléphone** : barre d'onglets en bas, 5 entrées maximum (Accueil, Notes, Présence, Messages, Plus), cibles de 48 px, onglet actif souligné d'un trait cyan.
- **Bureau** : rail latéral de 216 px (sceau, entrées avec pastilles de compteur « Notes 12 », « Admissions 23 », compte en bas), barre du haut avec recherche globale et état de synchronisation (« En ligne · synchronisé » ; hors ligne : « 2 changements en attente »).
- Même contenu, deux dispositions : les rôles à usage mobile (étudiant, enseignant) partent du téléphone ; le personnel part du bureau.

## 7. Mouvement (GSAP)
Une seule séquence, jouée à l'ouverture d'un écran, qui **recompose l'affiche** : le trait cyan se tire → les mots du titre montent chacun dans leur masque (décalage de 90 ms) → sous-titre et boutons apparaissent → les filets se tracent → les cartes et onglets apparaissent en cascade (45 ms). Environ 1,8 s au total.
- **Transform et opacité seulement** (aucune animation de mise en page) ; `clearProps` à la fin pour ne laisser aucun style résiduel.
- `prefers-reduced-motion` : aucune animation, contenu affiché immédiatement. Sans GSAP chargé, tout reste visible.
- Les écrans de saisie intensive (notes, présence) ne rejouent pas la séquence à chaque changement d'onglet : seulement à la **première** ouverture de la session.
- À tester sur un Android d'entrée de gamme avant de figer les durées.
- **Vérifié** dans Chrome en temps réel : l'animation se termine et les 32 éléments animés retrouvent `opacity 1 / transform none`.

## 8. À trancher
1. ~~L'or~~ : **tranché le 14/11/2026, seule la palette de l'affiche est retenue, sans or.**
2. **Thème par défaut** : « suivre le système » (recommandé : un téléphone en plein soleil préfère le clair, un poste de bureau le sombre) ou toujours sombre comme le back-office actuel ?
3. **Bande de photos** de l'affiche pour la connexion et les actualités : oui ou non ?

## 9. Revue avec `ui-ux-pro-max` (14/11/2026)
Le skill a été interrogé pour le portail étudiant (mobile, espacé) et le registrariat (bureau, dense).
- **Rejeté** : son « système de design » généré est orienté page d'atterrissage (motif « Minimal Single Column » / « Operations Landing », titres en serif EB Garamond, accent vert ou or, fond `#F8FAFC`). Ce n'est pas adapté à une application de gestion et contredit la palette de l'affiche choisie. La marque prime sur ses suggestions.
- **Retenu** : ses règles d'UX (accessibilité, tactile, formulaires, navigation, tableaux, graphiques, mouvement). Appliquées à la maquette :
  | Règle | Changement |
  |---|---|
  | Actions groupées (« bulk actions ») | Cases à cocher + barre « N remises sélectionnées · Valider la sélection » |
  | Annulation après action | Toast « 2 remises validées » + **Annuler** (6 s), qui rétablit l'état exact |
  | Tableaux triables accessibles | `aria-sort` + boutons d'en-tête (tri croissant/décroissant) |
  | Texte ≥ 12 px | Libellés des onglets 11 → 12 px |
  | Zones sûres | Barre d'onglets avec `env(safe-area-inset-bottom)` |
  | Retour tactile | Pression : `scale(.97)` en 120 ms ; `cursor: pointer`, `touch-action: manipulation` |
  | Focus visible | Anneau 3 px ; **cyan sombre en thème clair** (le cyan vif y était invisible, 2,13:1) |
  | Lien d'évitement | « Aller au tableau de bord » |
  | Graphiques | Résumé textuel (`aria-label`) ; chaque valeur reste écrite à côté de sa barre |
  | Mouvement | Séquence d'ouverture ramenée de ~1,8 s à ~1,1 s, cascade de 30 ms ; l'interface reste utilisable pendant l'animation |
- **À faire dans l'application réelle** (non couvert par la maquette) : squelettes de chargement au-delà de 300 ms, transition de page de sortie ≤ 250 ms qui ne bloque jamais la navigation, URL reflétant les filtres et l'onglet (navigation retour prévisible, liens profonds), tableaux en cartes sous 768 px.

## 10. Limites
- La maquette utilise Google Fonts et GSAP 3.12.5 (cdnjs) : en production, tout est auto-hébergé.
- Les données (noms, notes, effectifs) sont fictives. Sur un écran de 390 px, la maquette montre un téléphone dans un téléphone ; l'application réelle occupera tout l'écran.
- Vérifié dans Chrome : rendu sombre et clair à 1 500 px ; émulation mobile à 390 px (aucun débordement horizontal, mesuré) ; animation qui se termine ; **interactions** testées par pilotage du navigateur (sélection, validation groupée, annulation, tri croissant puis décroissant) sans aucune erreur JavaScript. **Non vérifié** : rendu sur un vrai Android, Safari/iOS, lecteur d'écran, navigation au clavier de bout en bout.
