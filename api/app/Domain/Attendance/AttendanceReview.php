<?php

namespace App\Domain\Attendance;

use App\Domain\Teacher\TeacherAttendance;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assiduité, vue du registrariat (port de `src/presence.php` et `app/.presence/traiter.php`) : Bulletin académique, §472 à §476.
 *
 * Ce que le registraire doit savoir sans ouvrir chaque registre :
 *  1. les étudiants au-delà de 15 % d'absences dans un cours (§476), SIGNALÉS automatiquement au pointage ; il les marque « traités » ;
 *  2. les retards des enseignants (§474) : arrivée plus de 10 minutes après l'heure, avec ou sans message aux étudiants ;
 *  3. les registres dont la base des 15 % n'est pas indiquée : la règle ne peut pas s'y appliquer.
 *
 * Accès : superadmin et registrariat (niveau 3) — pas l'administration (niveau 2), comme l'ancienne page.
 */
final class AttendanceReview
{
    public function __construct(private readonly AuditTrail $audit) {}

    /** Ce compte peut-il ouvrir l'assiduité du registrariat ? */
    public static function allows(User $user): bool
    {
        return $user->isActive() && in_array($user->role(), [Role::Superadmin, Role::Registrar], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user, ?string $year, bool $withProcessed): array
    {
        $this->require($user);
        $year = $year !== null && $year !== '' ? trim($year) : TeacherAttendance::currentYear();
        $years = DB::table('t_presence_feuille')->distinct()->orderByDesc('annee_scolaire')->pluck('annee_scolaire')->map(fn ($y) => (string) $y)->all();
        if (! in_array($year, $years, true)) {
            array_unshift($years, $year);
        }

        return [
            'year' => $year, 'years' => $years,
            'pending_total' => (int) DB::table('t_presence_signalement')->whereNull('traite_le')->count(),
            'flags' => $this->flags($year, $withProcessed),
            'teacher_delays' => $delays = $this->delays($year),
            'delays_by_teacher' => $this->byTeacher($delays),
            'without_base' => $this->withoutBase($year),
            'wait_minutes' => AttendanceRules::TEACHER_WAIT,
        ];
    }

    /** Marque un signalement comme traité. Idempotent : un signalement déjà traité ne change pas. */
    public function process(User $user, int $flagId): array
    {
        $this->require($user);

        return DB::transaction(function () use ($user, $flagId) {
            $flag = DB::table('t_presence_signalement')->where('id', $flagId)->lockForUpdate()->first()
                ?? throw new ApiProblem(404, 'attendance.flag_not_found', 'Signalement introuvable', 'Ce signalement n’existe pas.');
            if ($flag->traite_le !== null) {
                return ['id' => $flagId, 'processed' => false, 'processed_at' => (string) $flag->traite_le];
            }
            $now = Carbon::now()->format('Y-m-d H:i:s');
            DB::table('t_presence_signalement')->where('id', $flagId)->update(['traite_le' => $now, 'traite_par' => $user->id]);
            $this->audit->update('t_presence_signalement', 'id', (array) $flag, ['traite_le' => $now, 'traite_par' => $user->id] + (array) $flag);

            return ['id' => $flagId, 'processed' => true, 'processed_at' => $now];
        });
    }

    // ------------------------------------------------------------------ privé

    private function require(User $user): void
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.registrar_only', 'Réservé au registrariat', 'L’assiduité est réservée au superadmin et au registrariat.');
        }
    }

    /** Les signalements, les non traités d'abord, avec le bilan À JOUR de chaque étudiant (une absence corrigée a pu le ramener sous le seuil). */
    private function flags(string $year, bool $withProcessed): array
    {
        $rows = DB::select(
            'SELECT g.id, g.feuille_id, g.student_id, g.absences, g.seances_prevues, g.signale_le, g.traite_le, f.cours_id, f.sigle, f.titre_cours, f.semestre,
                    (SELECT MAX(x.student_nom) FROM tbl_2024_etudiant x WHERE x.student_id = g.student_id) AS student_nom,
                    (SELECT MAX(x.student_prenom) FROM tbl_2024_etudiant x WHERE x.student_id = g.student_id) AS student_prenom,
                    TRIM(CONCAT_WS(" ", CONVERT(t.name USING utf8mb4) COLLATE utf8mb4_general_ci, CONVERT(t.lastName USING utf8mb4) COLLATE utf8mb4_general_ci)) AS enseignant
             FROM t_presence_signalement g JOIN t_presence_feuille f ON f.id = g.feuille_id
             LEFT JOIN t_2023_cours c ON c.id = f.cours_id LEFT JOIN teacher t ON t.uid = c.id_teacher
             WHERE f.annee_scolaire = ?'.($withProcessed ? '' : ' AND g.traite_le IS NULL').'
             ORDER BY g.traite_le IS NOT NULL, g.signale_le DESC, g.id DESC',
            [$year],
        );

        $planned = [];
        $out = [];
        foreach ($rows as $r) {
            $planned[$r->feuille_id] ??= DB::table('t_presence_feuille')->where('id', $r->feuille_id)->value('seances_prevues');
            $summary = AttendanceRules::summary($this->records((int) $r->feuille_id, (string) $r->student_id), $planned[$r->feuille_id]);
            $out[] = [
                'id' => (int) $r->id, 'student_id' => (string) $r->student_id, 'last_name' => $r->student_nom, 'first_name' => $r->student_prenom,
                'course_id' => (int) $r->cours_id, 'code' => $r->sigle, 'title' => $r->titre_cours, 'semester' => $r->semestre === null ? null : (int) $r->semestre,
                'semester_label' => AttendanceRules::semesterLabel($r->semestre), 'teacher' => $r->enseignant ?: null,
                'flagged_at' => $r->signale_le, 'processed_at' => $r->traite_le, 'counted_at_flag' => (int) $r->absences, 'planned_at_flag' => (int) $r->seances_prevues,
                'today' => $summary,
            ];
        }

        return $out;
    }

    /**
     * Pointages d'un étudiant dans un registre, avec ce qu'il faut au bilan (§474 compris).
     *
     * @return list<array{status: ?string, minutes_late: ?int, no_penalty: bool}>
     */
    private function records(int $sheetId, string $studentId): array
    {
        return DB::table('t_presence_ligne as l')->join('t_presence_seance as s', 's.id', '=', 'l.seance_id')
            ->where('s.feuille_id', $sheetId)->where('l.student_id', $studentId)->orderBy('s.date_seance')->orderBy('s.heure_prevue')->orderBy('s.id')
            ->get(['l.statut', 'l.minutes_retard', 's.heure_prevue', 's.arrivee_enseignant', 's.message_retard'])
            ->map(fn ($r) => [
                'status' => $r->statut, 'minutes_late' => $r->minutes_retard === null ? null : (int) $r->minutes_retard,
                'no_penalty' => AttendanceRules::teacherLateWithoutPenalty($r->heure_prevue, $r->arrivee_enseignant, $r->message_retard),
            ])->all();
    }

    /** Retards des enseignants (§474) : plus de 10 minutes après l'heure prévue. */
    private function delays(string $year): array
    {
        $rows = DB::select(
            'SELECT s.id, s.date_seance, s.heure_prevue, s.arrivee_enseignant, s.message_retard, f.cours_id, f.sigle, f.titre_cours,
                    TRIM(CONCAT_WS(" ", CONVERT(t.name USING utf8mb4) COLLATE utf8mb4_general_ci, CONVERT(t.lastName USING utf8mb4) COLLATE utf8mb4_general_ci)) AS enseignant
             FROM t_presence_seance s JOIN t_presence_feuille f ON f.id = s.feuille_id
             LEFT JOIN t_2023_cours c ON c.id = f.cours_id LEFT JOIN teacher t ON t.uid = c.id_teacher
             WHERE f.annee_scolaire = ? AND s.heure_prevue IS NOT NULL AND s.arrivee_enseignant IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, CONCAT(s.date_seance, " ", s.heure_prevue), CONCAT(s.date_seance, " ", s.arrivee_enseignant)) > ?
             ORDER BY s.date_seance DESC, s.heure_prevue DESC, s.id DESC',
            [$year, AttendanceRules::TEACHER_WAIT],
        );

        return array_map(fn ($r) => [
            'id' => (int) $r->id, 'date' => (string) $r->date_seance, 'planned_time' => substr((string) $r->heure_prevue, 0, 5), 'arrival' => substr((string) $r->arrivee_enseignant, 0, 5),
            'late_minutes' => AttendanceRules::teacherLateMinutes($r->heure_prevue, $r->arrivee_enseignant), 'warned' => (bool) $r->message_retard,
            'course_id' => (int) $r->cours_id, 'code' => $r->sigle, 'title' => $r->titre_cours, 'teacher' => $r->enseignant ?: null,
        ], $rows);
    }

    /** @param list<array<string, mixed>> $delays @return list<array{teacher: string, count: int}> le plus de retards d'abord */
    private function byTeacher(array $delays): array
    {
        $count = [];
        foreach ($delays as $d) {
            $key = $d['teacher'] ?: 'Enseignant non renseigné';
            $count[$key] = ($count[$key] ?? 0) + 1;
        }
        arsort($count);

        return array_map(fn ($name, $n) => ['teacher' => (string) $name, 'count' => $n], array_keys($count), array_values($count));
    }

    /** Registres tenus sans base des 15 % : la règle ne peut pas s'y appliquer. */
    private function withoutBase(string $year): array
    {
        return array_map(fn ($f) => [
            'sheet_id' => (int) $f->id, 'course_id' => (int) $f->cours_id, 'code' => $f->sigle, 'title' => $f->titre_cours,
            'semester' => $f->semestre === null ? null : (int) $f->semestre, 'semester_label' => AttendanceRules::semesterLabel($f->semestre),
            'teacher' => $f->enseignant ?: null, 'held_sessions' => (int) $f->nb_seances,
        ], DB::select(
            'SELECT f.id, f.cours_id, f.sigle, f.titre_cours, f.semestre,
                    TRIM(CONCAT_WS(" ", CONVERT(t.name USING utf8mb4) COLLATE utf8mb4_general_ci, CONVERT(t.lastName USING utf8mb4) COLLATE utf8mb4_general_ci)) AS enseignant,
                    (SELECT COUNT(*) FROM t_presence_seance s WHERE s.feuille_id = f.id) AS nb_seances
             FROM t_presence_feuille f LEFT JOIN t_2023_cours c ON c.id = f.cours_id LEFT JOIN teacher t ON t.uid = c.id_teacher
             WHERE f.annee_scolaire = ? AND f.seances_prevues IS NULL AND EXISTS (SELECT 1 FROM t_presence_seance s WHERE s.feuille_id = f.id)
             ORDER BY f.sigle',
            [$year],
        ));
    }
}
