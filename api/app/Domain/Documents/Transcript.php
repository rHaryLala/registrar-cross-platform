<?php

namespace App\Domain\Documents;

use App\Domain\Grades\GradeStatus;
use Illuminate\Support\Facades\DB;

/**
 * Le relevé semestriel des notes d'un étudiant, pour le personnel : port de src/extenssionPrint/transcriptSS.php (569 appels en deux mois).
 *
 * Ce n'est PAS « Mes notes » : le personnel voit TOUTES les sessions, publiées ou non, et les notes de promotion sans l'interrupteur de
 * l'espace étudiant. Les règles de calcul sont celles de l'ancien relevé, à la lettre :
 *  - une note n'est « chiffrée » que si elle est numérique ET > 0 ; 0, vide, -1 et -4 sont « pas encore noté » (`NN`, ou `--` pour 0) ;
 *  - -2 est « validé sans note » (V), -3 « échec sans note » (E) ; un cours de validation (catégorie 5, RELP 291, formation spirituelle)
 *    ne s'exprime qu'en V ou E et n'entre jamais dans la moyenne ;
 *  - la moyenne d'une session pèse chaque note par ses crédits ; la « majeure » ne retient que la catégorie 1 ; le cumul additionne
 *    crédits et points de TOUTES les sessions affichées, jamais des moyennes ;
 *  - la note de Work Education et celle de chapelle sont cumulées en moyenne sur les seules sessions où elles sont renseignées ;
 *  - le niveau affiché d'une session est celui de la majorité de ses cours (à égalité, le plus élevé).
 *
 * Écarts assumés avec l'ancien relevé :
 *  - un cours supprimé (`remove = 1`) n'y figure plus (9 notes dans la copie locale, que l'ancien relevé comptait encore) ;
 *  - une note à virgule décimale (« 18,28 ») est lue comme un nombre, comme sur « Mes notes » (l'ancien relevé la lisait « 18 » en la
 *    comptant « pas encore noté » : 2 notes dans la copie locale) ;
 *  - sans note chiffrée, une moyenne s'affiche « -- » (l'ancien relevé affichait 0 pour les moyennes cumulées) ;
 *  - les notes de promotion ne sont lues que pour un matricule numérique (la colonne est un entier : « 20023MG » serait lu « 20023 »).
 */
final class Transcript
{
    /**
     * @param  array{year?: ?string, semester?: ?int, level?: ?int, session?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function build(object $student, array $filters = []): array
    {
        $studentId = (string) $student->student_id;
        $rows = $this->sessionList($studentId, $filters);

        $promotion = $this->promotion($studentId, $rows->pluck('session_id')->all());
        $sessions = [];
        $total = ['courses' => 0, 'valid' => 0, 'failed' => 0, 'incomplete' => 0, 'validCredits' => 0, 'failedCredits' => 0, 'incompleteCredits' => 0];
        $cum = ['credits' => 0, 'gpaCredits' => 0.0, 'gpaPoints' => 0.0, 'majorCredits' => 0.0, 'majorPoints' => 0.0, 'work' => 0.0, 'workCount' => 0, 'chapel' => 0.0, 'chapelCount' => 0];

        foreach ($rows as $s) {
            $session = $this->session($studentId, $s, $promotion[(int) $s->session_id] ?? null);
            if ($session === null) {
                continue;
            }
            $r = $session['recap'];
            $total['courses'] += $session['totals']['courses'];
            $total['valid'] += $r['valid_courses'];
            $total['failed'] += $r['failed_courses'];
            $total['incomplete'] += $r['incomplete_courses'];
            $total['validCredits'] += $r['valid_credits'];
            $total['failedCredits'] += $r['failed_credits'];
            $total['incompleteCredits'] += $r['incomplete_credits'];

            $p = $session['promotion'];
            // Compteurs indépendants : un semestre non renseigné ne dilue pas l'autre note.
            if ($p !== null && ! empty($p['work_education'])) {
                $cum['work'] += (float) $p['work_education'];
                $cum['workCount']++;
            }
            if ($p !== null && ! empty($p['chapel'])) {
                $cum['chapel'] += (float) $p['chapel'];
                $cum['chapelCount']++;
            }
            $cum['gpaCredits'] += $session['_gpaCredits'];
            $cum['gpaPoints'] += $session['_gpaPoints'];
            $cum['majorCredits'] += $session['_majorCredits'];
            $cum['majorPoints'] += $session['_majorPoints'];
            $cum['credits'] += $session['totals']['credits'];
            unset($session['_gpaCredits'], $session['_gpaPoints'], $session['_majorCredits'], $session['_majorPoints']);
            $sessions[] = $session;
        }

        return [
            'student' => [
                'student_id' => $studentId,
                'name' => trim(mb_strtoupper((string) $student->student_nom).' '.$student->student_prenom),
                'mention' => $student->etude_envisage ?: null,
                'track' => $student->etude_option ?: null,
                'level_label' => $this->levelLabel($student->annee_etude === null || $student->annee_etude === '' ? null : (int) $student->annee_etude),
                'email' => $student->student_email ?: null,
                'phone' => $student->student_tel ?: null,
                'address' => $student->student_adresse ?: null,
            ],
            'sessions' => $sessions,
            'summary' => [
                'sessions' => count($sessions),
                'courses' => $total['courses'],
                'valid_courses' => $total['valid'],
                'valid_credits' => $total['validCredits'],
                'failed_courses' => $total['failed'],
                'failed_credits' => $total['failedCredits'],
                'incomplete_courses' => $total['incomplete'],
                'incomplete_credits' => $total['incompleteCredits'],
                'credits' => $cum['credits'],
                'success_rate' => $total['courses'] > 0 ? round($total['valid'] / $total['courses'] * 100, 1) : 0.0,
            ],
            'cumulative' => [
                'work_education' => $cum['workCount'] > 0 ? round($cum['work'] / $cum['workCount'], 2) : null,
                'chapel' => $cum['chapelCount'] > 0 ? round($cum['chapel'] / $cum['chapelCount'], 2) : null,
                'major' => $cum['majorCredits'] > 0 ? round($cum['majorPoints'] / $cum['majorCredits'], 2) : null,
                'general' => $cum['gpaCredits'] > 0 ? round($cum['gpaPoints'] / $cum['gpaCredits'], 2) : null,
            ],
        ];
    }

    /**
     * Les sessions où l'étudiant a des notes, pour choisir ce qu'on imprime.
     *
     * @return list<array<string, mixed>>
     */
    public function options(string $studentId): array
    {
        return $this->sessionList($studentId, [])->map(fn ($s) => [
            'session_id' => (int) $s->session_id,
            'session_name' => $s->session_name,
            'year' => $s->session_year,
            'semester' => (int) $s->session_semester,
            'courses' => $this->notes($studentId, (int) $s->session_id)->count(),
        ])->values()->all();
    }

