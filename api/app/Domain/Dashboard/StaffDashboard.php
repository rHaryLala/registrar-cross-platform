<?php

namespace App\Domain\Dashboard;

use App\Domain\Directory\Directory;
use App\Domain\Teacher\TeacherAttendance;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord du personnel (port de `data/tableau-de-bord.php`) : deux blocs.
 *
 *  « À traiter »     ce qui attend quelqu'un aujourd'hui, en compteurs qui ouvrent chacun la page où le traiter ;
 *  « Chiffres clés » l'état de l'université pour une année scolaire, comparé à l'année précédente.
 *
 * Deux règles de l'ancienne application, conservées :
 *  - un compteur n'apparaît que si la page qu'il ouvre est accessible au compte ;
 *  - le périmètre de cycle d'un compte s'applique aux inscriptions, cours et notes (le chiffre du tableau de bord est celui que la page affichera).
 *
 * Pas encore repris (leurs pages ne sont pas dans la nouvelle application) : réinscriptions à autoriser, dossiers au guichet, file d'attente,
 * courbe de la rentrée, entonnoir des admissions, taux de saisie des notes, activité récente.
 */
final class StaffDashboard
{
    /** Une note « non saisie » : vide ou code -1 / -4 (« pas encore noté ») ; -2 (validé) et -3 (échec) sont des résultats saisis. */
    private const UNGRADED = "(n.grade IS NULL OR n.grade IN ('', '-1', '-4'))";

