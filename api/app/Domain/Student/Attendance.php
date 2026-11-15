<?php

namespace App\Domain\Student;

use App\Domain\Attendance\AttendanceRules;
use Illuminate\Support\Facades\DB;

/**
 * « Mon assiduité » : cours par cours, les présences, retards et absences tels que les enseignants les ont pointés,
 * ce qu'ils comptent, et où l'étudiant en est du seuil de 15 % (src/student.presence.php).
 *
 * CONFIDENTIALITÉ : on ne lit que les lignes de CET étudiant (`t_presence_ligne.student_id` = son matricule) ;
 * les registres sont ceux où il figure. Aucune donnée d'un autre étudiant n'est jamais chargée.
 */
final class Attendance
{
    /** @return array{courses: list<array<string, mixed>>, alerts: list<array<string, mixed>>} */
    public function for(string $studentId): array
    {
        $sheets = DB::table('t_presence_feuille as f')
            ->whereExists(function ($q) use ($studentId) {
                $q->select(DB::raw(1))
                    ->from('t_presence_seance as s')
                    ->join('t_presence_ligne as l', 'l.seance_id', '=', 's.id')
                    ->whereColumn('s.feuille_id', 'f.id')
                    ->where('l.student_id', $studentId);
            })
            ->orderByDesc('f.annee_scolaire')
            ->orderBy('f.semestre')
            ->orderBy('f.sigle')
            ->orderBy('f.id')
            ->get(['f.id', 'f.sigle', 'f.titre_cours', 'f.annee_scolaire', 'f.semestre', 'f.seances_prevues']);

        $courses = [];
        foreach ($sheets as $sheet) {
            $records = $this->records((int) $sheet->id, $studentId);

            $courses[] = [
                'sheet_id' => (int) $sheet->id,
                'course_code' => $sheet->sigle,
                'course_title' => $sheet->titre_cours,
                'school_year' => $sheet->annee_scolaire,
                'semester' => $sheet->semestre === null ? null : (int) $sheet->semestre,
                'semester_label' => AttendanceRules::semesterLabel($sheet->semestre),
                'summary' => AttendanceRules::summary($records, $sheet->seances_prevues),
                // Les séances les plus récentes d'abord (c'est ce qu'on cherche).
                'sessions' => array_map(fn (array $r) => [
                    'date' => $r['date'],
                    'time' => $r['time'],
                    'status' => $r['status'],
                    'minutes_late' => $r['minutes_late'],
                    'category' => AttendanceRules::category($r['status'], $r['minutes_late']),
                    'label' => AttendanceRules::recordLabel($r['status'], $r['minutes_late']),
                    'no_penalty' => $r['no_penalty'],
                    'justification' => $r['justification'],
                ], array_reverse($records)),
            ];
        }

        return [
            'courses' => $courses,
            'alerts' => $this->alerts($courses),
        ];
    }

    /**
     * Pointages d'un étudiant dans un registre, du plus ancien au plus récent, avec ce qu'il faut pour le bilan (§474 compris).
     *
     * @return list<array<string, mixed>>
     */
    private function records(int $sheetId, string $studentId): array
    {
        return DB::table('t_presence_ligne as l')
            ->join('t_presence_seance as s', 's.id', '=', 'l.seance_id')
            ->where('s.feuille_id', $sheetId)
            ->where('l.student_id', $studentId)
            ->orderBy('s.date_seance')
            ->orderBy('s.heure_prevue')
            ->orderBy('s.id')
            ->get(['l.statut', 'l.minutes_retard', 'l.justificatif', 's.date_seance', 's.heure_prevue', 's.arrivee_enseignant', 's.message_retard'])
            ->map(fn ($r) => [
                'status' => $r->statut,
                'minutes_late' => $r->minutes_retard === null ? null : (int) $r->minutes_retard,
                'justification' => $r->justificatif,
                'date' => $r->date_seance,
                'time' => $r->heure_prevue === null ? null : substr($r->heure_prevue, 0, 5),
                'no_penalty' => AttendanceRules::teacherLateWithoutPenalty($r->heure_prevue, $r->arrivee_enseignant, $r->message_retard),
            ])
            ->all();
    }

    /**
     * Un cours à la limite ou au-delà des 15 % : l'étudiant doit en être prévenu en haut de page.
     *
     * @param  list<array<string, mixed>>  $courses
     * @return list<array<string, mixed>>
     */
    private function alerts(array $courses): array
    {
        $alerts = [];
        foreach ($courses as $c) {
            if (in_array($c['summary']['state'], ['exceeded', 'limit'], true)) {
                $alerts[] = [
                    'sheet_id' => $c['sheet_id'],
                    'course_code' => $c['course_code'],
                    'state' => $c['summary']['state'],
                    'late_pending' => $c['summary']['late_pending'],
                ];
            }
        }

        return $alerts;
    }
}