    // ------------------------------------------------------------------------------------------------------------

    /** @return \Illuminate\Support\Collection<int, object> */
    private function sessionList(string $studentId, array $filters)
    {
        $q = DB::table('t_2023_notes as n')->join('t_2023_session as s', 's.session_id', '=', 'n.session_id')
            ->where('n.student_id', $studentId)->where('n.ajout', 1)->where('n.remove', 0);
        if (isset($filters['level'])) {
            $q->where('n.yearlevel', (int) $filters['level']);
        }
        if (isset($filters['semester'])) {
            $q->where('s.session_semester', (int) $filters['semester']);
        }
        if (isset($filters['year']) && $filters['year'] !== '') {
            $q->where('s.session_year', $filters['year']);
        }
        if (isset($filters['session']) && (int) $filters['session'] > 0) {
            $q->where('n.session_id', (int) $filters['session']);
        }

        return $q->select('n.session_id', 's.session_name', 's.session_semester', 's.session_year')->distinct()
            ->orderBy('s.session_year')->orderBy('s.session_semester')->orderBy('n.session_id')->get();
    }

    /** @return \Illuminate\Support\Collection<int, object> les cours de la session, dans l'ordre de saisie */
    private function notes(string $studentId, int $sessionId)
    {
        return DB::table('t_2023_notes')->where('student_id', $studentId)->where('ajout', 1)->where('remove', 0)
            ->where('session_id', $sessionId)->orderBy('id')->get();
    }

    /** Le niveau de la majorité des cours de la session (une session peut en mélanger deux) ; à égalité, le plus élevé. */
    private function sessionLevel(string $studentId, int $sessionId): ?int
    {
        $row = DB::table('t_2023_notes')->where('student_id', $studentId)->where('ajout', 1)->where('remove', 0)->where('session_id', $sessionId)
            ->select('yearlevel')->groupBy('yearlevel')->orderByRaw('COUNT(*) DESC')->orderByDesc('yearlevel')->limit(1)->first();

        return $row === null ? 1 : ($row->yearlevel === null ? null : (int) $row->yearlevel);
    }

