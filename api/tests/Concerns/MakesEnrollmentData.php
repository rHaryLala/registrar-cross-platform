<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/** Inscriptions, cours facturés et frais FICTIFS pour les tests des fiches d'inscription (base *_test uniquement). */
trait MakesEnrollmentData
{
    /** Une inscription de l'étudiant à une session (une seule ligne par appel). */
    protected function enroll(string $student, int $session, int $level = 1, string $mention = 'Informatique', array $extra = []): int
    {
        return DB::table('t_2024_inscription_session')->insertGetId(array_merge([
            'student_id' => $student, 'session_id' => $session, 'niveau_std' => $level, 'etude_mention' => $mention,
            'abonment_std' => 0, 'adresse_actuel_std' => '', 'annee_scolaire' => '', 'caisse_verification' => 0, 'conjoint_name' => '',
            'cours_selected' => 0, 'data_completion' => 0, 'data_verification' => 0, 'date_entry' => '2026-09-01', 'depot_list' => 0,
            'etat_civil_std' => '', 'graduated' => 0, 'mode_payement' => 0, 'nb_enfant' => 0, 'nbr_semester' => 0, 'new_student' => 0,
            'parcours_std' => '', 'remove' => 0, 'sponsor_address' => '', 'sponsor_contact' => '', 'sponsor_lastName' => '',
            'sponsor_name' => '', 'status' => '', 'test_niveau' => 0, 'verification_signatures' => 0,
        ], $extra));
    }

    /** Un cours du catalogue ; $lab = 1 pour un cours à laboratoire. */
    protected function catalogCourse(string $code, int $category = 1, int $lab = 0, float $labCost = 0): int
    {
        return DB::table('t_2023_cours')->insertGetId([
            'Sigle' => $code, 'title' => $code, 'category' => $category, 'lab' => $lab, 'cout_lab' => $labCost, 'cout' => 0, 'ajout' => 1, 'remove' => 0,
        ]);
    }

    /** Un cours facturé à l'étudiant pour la session. */
    protected function billedCourse(string $student, int $session, ?int $catalogId, string $code, int $credits, float $cost, array $extra = []): int
    {
        return DB::table('t_2024_cours_finance')->insertGetId(array_merge([
            'student_id' => $student, 'session_id' => $session, 'cours_id' => $catalogId ?? 0, 'cours_sigle' => $code, 'cours_title' => 'Cours '.$code,
            'cours_credit' => $credits, 'cours_cout' => $cost, 'lab_cout' => 0, 'remove' => 0, 'date_entry' => '2026-09-01',
            'last_change_datetime' => '2026-09-01', 'last_change_user_id' => 0,
        ], $extra));
    }

    /** La ligne financière de l'étudiant pour la session. */
    protected function finance(string $student, int $session, array $extra = []): int
    {
        return DB::table('t_2024_etudiant_finace')->insertGetId(array_merge([
            'student_id' => $student, 'session_id' => $session, 'mention' => '', 'level' => 1, 'status' => 'Externe',
            'cout_logement' => 0, 'cout_fraix_generaux' => 0, 'cout_fondDepot_dortoir' => 0, 'cout_abonment' => 0, 'cout_frais_graduation' => 0,
            'cout_totalCours' => 0, 'cout_totalLab' => 0, 'cout_costume' => 0, 'cout_voyage' => 0, 'mode_payement' => '', 'remove' => 0,
            'date_entry' => '2026-09-01', 'last_change_datetime' => '2026-09-01', 'last_change_user_id' => 0,
        ], $extra));
    }

    protected function extraFee(string $student, int $session, string $label, float $amount): void
    {
        // Clé unique (étudiant, session, frais_id) : un identifiant de frais distinct par ligne.
        static $feeId = 0;
        DB::table('t_2024_etudiant_frais_perso')->insert(['student_id' => $student, 'session_id' => $session, 'frais_id' => ++$feeId, 'libelle' => $label, 'montant' => $amount, 'remove' => 0]);
    }

    protected function dueDate(int $session, string $type, int $part, string $date): void
    {
        DB::table('t_paiement_echeance')->insert(['session_id' => $session, 'type_paiement' => $type, 'tranche' => $part, 'date_limite' => $date]);
    }

    /** Met à jour le dossier de l'étudiant (celui que makeStudent a créé). */
    protected function setStudentFile(string $student, array $fields): void
    {
        DB::table('tbl_2024_etudiant')->where('student_id', $student)->update($fields);
    }
}
