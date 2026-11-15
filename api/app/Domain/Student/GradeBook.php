<?php

namespace App\Domain\Student;

use App\Domain\Grades\GradeStatus;
use Illuminate\Support\Facades\DB;

/**
 * Le relevé de notes d'un étudiant, tel que le montre « Mes notes » (src/student.dashboard.php) :
 * sessions PUBLIÉES, cours, moyennes par session et cumulative, notes de promotion.
 *
 * CONFIDENTIALITÉ — appliquée ici, dans la requête, jamais à l'écran :
 *  - une session n'est visible que si `t_notes_publication.publiee = 1` (une session sans ligne reste masquée) ;
 *  - les notes de promotion (Work Education, remarque académique, chapelle) ne sont lues que si la rubrique
 *    « notes_promotion » est visible (interrupteur du superadmin, visible par défaut) ;
 *  - `t_2023_promotion_notes.student_id` est un ENTIER : un matricule non numérique est converti en 0 par MySQL
 *    et pourrait rattacher les notes d'un autre. On n'y accède donc QUE pour un matricule entièrement numérique.
 *
 * Moyennes : pondérées par les crédits (somme crédit × note / somme crédits), sur les seules notes chiffrées qui
 * comptent (voir GradeStatus::countsInAverage). La « moyenne majeure » ne retient que la catégorie 1.
 * Le cumul ajoute crédits et points, jamais des moyennes.
 */
final class GradeBook
{
    /** @return array<string, mixed> */
    public function for(string $studentId, bool $withPromotion = true): array
    {
        $rows = DB::table('t_2023_notes as n')
            ->join('t_2023_session as s', 's.session_id', '=', 'n.session_id')
            ->where('n.student_id', $studentId)
            ->where('n.ajout', 1)
            ->whereIn('n.session_id', DB::table('t_notes_publication')->where('publiee', 1)->select('session_id'))
            ->orderBy('s.session_year')
            ->orderBy('s.session_semester')
            ->orderBy('n.id')
            ->get(['n.id', 'n.Sigle', 'n.title_cours', 'n.credit', 'n.grade', 'n.cours_category', 'n.yearlevel',
                's.session_id', 's.session_name', 's.session_year', 's.session_semester']);

        $showPromotion = $withPromotion && $this->promotionVisible();
        $promotion = $showPromotion ? $this->promotionBySession($studentId, $rows->pluck('session_id')->unique()->all()) : [];

        $sessions = [];
        $promoSessions = 0;
        $cum = ['credits' => 0.0, 'points' => 0.0, 'majorCredits' => 0.0, 'majorPoints' => 0.0, 'work' => 0.0, 'chapel' => 0.0];

        foreach ($rows->groupBy('session_id') as $sessionRows) {
            $first = $sessionRows->first();
            $courses = [];
            $credits = 0;
            $sum = ['credits' => 0.0, 'points' => 0.0, 'majorCredits' => 0.0, 'majorPoints' => 0.0];

            foreach ($sessionRows->sortBy('title_cours', SORT_NATURAL | SORT_FLAG_CASE) as $r) {
                $validation = GradeStatus::isValidationCourse($r->cours_category, $r->Sigle, $r->title_cours);
                $status = GradeStatus::from($r->grade, $validation);
                $counts = ! $validation && $status->countsInAverage();
                $credit = (float) $r->credit;
                $credits += (int) $r->credit;

                if ($counts) {
                    $sum['credits'] += $credit;
                    $sum['points'] += $credit * $status->value;
                    if ((int) $r->cours_category === 1) {
                        $sum['majorCredits'] += $credit;
                        $sum['majorPoints'] += $credit * $status->value;
                    }
                }

                $courses[] = [
                    'id' => (int) $r->id,
                    'code' => $r->Sigle,
                    'title' => $r->title_cours,
                    'credits' => $r->credit === null ? null : (int) $r->credit,
                    'category' => GradeStatus::categoryLabel($r->cours_category),
                    'status' => ['code' => $status->code, 'title' => $status->title],
                    'grade' => $status->value,
                    'counts_in_average' => $counts,
                ];
            }

            $promo = $promotion[$first->session_id] ?? null;
            if ($promo) {
                $promoSessions++;
                $cum['work'] += (float) ($promo->grade_work_educ ?: 0);
                $cum['chapel'] += (float) ($promo->grade_chapel_part ?: 0);
            }

            foreach (['credits', 'points', 'majorCredits', 'majorPoints'] as $k) {
                $cum[$k] += $sum[$k];
            }

            $sessions[] = [
                'session' => ['id' => (int) $first->session_id, 'name' => $first->session_name, 'year' => $first->session_year, 'semester' => (int) $first->session_semester],
                'level_label' => GradeStatus::levelLabel($first->yearlevel === null ? null : (int) $first->yearlevel),
                'courses' => $courses,
                'totals' => ['courses' => count($courses), 'credits' => $credits],
                'averages' => [
                    'major' => $this->ratio($sum['majorPoints'], $sum['majorCredits']),
                    'general' => $this->ratio($sum['points'], $sum['credits']),
                ],
                // Comme l'ancien affichage : une valeur nulle ou à 0 s'affiche « - ».
                'promotion' => $promo ? [
                    'work_education' => $promo->grade_work_educ ?: null,
                    'academic_remark' => $promo->grade_remark_acad ?: null,
                    'chapel' => $promo->grade_chapel_part ?: null,
                ] : null,
            ];
        }

        $count = count($sessions);

        return [
            'sessions' => $sessions,
            'cumulative' => [
                'major' => $this->ratio($cum['majorPoints'], $cum['majorCredits']),
                'general' => $this->ratio($cum['points'], $cum['credits']),
                // Moyenne sur le nombre de sessions affichées (une session sans note de promotion compte pour 0, comme avant).
                // Aucune note de promotion nulle part : « — », jamais un 0 qui ressemblerait à une vraie note.
                'work_education' => $showPromotion && $promoSessions > 0 ? round($cum['work'] / $count, 2) : null,
                'chapel' => $showPromotion && $promoSessions > 0 ? round($cum['chapel'] / $count, 2) : null,
            ],
            'promotion_visible' => $showPromotion,
            'pending_sessions' => $this->pendingSessions($studentId),
        ];
    }