    /** Les inscriptions avec leur ANNÉE EFFECTIVE : celle de leur session (la copie de l'inscription a divergé ; elle ne sert que si la session manque). */
    private const ENROLMENTS = "(SELECT ins.*, COALESCE(NULLIF(se.session_year, ''), TRIM(ins.annee_scolaire)) AS annee_effective
        FROM t_2024_inscription_session ins LEFT JOIN t_2023_session se ON se.session_id = ins.session_id)";

    public static function allows(User $user): bool
    {
        return Directory::allows($user, 'students');   // tout le personnel, comme l'ancien tableau de bord
    }

    /** Candidatures, remises, présences : superadmin et registrariat (`hasAnyPrivilege(['superadmin', 'registrar'])`). */
    private static function registrariat(User $user): bool
    {
        return in_array($user->role(), [Role::Superadmin, Role::Registrar], true);
    }

    public static function previousYear(string $year): string
    {
        $start = (int) substr($year, 0, 4);

        return ($start - 1).' - '.$start;
    }

    /** @return array<string, mixed> */
    public function overview(User $user, ?string $year): array
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.dashboard', 'Accès refusé', 'Le tableau de bord est réservé au personnel.');
        }
        $current = TeacherAttendance::currentYear();
        $years = $this->years($current);
        $year = $year !== null && in_array($year, $years, true) ? $year : $current;
        $registrariat = self::registrariat($user);

        return [
            'year' => $year, 'years' => $years, 'previous' => self::previousYear($year), 'is_current' => $year === $current,
            'to_do' => $this->toDo($user, $registrariat, $current),
            'figures' => $this->figures($user, $registrariat, $year, $current),
        ];
    }

    /** Années proposées : celles qui ont des inscriptions, et l'année en cours (la plus récente d'abord). @return list<string> */
    private function years(string $current): array
    {
        $years = [$current];
        foreach (DB::select('SELECT DISTINCT i.annee_effective FROM '.self::ENROLMENTS.' i WHERE i.`remove` = 0') as $r) {
            $a = trim((string) $r->annee_effective);
            if (preg_match('~^\d{4} - \d{4}$~', $a)) {
                $years[] = $a;
            }
        }
        $years = array_values(array_unique($years));
        rsort($years);

        return $years;
    }

    /** `AND col IN (…)` selon le cycle du compte, ou ''. */
    private function level(User $user, string $column): string
    {
        $levels = Directory::levels($user);

        return $levels === null ? '' : " AND $column IN (".implode(', ', $levels).')';
    }

    // ------------------------------------------------------------------ à traiter

    /**
     * Du plus pressant au moins pressant ; seuls les éléments dont le nombre est positif sont rendus.
     *
     * @return list<array<string, mixed>>
     */
    private function toDo(User $user, bool $registrariat, string $current): array
    {
        $items = [];
        if (! $registrariat) {
            return $items;
        }
        $past = self::previousYear($current);

        $r = DB::selectOne("SELECT COUNT(*) AS n, MIN(soumis_le) AS since FROM t_remise_notes WHERE statut = 'soumise'");
        if ((int) $r->n > 0) {
            $items[] = ['key' => 'reviews', 'tone' => 'attention', 'count' => (int) $r->n, 'since' => $r->since, 'link' => '/validations/'];
        }

        $n = (int) DB::selectOne('SELECT COUNT(*) AS n FROM t_presence_signalement WHERE traite_le IS NULL')->n;
        if ($n > 0) {
            $items[] = ['key' => 'flags', 'tone' => 'attention', 'count' => $n, 'link' => '/assiduite/'];
        }

        $r = DB::selectOne("SELECT COALESCE(SUM(statut = 'nouveau'), 0) AS nouveaux, COALESCE(SUM(statut = 'en_cours'), 0) AS en_cours FROM candidat WHERE archive_le IS NULL");
        if ((int) $r->nouveaux > 0) {
            $items[] = ['key' => 'applications', 'tone' => 'attention', 'count' => (int) $r->nouveaux, 'in_progress' => (int) $r->en_cours, 'link' => '/candidatures/'];
        }

        // Notes encore « non notées » de l'année écoulée : pendant l'année en cours, une note manque normalement tant que l'examen n'a pas eu lieu.
        $rows = DB::select(
            'SELECT n.id_cours, COUNT(*) AS nn, c.Sigle, c.sigle_affiche, c.title, t.name, t.lastName
             FROM t_2023_notes n LEFT JOIN t_2023_cours c ON c.id = n.id_cours LEFT JOIN teacher t ON t.uid = c.id_teacher
             WHERE n.`remove` = 0 AND '.self::UNGRADED.' AND n.annee_scolaire = ?'.$this->level($user, 'n.yearlevel').'
             GROUP BY n.id_cours, c.Sigle, c.sigle_affiche, c.title, t.name, t.lastName ORDER BY nn DESC',
            [$past],
        );
        if ($rows) {
            $items[] = [
                'key' => 'ungraded', 'tone' => 'attention', 'count' => (int) array_sum(array_column($rows, 'nn')), 'courses' => count($rows), 'year' => $past,
                'list' => array_map(fn ($c) => [
                    'course_id' => $c->id_cours ? (int) $c->id_cours : null, 'code' => Directory::courseCode($c->sigle_affiche, $c->Sigle), 'title' => trim((string) $c->title),
                    'teacher' => trim(ucfirst(mb_strtolower((string) $c->name)).' '.(string) $c->lastName) ?: null, 'count' => (int) $c->nn,
                ], array_slice($rows, 0, 8)),
            ];
        }

        // Cours offerts cette année sans enseignant (ou avec un enseignant supprimé).
        $rows = DB::select(
            'SELECT DISTINCT c.id, c.Sigle, c.sigle_affiche, c.title, cs.session_label
             FROM t_2023_course_sessions cs JOIN t_2023_cours c ON c.id = cs.course_id LEFT JOIN teacher t ON t.uid = c.id_teacher
             WHERE cs.academic_year = ? AND cs.offered = 1 AND c.`remove` != 1 AND (t.uid IS NULL OR t.`remove` = 1)'.$this->level($user, 'c.yearlevel').'
             ORDER BY cs.session_label, c.Sigle',
            [$current],
        );
        if ($rows) {
            $items[] = [
                'key' => 'no_teacher', 'tone' => 'info', 'count' => count($rows), 'year' => $current,
                'list' => array_map(fn ($c) => ['course_id' => (int) $c->id, 'code' => Directory::courseCode($c->sigle_affiche, $c->Sigle), 'title' => trim((string) $c->title), 'session' => $c->session_label], array_slice($rows, 0, 8)),
            ];
        }

        // Deux cours actifs montrés sous le même sigle.
        $rows = DB::select(
            "SELECT UPPER(TRIM(REGEXP_REPLACE(COALESCE(NULLIF(TRIM(sigle_affiche), ''), Sigle), '[[:space:]]+', ' '))) AS code, GROUP_CONCAT(id ORDER BY id) AS ids
             FROM t_2023_cours WHERE `remove` != 1".$this->level($user, 'yearlevel').' GROUP BY code HAVING COUNT(*) > 1 ORDER BY code',
        );
        if ($rows) {
            $items[] = [
                'key' => 'duplicate_codes', 'tone' => 'info', 'count' => count($rows),
                'list' => array_map(fn ($d) => ['code' => $d->code, 'course_ids' => array_map('intval', explode(',', $d->ids))], $rows),
            ];
        }

        // Le plus pressant d'abord, puis le plus nombreux.
        usort($items, fn ($a, $b) => [$a['tone'] !== 'attention', -$a['count']] <=> [$b['tone'] !== 'attention', -$b['count']]);

        return $items;
    }

    // ------------------------------------------------------------------ chiffres clés

    /**
     * Inscriptions et candidatures d'une année scolaire, comparées à l'année précédente. Un étudiant compte UNE fois par année, sur sa DERNIÈRE
     * inscription de l'année : chaque répartition totalise ainsi exactement le nombre d'inscrits.
     * Pas de répartition par année d'études : `niveau_std` vaut 0 par défaut dans les anciennes données ; seuls 4 et 5 (master) sont fiables.
     *
     * @return array<string, mixed>
     */
    private function figures(User $user, bool $registrariat, string $year, string $current): array
    {
        $before = self::previousYear($year);
        $scope = $this->level($user, 'i.niveau_std');
        $latest = 'SELECT MAX(i.id) id FROM '.self::ENROLMENTS.' i WHERE i.`remove` = 0 AND i.annee_effective IN (?, ?) GROUP BY i.annee_effective, i.student_id';
        $people = 'SELECT student_id, MIN(sex) sex FROM tbl_2024_etudiant GROUP BY student_id';   // fiches dédoublonnées

        $empty = ['enrolled' => 0, 'new' => 0, 'bachelor' => 0, 'master' => 0, 'women' => 0, 'men' => 0, 'boarding' => 0, 'bungalow' => 0, 'external' => 0];
        $totals = [];
        foreach (DB::select(
            "SELECT i.annee_effective a, COUNT(*) enrolled, SUM(i.new_student = 1) new_students, SUM(i.niveau_std IN ('4', '5')) master,
                    SUM(NOT (i.niveau_std IN ('4', '5')) OR i.niveau_std IS NULL) bachelor, SUM(e.sex = '0') women, SUM(e.sex = '1') men,
                    SUM(i.status = 'Interne') boarding, SUM(i.status = 'Bungalow') bungalow, SUM(i.status IS NULL OR i.status NOT IN ('Interne', 'Bungalow')) external
             FROM ".self::ENROLMENTS." i JOIN ($latest) d ON d.id = i.id LEFT JOIN ($people) e ON e.student_id = i.student_id
             WHERE 1 = 1$scope GROUP BY i.annee_effective",
            [$year, $before],
        ) as $r) {
            $totals[trim((string) $r->a)] = [
                'enrolled' => (int) $r->enrolled, 'new' => (int) $r->new_students, 'bachelor' => (int) $r->bachelor, 'master' => (int) $r->master, 'women' => (int) $r->women,
                'men' => (int) $r->men, 'boarding' => (int) $r->boarding, 'bungalow' => (int) $r->bungalow, 'external' => (int) $r->external,
            ];
        }

        // Pendant la rentrée, la comparaison qui compte est « à la même date l'an dernier ».
        $sameDate = null;
        if ($year === $current) {
            $sameDate = (int) DB::selectOne(
                'SELECT COUNT(DISTINCT i.student_id) AS n FROM '.self::ENROLMENTS." i WHERE i.`remove` = 0 AND i.annee_effective = ? AND i.date_entry <= CURDATE() - INTERVAL 1 YEAR$scope",
                [$before],
            )->n;
        }

        $labels = [];
        foreach (DB::select('SELECT filiere_sigle, filiere_description FROM filiere') as $r) {
            $labels[$r->filiere_sigle] = $r->filiere_description;
        }
        $mentions = [];
        foreach (DB::select(
            "SELECT i.annee_effective a, UPPER(TRIM(COALESCE(i.etude_mention, ''))) m, COUNT(*) n FROM ".self::ENROLMENTS." i JOIN ($latest) d ON d.id = i.id WHERE 1 = 1$scope GROUP BY i.annee_effective, m",
            [$year, $before],
        ) as $r) {
            $code = $r->m !== '' ? $r->m : '?';
            $mentions[$code] ??= ['code' => $code, 'label' => $labels[$code] ?? ($code === '?' ? 'Mention non renseignée' : $code), 'count' => 0, 'before' => 0];
            $mentions[$code][trim((string) $r->a) === $year ? 'count' : 'before'] = (int) $r->n;
        }
        uasort($mentions, fn ($x, $y) => [$y['count'], $y['before']] <=> [$x['count'], $x['before']]);

        // Candidatures reçues sur l'année scolaire (1er juillet – 30 juin).
        $applications = null;
        if ($registrariat) {
            $d = (int) substr($year, 0, 4);
            $r = DB::selectOne(
                "SELECT COALESCE(SUM(date_creation >= ? AND date_creation < ?), 0) AS received, COALESCE(SUM(date_creation >= ? AND date_creation < ? AND statut = 'accepte'), 0) AS accepted,
                        COALESCE(SUM(date_creation >= ? AND date_creation < ?), 0) AS before_year FROM candidat",
                [$d.'-07-01', ($d + 1).'-07-01', $d.'-07-01', ($d + 1).'-07-01', ($d - 1).'-07-01', $d.'-07-01'],
            );
            $applications = ['received' => (int) $r->received, 'accepted' => (int) $r->accepted, 'before' => (int) $r->before_year];
        }

        return [
            'current' => ($totals[$year] ?? []) + $empty, 'before' => ($totals[$before] ?? []) + $empty, 'same_date' => $sameDate,
            'mentions' => array_values($mentions), 'applications' => $applications,
        ];
    }
}
