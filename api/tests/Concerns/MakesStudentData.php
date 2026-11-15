<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Données FICTIVES d'étudiants, de sessions et de notes pour les tests (base *_test uniquement). */
trait MakesStudentData
{
    protected function makeSession(string $name, string $year, int $semester, ?bool $published = null): int
    {
        $id = DB::table('t_2023_session')->insertGetId([
            'session_code' => $name.$year, 'session_name' => $name, 'session_year' => $year,
            'session_semester' => $semester, 'selected' => 0, 'date_entry' => '2026-01-01',
        ]);

        // null = aucune ligne (session masquée) ; false = ligne « non publiée » ; true = publiée.
        if ($published !== null) {
            DB::table('t_notes_publication')->insert(['session_id' => $id, 'publiee' => $published ? 1 : 0]);
        }

        return $id;
    }

    protected function makeStudent(string $id, string $nom = 'Rakoto', string $prenom = 'Fanja'): User
    {
        DB::table('tbl_2024_etudiant')->insert([
            'student_id' => $id, 'student_nom' => $nom, 'student_prenom' => $prenom,
            'password' => '', 'abonment' => 0, 'annee_scolaire' => '2025-2026', 'period' => 'S1', 'remove' => 0,
        ]);

        return User::factory()->create(['student_id' => $id]);
    }

    /** @param array<string, mixed> $extra */
    protected function makeNote(string $student, int $session, string $title, ?string $grade, int $credit = 3, array $extra = []): int
    {
        return DB::table('t_2023_notes')->insertGetId(array_merge([
            'student_id' => $student, 'session_id' => $session, 'Sigle' => strtoupper(substr($title, 0, 3)).' 101',
            'title_cours' => $title, 'grade' => $grade, 'credit' => $credit, 'cours_category' => 1, 'yearlevel' => 1,
            'ajout' => 1, 'annee_scolaire' => '2025-2026', 'remove' => 0, 'user_id_entry' => 'test',
        ], $extra));
    }

    /** @param array<string, mixed> $extra */
    protected function makePromotion(int|string $student, int $session, ?float $work, ?float $remark, ?float $chapel, array $extra = []): int
    {
        return DB::table('t_2023_promotion_notes')->insertGetId(array_merge([
            'student_id' => $student, 'session_id' => $session, 'annee_scolaire' => '2025-2026', 'remove' => 0,
            'grade_work_educ' => $work, 'grade_remark_acad' => $remark, 'grade_chapel_part' => $chapel,
        ], $extra));
    }

    protected function setRubric(string $key, bool $visible): void
    {
        DB::table('t_espace_etudiant_config')->updateOrInsert(['cle' => $key], ['valeur' => $visible ? '1' : '0']);
    }
}
