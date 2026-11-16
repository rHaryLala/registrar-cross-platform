<?php

namespace App\Domain\Directory;

use App\Domain\Finance\Reenrolment;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Facades\DB;

/**
 * Les trois listes du registrariat — étudiants, cours, enseignants — port fidèle de `data/listes.php`.
 *
 * Chaque liste est DÉCRITE une fois (colonnes, recherche, filtres, tris) ; l'état d'une liste (recherche, filtres, tri, page) est lu de la requête
 * et vérifié contre cette description. Aucune valeur de la requête n'entre dans le SQL autrement que liée : les colonnes et les tris viennent d'ici.
 *
 *   q        recherche, plusieurs mots (chacun doit se retrouver dans au moins un champ)
 *   filtres  plusieurs valeurs par filtre (OU), plusieurs filtres (ET)
 *   tri      une colonne et un sens
 *   page     « afficher la suite » (200 lignes par page)
 *
 * Accès (comme l'ancienne application) :
 *  - étudiants : tout le personnel, sauf l'étudiant, l'enseignant et le responsable du Work Education ;
 *  - cours et enseignants : superadmin et registrariat seulement.
 * Périmètre d'un compte (`data/perimetre.php`) : un compte limité à un cycle (Licence 0–3, Master 4–5) ne voit que celui-là (étudiants et cours) ;
 * un chef de mention ne voit dans la liste des étudiants que SA mention (aucune mention attribuée : liste vide).
 */
final class Directory
{
    public const PER_PAGE = 200;

    public const LISTS = ['students', 'courses', 'teachers'];

    /** Les listes servies par `staff/lists/{list}` ; `reenrolment` (finance) a sa propre route, avec ses droits et ses actions. */
    public const FINANCE_LISTS = ['reenrolment'];

    private const CYCLES = ['L' => [0, 1, 2, 3], 'M' => [4, 5]];

    // ------------------------------------------------------------------ accès

    public static function allows(User $user, string $list): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        $role = $user->role();