    private function ratio(float $points, float $credits): ?float
    {
        return $credits > 0 ? round($points / $credits, 2) : null;
    }

    /** Rubrique « notes_promotion » : visible par défaut, masquable par le superadmin (t_espace_etudiant_config). */
    private function promotionVisible(): bool
    {
        $value = DB::table('t_espace_etudiant_config')->where('cle', 'notes_promotion')->value('valeur');

        return $value === null ? true : $value === '1';
    }

    /**
     * @param  list<int|string>  $sessionIds
     * @return array<int, object> la première ligne (plus petit id) de chaque session, comme l'ancien fetch()
     */
    private function promotionBySession(string $studentId, array $sessionIds): array
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

    /**
     * Sessions où l'étudiant a des notes encore masquées : on le lui dit, plutôt que de laisser croire qu'elles n'existent pas.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingSessions(string $studentId): array
    {
        return DB::table('t_2023_notes as n')
            ->join('t_2023_session as s', 's.session_id', '=', 'n.session_id')
            ->where('n.student_id', $studentId)
            ->where('n.ajout', 1)
            ->whereNotIn('n.session_id', DB::table('t_notes_publication')->where('publiee', 1)->select('session_id'))
            ->distinct()
            ->orderBy('s.session_year')
            ->orderBy('s.session_semester')
            ->get(['s.session_name', 's.session_year', 's.session_semester'])
            ->map(fn ($s) => ['name' => $s->session_name, 'year' => $s->session_year, 'semester' => (int) $s->session_semester])
            ->all();
    }
}
