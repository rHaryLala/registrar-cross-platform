<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/** Registres de présence FICTIFS pour les tests (base *_test uniquement). */
trait MakesAttendanceData
{
    private int $sheetCounter = 0;

    protected function makeSheet(string $code, string $title, ?int $planned, string $year = '2026 - 2027', ?int $semester = 1): int
    {
        return DB::table('t_presence_feuille')->insertGetId([
            'cours_id' => 900000 + (++$this->sheetCounter), 'sigle' => $code, 'titre_cours' => $title,
            'annee_scolaire' => $year, 'semestre' => $semester, 'seances_prevues' => $planned,
        ]);
    }

    protected function makeMeeting(int $sheet, string $date, ?string $time = '08:00:00', ?string $teacherArrival = null, bool $warned = false): int
    {
        return DB::table('t_presence_seance')->insertGetId([
            'feuille_id' => $sheet, 'date_seance' => $date, 'heure_prevue' => $time,
            'arrivee_enseignant' => $teacherArrival, 'message_retard' => $warned ? 1 : 0,
        ]);
    }

    protected function makeLine(int $meeting, string $student, ?string $status, ?int $minutes = null, ?string $justification = null): void
    {
        DB::table('t_presence_ligne')->insert([
            'seance_id' => $meeting, 'student_id' => $student, 'statut' => $status,
            'minutes_retard' => $minutes, 'justificatif' => $justification,
        ]);
    }
}