        return match ($list) {
            'students' => $role !== null && ! in_array($role, [Role::Student, Role::Teacher, Role::WorkEducation], true),
            'courses', 'teachers' => in_array($role, [Role::Superadmin, Role::Registrar], true),
            'reenrolment' => Reenrolment::allows($user),
            default => false,
        };
    }

    /** Cycle du compte ('L', 'M') ou null : voit tout. */
    public static function cycle(User $user): ?string
    {
        $c = (string) ($user->perimetre_cycle ?? '');

        return isset(self::CYCLES[$c]) ? $c : null;
    }

    /** @return string|null|false false : pas de restriction ; null : chef de mention sans mention attribuée ; sinon sa mention */
    public static function mentionScope(User $user): string|null|false
    {
        if ((int) $user->level <= 3 || $user->role() !== Role::ChefMention) {
            return false;
        }
        $m = trim((string) ($user->perimetre_mention ?? ''));

        return $m === '' ? null : $m;
    }

    /** Les niveaux visibles du compte (annee_etude, yearlevel), null : tous. @return list<int>|null */
    public static function levels(User $user): ?array
    {
        $c = self::cycle($user);

        return $c === null ? null : self::CYCLES[$c];
    }

    // ------------------------------------------------------------------ libellés

    public static function levelLabel(mixed $n): string
    {
        if ($n === null || $n === '') {
            return 'Non renseigné';
        }
        $n = (int) $n;

        return $n === 0 ? 'Remise à niveau' : ($n <= 3 ? 'Licence '.$n : 'Master '.($n - 3));
    }

    public static function categoryLabel(mixed $c): string
    {
        return [0 => 'Général', 1 => 'Majeur', 2 => 'Sélectif', -1 => 'Sélectif', 3 => 'Additionnel'][(int) $c] ?? 'Autre';
    }

    /** Le code affiché d'un cours : son alias, à défaut son sigle technique. */
    public static function courseCode(?string $alias, ?string $sigle): string
    {
        $a = trim((string) $alias);

        return $a !== '' ? $a : trim((string) $sigle);
    }

    // ------------------------------------------------------------------ définitions

    /** @return array<string, mixed> */
    private function definition(User $user, string $list): array
    {
        return match ($list) {
            'students' => $this->students($user),
            'courses' => $this->courses($user),
            'teachers' => $this->teachers(),
            'reenrolment' => $this->reenrolment(),
            default => throw new ApiProblem(404, 'directory.unknown_list', 'Liste inconnue', 'Cette liste n’existe pas.'),
        };
    }

    private function students(User $user): array
    {
        $params = [];
        $base = 'e.remove != 1';
        $levels = self::levels($user);
        if ($levels !== null) {
            $base .= ' AND e.annee_etude IN ('.implode(', ', array_map(fn ($n) => "'$n'", $levels)).')';
        }
        $mention = self::mentionScope($user);
        $notice = null;
        if ($mention === null) {
            $base .= ' AND 1 = 0';
            $notice = 'Aucune mention n’est attribuée à votre compte : la liste reste vide. Demandez au registrariat d’indiquer votre mention dans votre compte.';
        } elseif ($mention !== false) {
            $base .= ' AND e.etude_envisage = ?';
            $params[] = $mention;
            $notice = 'Étudiants de la mention '.$mention.'.';
        }

        $filters = [
            'mention' => ['label' => 'Mention', 'field' => 'e.etude_envisage'],
            'parcours' => ['label' => 'Parcours', 'field' => 'e.etude_option', 'after' => 'mention', 'help' => 'Choisissez d’abord une mention pour voir ses parcours.'],
            'annee' => ['label' => 'Année scolaire', 'field' => 'e.annee_scolaire', 'order' => 'desc'],
            'niveau' => ['label' => 'Niveau', 'field' => 'e.annee_etude', 'labels' => fn ($v) => self::levelLabel($v), 'order' => 'num'],
            'statut' => ['label' => 'Inscription', 'cases' => [
                'nouveau' => ['Nouveaux étudiants', 'e.new_student = 1'],
                'ancien' => ['Réinscrits', 'COALESCE(e.new_student, 0) <> 1'],
            ]],
        ];
        if ($mention !== false) {   // chef de mention : une seule mention, le filtre n'a plus lieu d'être ; le parcours s'ouvre directement
            unset($filters['mention'], $filters['parcours']['after'], $filters['parcours']['help']);
        }

        return [
            'from' => 'tbl_2024_etudiant e',
            'select' => 'e.id, e.student_id, e.student_nom, e.student_prenom, e.etude_envisage, e.etude_option, e.annee_scolaire, e.annee_etude, e.new_student',
            'base' => $base, 'base_params' => $params, 'key' => 'e.id', 'notice' => $notice,
            'search' => ['e.student_id', 'e.student_nom', 'e.student_prenom', 'e.student_email', 'e.student_tel'],
            'sorts' => [
                'recent' => ['Plus récents', 'e.id', 'desc'],
                'matricule' => ['Matricule', 'e.student_id', 'asc'],
                'nom' => ['Nom', 'e.student_nom', 'asc', 'e.student_prenom'],
                'prenom' => ['Prénom', 'e.student_prenom', 'asc', 'e.student_nom'],
                'mention' => ['Mention', 'e.etude_envisage', 'asc', 'e.student_nom'],
                'parcours' => ['Parcours', 'e.etude_option', 'asc', 'e.student_nom', true],
                'annee' => ['Année scolaire', 'e.annee_scolaire', 'desc', 'e.student_nom'],
                'niveau' => ['Niveau', 'e.annee_etude', 'asc', 'e.student_nom'],
            ],
            'default_sort' => 'recent', 'filters' => $filters,
            'row' => fn (object $r) => [
                'id' => (int) $r->id, 'student_id' => (string) $r->student_id, 'last_name' => $r->student_nom, 'first_name' => $r->student_prenom,
                'mention' => $r->etude_envisage, 'track' => $r->etude_option, 'year' => $r->annee_scolaire,
                'level' => $r->annee_etude === null || $r->annee_etude === '' ? null : (int) $r->annee_etude, 'level_label' => self::levelLabel($r->annee_etude),
                'is_new' => (int) ($r->new_student ?? 0) === 1,
            ],
        ];
    }

    /**
     * Autorisation de réinscription (`src/finance/authorized_reinscription.php`) : tous les étudiants non retirés, avec les deux drapeaux et qui les a posés.
     * Comme l'ancienne page : AUCUN périmètre de cycle ni de mention (la caisse et le registrariat voient tout l'effectif). Les quatre états s'excluent
     * et totalisent l'effectif : ils servent de filtre et de tableau de bord.
     */
    private function reenrolment(): array
    {
        $auth = 'COALESCE(e.authorized_reinscription, 0) = 1';
        $file = 'COALESCE(e.dossier_ok, 0) = 1';

        return [
            'from' => Reenrolment::FROM,
            'select' => 'e.id, e.student_id, e.student_nom, e.student_prenom, e.etude_envisage, e.etude_option, e.annee_etude, e.annee_scolaire, e.status, '.Reenrolment::SELECT,
            'base' => 'e.remove != 1', 'base_params' => [], 'key' => 'e.id', 'notice' => null,
            'search' => ['e.student_id', 'e.student_nom', 'e.student_prenom', 'e.etude_envisage', 'e.etude_option'],
            'sorts' => [
                'matricule' => ['Matricule', 'e.student_id', 'desc'],
                'nom' => ['Nom', 'e.student_nom', 'asc', 'e.student_prenom'],
                'mention' => ['Mention', 'e.etude_envisage', 'asc', 'e.student_nom'],
                'annee' => ['Année scolaire', 'e.annee_scolaire', 'desc', 'e.student_nom'],
                'niveau' => ['Niveau', 'e.annee_etude', 'asc', 'e.student_nom'],
            ],
            'default_sort' => 'matricule',
            'filters' => [
                'etat' => ['label' => 'État', 'cases' => [
                    'prets' => ['Prêts à se réinscrire', "($auth AND $file)"],
                    'attente_caisse' => ['En attente de la caisse', "(NOT ($auth) AND $file)"],
                    'attente_dossier' => ['En attente du dossier', "($auth AND NOT ($file))"],
                    'aucun' => ['Ni l’un ni l’autre', "(NOT ($auth) AND NOT ($file))"],
                ]],
                'mention' => ['label' => 'Mention', 'field' => 'e.etude_envisage'],
                'parcours' => ['label' => 'Parcours', 'field' => 'e.etude_option', 'after' => 'mention', 'help' => 'Choisissez d’abord une mention pour voir ses parcours.'],
                'annee' => ['label' => 'Année scolaire', 'field' => 'e.annee_scolaire', 'order' => 'desc'],
                'niveau' => ['label' => 'Niveau', 'field' => 'e.annee_etude', 'labels' => fn ($v) => self::levelLabel($v), 'order' => 'num'],
            ],
            'row' => fn (object $r) => Reenrolment::row($r),
        ];
    }

    private function courses(User $user): array
    {
        $mentions = [];
        foreach (DB::select('SELECT filiere_sigle, filiere_description FROM filiere') as $r) {
            $mentions[(string) $r->filiere_sigle] = (string) $r->filiere_description;
        }
        $tracks = ['all' => 'Tronc commun'];
        foreach (DB::select('SELECT shortcode, description FROM filiere_parcours') as $r) {
            $tracks[(string) $r->shortcode] = (string) $r->description;
        }
        $teacherName = "TRIM(CONCAT_WS(' ', CONVERT(t.name USING utf8mb4), CONVERT(t.lastName USING utf8mb4))) COLLATE utf8mb4_general_ci";
        $withTeacher = "EXISTS (SELECT 1 FROM teacher t WHERE t.uid = c.id_teacher AND $teacherName <> '')";
        $levels = self::levels($user);

        return [
            'from' => 't_2023_cours c',
            'select' => "c.id, c.Sigle, c.sigle_affiche, c.title, c.dep_desc, c.nb_crd, c.category, c.yearlevel, c.semester,
                CASE WHEN c.parcours = 'all' THEN 'Tronc commun'
                     ELSE (SELECT fp.description FROM filiere_parcours fp WHERE fp.shortcode = c.parcours LIMIT 1) END AS parcours_nom,
                (SELECT NULLIF(TRIM(CONCAT(CONVERT(UPPER(t.name) USING utf8mb4), ' ', CONVERT(t.lastName USING utf8mb4))), '') COLLATE utf8mb4_general_ci
                   FROM teacher t WHERE t.uid = c.id_teacher LIMIT 1) AS enseignant",
            'base' => 'c.remove != 1'.($levels === null ? '' : ' AND c.yearlevel IN ('.implode(', ', $levels).')'), 'base_params' => [], 'key' => 'c.id', 'notice' => null,
            'search' => [
                'c.Sigle', 'c.sigle_affiche', 'c.title', 'c.title_english', 'c.dep_desc',
                "EXISTS (SELECT 1 FROM teacher t WHERE t.uid = c.id_teacher
                   AND CONCAT_WS(' ', CONVERT(t.name USING utf8mb4), CONVERT(t.lastName USING utf8mb4)) COLLATE utf8mb4_general_ci LIKE ?)",
            ],
            'sorts' => [
                'titre' => ['Intitulé', 'c.title', 'asc'],
                'sigle' => ['Sigle', "COALESCE(NULLIF(TRIM(c.sigle_affiche), ''), c.Sigle)", 'asc'],
                'mention' => ['Mention', 'c.dep_desc', 'asc', 'c.title'],
                'parcours' => ['Parcours', 'parcours_nom', 'asc', 'c.title', true],
                'credits' => ['Crédits', 'c.nb_crd + 0', 'desc', 'c.title'],
                'categorie' => ['Catégorie', 'c.category', 'asc', 'c.title'],
                'niveau' => ['Niveau', 'c.yearlevel', 'asc', 'c.semester, c.title'],
                'semestre' => ['Semestre', 'c.semester', 'asc', 'c.yearlevel, c.title'],
                'enseignant' => ['Enseignant', 'enseignant', 'asc', 'c.title', true],
            ],
            'default_sort' => 'titre',
            'filters' => [
                'mention' => ['label' => 'Mention', 'field' => 'c.dep_desc', 'labels' => $mentions],
                'parcours' => ['label' => 'Parcours', 'field' => 'c.parcours', 'labels' => $tracks, 'after' => 'mention', 'help' => 'Choisissez d’abord une mention pour voir ses parcours.'],
                'niveau' => ['label' => 'Niveau', 'field' => 'c.yearlevel', 'labels' => fn ($v) => self::levelLabel($v), 'order' => 'num'],
                'semestre' => ['label' => 'Semestre', 'field' => 'c.semester', 'labels' => fn ($v) => 'Semestre '.$v, 'order' => 'num'],
                'categorie' => ['label' => 'Catégorie', 'field' => 'c.category', 'labels' => fn ($v) => self::categoryLabel($v), 'order' => 'num'],
                'enseignant' => ['label' => 'Enseignant', 'cases' => [
                    'avec' => ['Enseignant désigné', $withTeacher],
                    'sans' => ['Sans enseignant', 'NOT '.$withTeacher],
                ]],
            ],
            'row' => fn (object $r) => [
                'id' => (int) $r->id, 'code' => self::courseCode($r->sigle_affiche, $r->Sigle), 'title' => $r->title, 'mention' => $r->dep_desc, 'track' => $r->parcours_nom,
                'credits' => $r->nb_crd, 'category' => self::categoryLabel($r->category), 'level' => $r->yearlevel === null ? null : (int) $r->yearlevel,
                'level_label' => self::levelLabel($r->yearlevel), 'semester' => $r->semester === null ? null : (int) $r->semester,
                'teacher' => trim((string) ($r->enseignant ?? '')) !== '' ? $r->enseignant : null,
            ],
        ];
    }

    private function teachers(): array
    {
        $campus = "(t.address LIKE '%campus%' OR t.address LIKE '%UAZ%' OR t.address LIKE '%Zurcher%')";
        $adventist = "((t.religion LIKE '%advent%' AND t.religion NOT LIKE '%non%') OR t.religion = 'SDA')";

        return [
            'from' => 'teacher t',
            'select' => 't.teacher_id, t.name, t.lastName, t.address, t.phone, t.email',
            'base' => 't.remove != 1', 'base_params' => [], 'key' => 't.teacher_id', 'notice' => null,
            'search' => ['t.teacher_id', 't.name', 't.lastName', 't.email', 't.phone'],
            'sorts' => [
                'recent' => ['Plus récents', 't.teacher_id + 0', 'desc'],
                'id' => ['Identifiant', 't.teacher_id + 0', 'asc'],
                'nom' => ['Nom', 't.name', 'asc', 't.lastName'],
                'prenom' => ['Prénom', 't.lastName', 'asc', 't.name'],
                'adresse' => ['Adresse', 't.address', 'asc', 't.name', true],
                'telephone' => ['Téléphone', 't.phone', 'asc', null, true],
                'email' => ['Email', 't.email', 'asc', null, true],
            ],
            'default_sort' => 'recent',
            'filters' => [
                'lieu' => ['label' => 'Lieu', 'cases' => [
                    'campus' => ['Sur le campus', $campus],
                    'hors' => ['Hors campus', "(TRIM(COALESCE(t.address, '')) <> '' AND NOT $campus)"],
                    'inconnu' => ['Adresse non renseignée', "TRIM(COALESCE(t.address, '')) = ''"],
                ]],
                'religion' => ['label' => 'Religion', 'cases' => [
                    'adventiste' => ['Adventiste', $adventist],
                    'autre' => ['Non adventiste', "(TRIM(COALESCE(t.religion, '')) <> '' AND NOT $adventist)"],
                    'inconnu' => ['Non renseignée', "TRIM(COALESCE(t.religion, '')) = ''"],
                ]],
            ],
            // `name` est affiché en « Nom » et `lastName` en « Prénom » : c'est ainsi que l'ancienne liste les montre (données saisies dans les deux sens).
            'row' => fn (object $r) => [
                'id' => (int) $r->teacher_id, 'name' => $r->name, 'last_name' => $r->lastName, 'address' => $r->address, 'phone' => $r->phone, 'email' => $r->email,
            ],
        ];
    }

    // ------------------------------------------------------------------ exécution

    /**
     * @param  array<string, mixed>  $query  la requête brute (q, tri, sens, page, un paramètre par filtre)
     * @return array<string, mixed>
     */
    public function listing(User $user, string $list, array $query): array
    {
        if (! in_array($list, [...self::LISTS, ...self::FINANCE_LISTS], true)) {
            throw new ApiProblem(404, 'directory.unknown_list', 'Liste inconnue', 'Cette liste n’existe pas.');
        }
        if (! self::allows($user, $list)) {
            throw new ApiProblem(403, 'forbidden.directory', 'Accès refusé', match ($list) {
                'students' => 'La liste des étudiants est réservée au personnel.',
                'courses' => 'Seuls le superadmin et le registrariat ont accès à la liste des cours.',
                'reenrolment' => 'L’autorisation de réinscription est réservée au superadmin, au registrariat et à la comptabilité.',
                default => 'Seuls le superadmin et le registrariat ont accès à la liste des enseignants.',
            });
        }
        $def = $this->definition($user, $list);
        $state = $this->state($def, $query);

        [$where, $params] = $this->conditions($def, $state);
        $total = (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$def['from']} WHERE $where", $params)->n;
        $rows = DB::select(
            "SELECT {$def['select']} FROM {$def['from']} WHERE $where ORDER BY ".$this->order($def, $state).' LIMIT '.self::PER_PAGE.' OFFSET '.($state['page'] * self::PER_PAGE),
            $params,
        );
        $shown = min($total, ($state['page'] + 1) * self::PER_PAGE);

        return [
            'list' => $list, 'total' => $total, 'shown' => $shown, 'remaining' => max(0, $total - $shown), 'per_page' => self::PER_PAGE,
            'rows' => array_map($def['row'], $rows),
            'facets' => $this->facets($def, $state),
            'state' => $state,
            'sorts' => array_map(fn ($s) => ['label' => $s[0], 'direction' => $s[2]], $def['sorts']),
            'notice' => $def['notice'],
        ];
    }

    /** L'état d'une liste, lu de la requête et vérifié contre la description. @return array{q: string, filters: array<string, list<string>>, sort: string, direction: string, page: int} */
    private function state(array $def, array $query): array
    {
        $q = trim((string) preg_replace('~\s+~u', ' ', is_scalar($query['q'] ?? null) ? (string) $query['q'] : ''));
        $q = mb_substr($q, 0, 120);

        $filters = [];
        foreach ($def['filters'] as $key => $f) {
            if (! isset($query[$key])) {
                continue;
            }
            $values = is_array($query[$key]) ? $query[$key] : [$query[$key]];
            $values = array_values(array_unique(array_filter(array_map(
                fn ($v) => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, 120),
                $values,
            ), fn ($v) => $v !== '' || ! isset($f['cases']))));
            if (isset($f['cases'])) {
                $values = array_values(array_intersect($values, array_keys($f['cases'])));
            }
            if ($values) {
                $filters[$key] = array_slice($values, 0, 40);
            }
        }

        $sort = is_scalar($query['tri'] ?? null) ? (string) $query['tri'] : '';
        if (! isset($def['sorts'][$sort])) {
            $sort = $def['default_sort'];
        }
        $direction = strtolower(is_scalar($query['sens'] ?? null) ? (string) $query['sens'] : '');
        if ($direction !== 'asc' && $direction !== 'desc') {
            $direction = $def['sorts'][$sort][2];
        }

        return ['q' => $q, 'filters' => $filters, 'sort' => $sort, 'direction' => $direction, 'page' => max(0, min(200, is_scalar($query['page'] ?? null) ? (int) $query['page'] : 0))];
    }

    /** La clause WHERE et ses paramètres ; `$except` retire un filtre (pour compter ses options sans qu'il se restreigne lui-même). @return array{0: string, 1: list<mixed>} */
    private function conditions(array $def, array $state, ?string $except = null): array
    {
        $parts = [$def['base']];
        $params = $def['base_params'];

        $words = $state['q'] === '' ? [] : array_slice(explode(' ', $state['q']), 0, 6);
        foreach ($words as $word) {
            $or = [];
            foreach ($def['search'] as $field) {
                $or[] = str_contains($field, '?') ? $field : "$field LIKE ?";
                $params[] = '%'.$word.'%';
            }
            $parts[] = '('.implode(' OR ', $or).')';
        }

        foreach ($state['filters'] as $key => $values) {
            if ($key === $except || ! isset($def['filters'][$key])) {
                continue;
            }
            $f = $def['filters'][$key];
            if (isset($f['cases'])) {
                $parts[] = '('.implode(' OR ', array_map(fn ($v) => $f['cases'][$v][1], $values)).')';
            } else {
                $parts[] = $f['field'].' IN ('.implode(', ', array_fill(0, count($values), '?')).')';
                array_push($params, ...$values);
            }
        }

        return [implode(' AND ', $parts), $params];
    }

    private function order(array $def, array $state): string
    {
        [, $sql, , $then, $empties] = $def['sorts'][$state['sort']] + [3 => null, 4 => false];
        $order = [];
        if ($empties) {
            $order[] = "($sql IS NULL OR $sql = '') ASC";   // les valeurs vides à la fin, quel que soit le sens
        }
        $order[] = "$sql ".strtoupper($state['direction']);
        if ($then) {
            foreach (explode(',', $then) as $p) {
                $order[] = trim($p).' ASC';
            }
        }
        $order[] = $def['key'].' DESC';   // ordre stable d'une page à l'autre

        return implode(', ', $order);
    }

    /** Les options de chaque filtre, avec le nombre de lignes qu'elles donneraient compte tenu des AUTRES filtres et de la recherche. */
    private function facets(array $def, array $state): array
    {
        $out = [];
        foreach ($def['filters'] as $key => $f) {
            $active = $state['filters'][$key] ?? [];
            [$where, $params] = $this->conditions($def, $state, $key);
            $options = [];

            if (isset($f['cases'])) {
                $sums = [];
                foreach ($f['cases'] as [, $cond]) {
                    $sums[] = "SUM(CASE WHEN $cond THEN 1 ELSE 0 END)";
                }
                $n = array_values((array) DB::selectOne('SELECT '.implode(', ', $sums)." FROM {$def['from']} WHERE $where", $params));
                $i = 0;
                foreach ($f['cases'] as $v => [$label]) {
                    $options[] = ['value' => (string) $v, 'label' => $label, 'count' => (int) ($n[$i++] ?? 0), 'on' => in_array((string) $v, $active, true)];
                }
            } else {
                // Un filtre qui dépend d'un autre (parcours après mention) ne propose rien tant que l'autre n'est pas choisi.
                if (! empty($f['after']) && empty($state['filters'][$f['after']]) && ! $active) {
                    $out[$key] = ['label' => $f['label'], 'options' => [], 'help' => $f['help'] ?? null];

                    continue;
                }
                $seen = [];
                foreach (DB::select("SELECT {$f['field']} AS v, COUNT(*) AS n FROM {$def['from']} WHERE $where GROUP BY {$f['field']}", $params) as $r) {
                    $v = (string) $r->v;
                    if ($v === '' && ($f['order'] ?? '') !== 'num') {
                        continue;
                    }
                    $seen[$v] = true;
                    $options[] = ['value' => $v, 'label' => $this->optionLabel($f, $v), 'count' => (int) $r->n, 'on' => in_array($v, $active, true)];
                }
                foreach ($active as $v) {   // une valeur choisie reste proposée même à zéro, pour la retirer
                    if (! isset($seen[$v])) {
                        $options[] = ['value' => $v, 'label' => $this->optionLabel($f, $v), 'count' => 0, 'on' => true];
                    }
                }
                $order = $f['order'] ?? 'alpha';
                usort($options, function ($a, $b) use ($order) {
                    return match ($order) {
                        'num' => (float) $a['value'] <=> (float) $b['value'],
                        'desc' => strcmp($b['value'], $a['value']),
                        default => strcmp(self::alphaKey($a['label']), self::alphaKey($b['label'])),
                    };
                });
            }
            $out[$key] = ['label' => $f['label'], 'options' => $options, 'help' => null];
        }

        return $out;
    }

    /** Clé de tri alphabétique sans accents : « Éducation » avant « Gestion ». */
    public static function alphaKey(string $s): string
    {
        return strtr(mb_strtolower($s), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe']);
    }

    private function optionLabel(array $f, string $v): string
    {
        $l = $f['labels'] ?? null;
        if (is_array($l)) {
            return $l[$v] ?? $v;
        }

        return is_callable($l) ? (string) $l($v) : $v;
    }
}