    /**
     * Les notes de promotion : la PREMIÈRE ligne (plus petit id) de chaque session, comme l'ancien fetch(). La colonne `student_id` est un
     * entier : un matricule non numérique est converti par MySQL et pourrait rattacher les notes d'un autre, on n'y accède donc pas.
     *
     * @param  list<int|string>  $sessionIds
     * @return array<int, object>
     */
    private function promotion(string $studentId, array $sessionIds): array
    {
        if (! ctype_digit($studentId) || $sessionIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('t_2023_promotion_notes')->where('student_id', (int) $studentId)->whereIn('session_id', $sessionIds)->orderBy('id')->get() as $p) {
            $out[(int) $p->session_id] ??= $p;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function session(string $studentId, object $s, ?object $promotion): ?array
    {
        $notes = $this->notes($studentId, (int) $s->session_id);
        if ($notes->isEmpty()) {
            return null;
        }

        $courses = [];
        $credits = 0;
        $gradeSum = 0.0;
        $points = 0.0;
        $gpaCredits = 0.0;
        $majorCredits = 0.0;
        $majorPoints = 0.0;
        $recap = ['valid_courses' => 0, 'valid_credits' => 0, 'failed_courses' => 0, 'failed_credits' => 0, 'incomplete_courses' => 0, 'incomplete_credits' => 0];

        foreach ($notes as $n) {
            $raw = trim(str_replace(',', '.', (string) ($n->grade ?? '')));
            $numeric = is_numeric($raw);
            $g = $numeric ? (float) $raw : 0.0;
            $credit = (int) $n->credit;
            $category = $n->cours_category === null ? null : (int) $n->cours_category;

            $incomplete = ! $numeric || $g <= 0.0;
            $passFail = GradeStatus::isValidationCourse($n->cours_category, $n->Sigle, $n->title_cours);
            $statusOnly = $g === -2.0 || $g === -3.0;

            if ($g === -2.0 || ($passFail && $g >= 10)) {
                $note = 'V';
            } elseif ($g === -3.0 || ($passFail && $g > 0 && $g < 10)) {
                $note = 'E';
            } elseif ($incomplete) {
                $note = (! $numeric || $g < 0) ? '--' : 'NN';
            } else {
                $note = $raw;
            }

            // L'état suit la même règle pour un cours de validation et pour un cours noté.
            if ($g === -2.0 || $g >= 10) {
                $state = 'valid';
                $recap['valid_courses']++;
                $recap['valid_credits'] += $credit;
            } elseif ($g === -3.0 || ($g > 0 && $g < 10)) {
                $state = 'failed';
                $recap['failed_courses']++;
                $recap['failed_credits'] += $credit;
            } else {
                $state = 'incomplete';
                $recap['incomplete_courses']++;
                $recap['incomplete_credits'] += $credit;
            }

            $outOfAverage = $passFail || $statusOnly || $incomplete;
            $lineTotal = $outOfAverage ? null : $credit * $g;
            $credits += $credit;
            if (! $outOfAverage) {
                $gpaCredits += $credit;
                $gradeSum += $g;
                $points += $credit * $g;
                if ($category === 1) {
                    $majorCredits += $credit;
                    $majorPoints += $credit * $g;
                }
            }

            $courses[] = [
                'code' => $n->Sigle,
                'title' => $n->title_cours,
                'credits' => $credit,
                'category' => $passFail ? 'validation' : self::categoryLabel($category),
                'note' => $note,
                'points' => $lineTotal,
                'state' => $state,
            ];
        }

        $level = $this->sessionLevel($studentId, (int) $s->session_id);

        return [
            'session' => ['id' => (int) $s->session_id, 'name' => $s->session_name, 'year' => $s->session_year, 'semester' => (int) $s->session_semester],
            'level_label' => $this->levelLabel($level),
            'courses' => $courses,
            'totals' => ['courses' => count($courses), 'credits' => $credits, 'grades' => round($gradeSum, 2), 'points' => round($points, 2)],
            'averages' => [
                'major' => $majorCredits > 0 ? round($majorPoints / $majorCredits, 2) : null,
                'general' => $gpaCredits > 0 ? round($points / $gpaCredits, 2) : null,
            ],
            'promotion' => $promotion === null ? null : [
                'work_education' => $promotion->grade_work_educ,
                'chapel' => $promotion->grade_chapel_part,
            ],
            'recap' => $recap,
            '_gpaCredits' => $gpaCredits,
            '_gpaPoints' => $points,
            '_majorCredits' => $majorCredits,
            '_majorPoints' => $majorPoints,
        ];
    }

    /** Catégorie imprimée : « Sélective » pour -1 et 2 ; « - » pour toute autre valeur ou une catégorie vide. */
    public static function categoryLabel(?int $category): string
    {
        return $category === null ? '-' : GradeStatus::categoryLabel($category);
    }

    private function levelLabel(?int $level): ?string
    {
        if ($level === null) {
            return null;
        }
        if ($level <= 0) {
            return 'Remise à niveau';
        }

        return $level <= 3 ? "Licence {$level}" : 'Master '.($level - 3);
    }
}
