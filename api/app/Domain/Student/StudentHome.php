<?php

namespace App\Domain\Student;

use Illuminate\Support\Facades\DB;

/**
 * Ce que voit un étudiant sur son accueil : profil, moyenne, dernières notes, sessions en attente.
 *
 * Toutes les règles de notes (sessions publiées seulement, moyennes pondérées, états V/E/NN) vivent dans
 * GradeBook : l'accueil et la page « Notes » ne peuvent donc jamais afficher deux moyennes différentes.
 */
final class StudentHome
{
    private const RECENT_GRADES = 5;

    public function __construct(private readonly GradeBook $grades) {}

    /** @return array<string, mixed> */
    public function for(string $studentId): array
    {
        $book = $this->grades->for($studentId, withPromotion: false);

        return [
            'student' => $this->profile($studentId),
            'average' => $book['cumulative']['general'],
            'recent_grades' => $this->recent($book['sessions']),
            'pending_sessions' => $book['pending_sessions'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function profile(string $studentId): ?array
    {
        $row = DB::table('tbl_2024_etudiant')
            ->where('student_id', $studentId)
            ->orderBy('remove')
            ->orderByDesc('annee_scolaire')
            ->first(['student_id', 'student_nom', 'student_prenom', 'annee_scolaire']);

        return $row === null ? null : [
            'student_id' => $row->student_id,
            'last_name' => $row->student_nom,
            'first_name' => $row->student_prenom,
            'school_year' => $row->annee_scolaire,
        ];
    }

    /**
     * Les notes des sessions les plus récentes d'abord, les dernières saisies en tête de chaque session.
     *
     * @param  list<array<string, mixed>>  $sessions  triées de la plus ancienne à la plus récente
     * @return list<array<string, mixed>>
     */
    private function recent(array $sessions): array
    {
        $out = [];
        foreach (array_reverse($sessions) as $s) {
            $courses = $s['courses'];
            usort($courses, fn ($a, $b) => $b['id'] <=> $a['id']);
            foreach ($courses as $c) {
                $out[] = [
                    'id' => $c['id'],
                    'course_code' => $c['code'],
                    'course_title' => $c['title'],
                    'credits' => $c['credits'],
                    'status' => $c['status'],
                    'grade' => $c['grade'],
                    'session' => $s['session'],
                ];
                if (count($out) === self::RECENT_GRADES) {
                    return $out;
                }
            }
        }

        return $out;
    }
}
