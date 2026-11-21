<?php

namespace App\Domain\Student;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * « Mes inscriptions » et la fiche d'inscription d'une session, pour l'étudiant connecté seulement.
 *
 * Porté de src/student.inscriptions.php, src/student.fiche-inscription.php et src/extenssionPrint/fiche_inscription.php.
 * Le matricule vient toujours du compte, jamais de la requête ; la session demandée doit être l'une de SES inscriptions,
 * et doit être « visible » (années récentes seulement, décision du registrariat) : sinon la fiche n'existe pas (404).
 *
 * Écarts assumés avec l'ancienne fiche (voir docs/PLAN-PAGES.md) :
 *  - le niveau est celui de l'inscription, pas le niveau actuel du dossier ;
 *  - la ligne « Frais costume » s'affiche dès que le montant est compté dans le total (avant : comptée sans être montrée) ;
 *  - un cours dont le catalogue a disparu s'écrit « — » (avant : « Général ») ;
 *  - parmi des inscriptions en double pour une même session, la plus récente (avant : au hasard).
 */
final class Enrollments
{
    public function __construct(private readonly StudentPhoto $photos) {}

    /** Rubrique « fiches_inscription » : visible par défaut, masquable par le registrariat. */
    public function visible(): bool
    {
        $value = DB::table('t_espace_etudiant_config')->where('cle', 'fiches_inscription')->value('valeur');

        return $value === null ? true : $value === '1';
    }

    /**
     * @return array{visible: bool, sheets_from: array<string, int>, years: list<array<string, mixed>>}
     */
    public function list(string $studentId): array
    {
        if (! $this->visible()) {
            return ['visible' => false, 'sheets_from' => EnrollmentRules::SHEETS_FROM, 'years' => []];
        }

        $studentLevel = DB::table('tbl_2024_etudiant')->where('student_id', $studentId)->orderBy('remove')->orderByDesc('annee_scolaire')->value('annee_etude');

        $rows = $this->enrollmentRows($studentId)->filter(
            fn ($r) => EnrollmentRules::sheetVisible((string) $r->session_year, $r->niveau_std, $studentLevel)
        );

        $years = [];
        foreach ($rows as $r) {
            $level = EnrollmentRules::level($r->niveau_std, $studentLevel);
            $years[$r->session_year][] = [
                'session_id' => (int) $r->session_id,
                'session_name' => $r->session_name,
                'level_label' => $level === null ? null : EnrollmentRules::levels()[$level]['label'],
                'mention' => $r->etude_mention ?: null,
                'enrolled_on' => $this->date($r->date_entry),
                'courses' => (int) $r->nb_cours,
                'has_fees' => $this->financeRow($studentId, (int) $r->session_id) !== null,
            ];
        }

        return [
            'visible' => true,
            'sheets_from' => EnrollmentRules::SHEETS_FROM,
            'years' => collect($years)->map(fn ($sessions, $year) => ['year' => (string) $year, 'sessions' => $sessions])->values()->all(),
        ];
    }

    /**
     * La fiche d'une session, ou null si elle n'existe pas POUR CET ÉTUDIANT (autre étudiant, session inconnue, trop ancienne,
     * rubrique masquée) : l'appelant répond 404 dans tous ces cas, sans distinguer.
     *
     * @return array<string, mixed>|null
     */
    public function sheet(string $studentId, int $sessionId): ?array
    {
        if (! $this->visible()) {
            return null;
        }

        return $this->build($studentId, $sessionId, true);
    }

    /**
     * La même fiche, demandée PAR LE PERSONNEL (registrariat, comptabilité) : toute inscription de l'étudiant, y compris les
     * années que l'étudiant ne voit pas encore, et même si la rubrique est masquée dans son espace. Aucun droit n'est vérifié ici :
     * l'appelant (App\Domain\Documents\StaffEnrollmentSheets) le fait.
     *
     * @return array<string, mixed>|null
     */
    public function sheetForStaff(string $studentId, int $sessionId): ?array
    {
        return $this->build($studentId, $sessionId, false);
    }

    /**
     * Les sessions où l'étudiant est inscrit, de la plus récente à la plus ancienne, sans la règle de visibilité de l'espace étudiant.
     *
     * @return list<array<string, mixed>>
     */
    public function sessionsForStaff(string $studentId): array
    {
        $studentLevel = DB::table('tbl_2024_etudiant')->where('student_id', $studentId)->orderBy('remove')->orderByDesc('annee_scolaire')->value('annee_etude');

        return $this->enrollmentRows($studentId)->map(function ($r) use ($studentId, $studentLevel) {
            $level = EnrollmentRules::level($r->niveau_std, $studentLevel);

            return [
                'session_id' => (int) $r->session_id,
                'session_name' => $r->session_name,
                'year' => $r->session_year,
                'level_label' => $level === null ? null : EnrollmentRules::levels()[$level]['label'],
                'mention' => $r->etude_mention ?: null,
                'enrolled_on' => $this->date($r->date_entry),
                'courses' => (int) $r->nb_cours,
                'has_fees' => $this->financeRow($studentId, (int) $r->session_id) !== null,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function build(string $studentId, int $sessionId, bool $studentRules): ?array
    {
        $student = DB::table('tbl_2024_etudiant')->where('student_id', $studentId)->orderBy('remove')->orderByDesc('annee_scolaire')->first();
        $enrollment = $this->enrollmentRows($studentId)->first(fn ($r) => (int) $r->session_id === $sessionId);
        if ($student === null || $enrollment === null
            || ($studentRules && ! EnrollmentRules::sheetVisible((string) $enrollment->session_year, $enrollment->niveau_std, $student->annee_etude))) {
            return null;
        }

        $session = DB::table('t_2023_session')->where('session_id', $sessionId)->first();
        $level = EnrollmentRules::level($enrollment->niveau_std, $student->annee_etude);
        $master = EnrollmentRules::isMaster($level);
        $courses = $this->courses($studentId, $sessionId);
        $fees = $this->fees($studentId, $sessionId, $student, $session, $level, $master, $courses['totals']);

        return [
            'session' => ['id' => $sessionId, 'name' => $session->session_name, 'year' => $session->session_year, 'semester' => (int) $session->session_semester],
            'filename' => EnrollmentRules::pdfFilename($studentId, (string) $session->session_name, (string) $session->session_year),
            'student' => [
                'student_id' => $studentId,
                'name' => trim(mb_strtoupper((string) $student->student_nom).' '.$student->student_prenom),
                'mention' => $student->etude_envisage ?: null,
                'track' => $student->etude_option ?: null,
                'level_label' => $level === null ? null : EnrollmentRules::levels()[$level]['label'],
                'email' => $student->student_email ?: null,
                'phone' => $student->student_tel ?: null,
                'address' => $student->student_adresse ?: null,
                'residence' => $master ? null : ($student->status ?: null),
                'cafeteria' => $master ? null : ((int) $student->abonment === 1),
                'sponsor' => trim((string) $student->sponsor_nom) !== '' ? trim($student->sponsor_nom.' '.$student->sponsor_prenom) : null,
                'has_photo' => $this->photos->exists($studentId),
            ],
            'is_master' => $master,
            'courses' => $courses['rows'],
            'course_totals' => $courses['totals'],
            'fees' => $fees,
            'work_education' => $master ? null : $this->workEducation($studentId, $sessionId),
            'issued_on' => Carbon::now()->toDateString(),
        ];
    }

    // ------------------------------------------------------------------------------------------------------------

    /** @return \Illuminate\Support\Collection<int, object> inscriptions actives, une par session (la plus récente), de la plus récente session à la plus ancienne */
    private function enrollmentRows(string $studentId)
    {
        return DB::table('t_2024_inscription_session as ins')
            ->join('t_2023_session as s', 's.session_id', '=', 'ins.session_id')
            ->where('ins.student_id', $studentId)
            ->whereRaw('COALESCE(ins.remove, 0) = 0')
            ->whereRaw('ins.id = (SELECT MAX(i2.id) FROM t_2024_inscription_session i2 WHERE i2.student_id = ins.student_id AND i2.session_id = ins.session_id AND COALESCE(i2.remove, 0) = 0)')
            ->orderByDesc('s.session_year')->orderByDesc('s.session_id')
            ->selectRaw('ins.session_id, ins.niveau_std, ins.etude_mention, ins.date_entry, s.session_name, s.session_year,
                (SELECT COUNT(*) FROM t_2024_cours_finance cf WHERE cf.student_id = ins.student_id AND cf.session_id = ins.session_id AND cf.remove != 1) AS nb_cours')
            ->get();
    }

    /**
     * Ligne financière d'un couple (étudiant, session) : t_2024_etudiant_finace n'a pas d'index unique et 133 couples sont
     * en double. Une ligne qui porte un mode de paiement passe devant une ligne vide ; à égalité, la plus récente.
     */
    private function financeRow(string $studentId, int $sessionId): ?object
    {
        return DB::table('t_2024_etudiant_finace')
            ->where('student_id', $studentId)->where('session_id', $sessionId)->where('remove', '!=', 1)
            ->orderByRaw('(mode_payement IS NOT NULL AND mode_payement <> \'\') DESC')->orderByDesc('id')
            ->first();
    }

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>} */
    private function courses(string $studentId, int $sessionId): array
    {
        $labs = 0;
        $labTotal = 0.0;
        $costTotal = 0.0;
        $creditTotal = 0.0;
        $rows = [];

        $lines = DB::table('t_2024_cours_finance as cf')
            ->leftJoin('t_2023_cours as c', 'c.id', '=', 'cf.cours_id')
            ->where('cf.student_id', $studentId)->where('cf.session_id', $sessionId)->where('cf.remove', '!=', 1)
            ->orderBy('cf.id')
            ->get(['cf.cours_sigle', 'cf.cours_title', 'cf.cours_credit', 'cf.cours_cout', 'c.id as catalog_id', 'c.category', 'c.lab', 'c.cout_lab']);

        foreach ($lines as $l) {
            $lab = null;
            // Laboratoire : seuls les DEUX premiers cours à laboratoire sont facturés, les suivants sont offerts (barrés).
            if ($l->catalog_id !== null && (int) $l->lab !== 0) {
                $labs++;
                $counted = $labs <= 2;
                $lab = ['cost' => (int) round((float) $l->cout_lab), 'counted' => $counted];
                if ($counted) {
                    $labTotal += (float) $l->cout_lab;
                }
            }
            $rows[] = [
                'code' => $l->cours_sigle,
                'title' => $l->cours_title,
                'credits' => (float) $l->cours_credit,
                'category' => EnrollmentRules::courseCategory($l->catalog_id === null ? null : $l->category),
                'cost' => (int) round((float) $l->cours_cout),
                'lab' => $lab,
            ];
            $costTotal += (float) $l->cours_cout;
            $creditTotal += (float) $l->cours_credit;
        }

        return ['rows' => $rows, 'totals' => [
            'count' => count($rows), 'credits' => $creditTotal, 'cost' => $costTotal, 'lab' => $labTotal,
        ]];
    }

    /**
     * @param  array<string, int|float>  $courseTotals
     * @return array<string, mixed>|null null = aucune ligne financière : la fiche ne vaut pas justificatif de paiement
     */
    private function fees(string $studentId, int $sessionId, object $student, object $session, ?int $level, bool $master, array $courseTotals): ?array
    {
        $fin = $this->financeRow($studentId, $sessionId);
        if ($fin === null) {
            return null;
        }

        $theology = $student->etude_envisage === 'Théologie';
        $extra = DB::table('t_2024_etudiant_frais_perso as p')->leftJoin('t_frais_perso as f', 'f.id', '=', 'p.frais_id')
            ->where('p.student_id', $studentId)->where('p.session_id', $sessionId)->where('p.remove', '!=', 1)
            ->orderByRaw('COALESCE(f.ordre, 0)')->orderBy('p.libelle')->get(['p.libelle', 'p.montant']);
        $extraTotal = (float) $extra->sum(fn ($e) => (float) $e->montant);

        $general = (float) $fin->cout_fraix_generaux;
        $costume = (float) $fin->cout_costume;
        $voyage = (float) $fin->cout_voyage;
        $graduation = EnrollmentRules::graduationFee($fin->cout_frais_graduation, $session->session_semester, $student->graduated);

        $diversRows = [['label' => 'Frais généraux', 'amount' => (int) round($general)]];
        // Le costume entre dans le total quel que soit l'étudiant : on le montre dès qu'il est compté (l'ancienne fiche le cachait).
        if (($theology && $level === 1) || $costume != 0.0) {
            $diversRows[] = ['label' => 'Frais costume', 'amount' => (int) round($costume)];
        }
        $diversRows[] = ['label' => $theology ? 'Colloque' : 'Voyage d’étude', 'amount' => (int) round($voyage)];
        foreach ($extra as $e) {
            $diversRows[] = ['label' => (string) $e->libelle, 'amount' => (int) round((float) $e->montant)];
        }

        // Montant « sans frais généraux » : le fonds de dépôt est dans les deux membres de la soustraction, il s'annule.
        $tuitionAmounts = [
            ['label' => 'Total des cours', 'amount' => (float) $courseTotals['cost']],
            ['label' => 'Total de laboratoire', 'amount' => (float) $courseTotals['lab']],
            ['label' => 'Frais de logement', 'amount' => (float) $fin->cout_logement],
            ['label' => 'Frais de cafétéria', 'amount' => (float) $fin->cout_abonment],
            ['label' => 'Frais de graduation', 'amount' => $graduation],
        ];
        $tuition = (float) array_sum(array_column($tuitionAmounts, 'amount'));
        $showTuition = ! $master || $tuition != 0.0;

        $mode = (string) $fin->mode_payement;
        $type = EnrollmentRules::paymentTypes()[$mode] ?? null;
        $dates = $type ? $this->paymentDates($sessionId)[$mode] ?? [] : [];

        return [
            'general_rows' => $diversRows,
            'tuition' => $showTuition ? [
                // Master : les lignes à 0 se taisent.
                'rows' => array_values(array_map(
                    fn ($r) => ['label' => $r['label'], 'amount' => (int) round($r['amount'])],
                    array_filter($tuitionAmounts, fn ($r) => ! $master || $r['amount'] != 0.0)
                )),
                'subtotal' => (int) round($tuition),
            ] : null,
            'payment' => $showTuition ? [
                'mode' => $mode !== '' ? $mode : null,
                'mode_label' => $type['label'] ?? null,
                'installments' => $type ? array_map(
                    fn ($part, $i) => [
                        'percent' => $part,
                        'amount' => (int) round($tuition * $part / 100),
                        'due_date' => $dates[$i + 1] ?? null,
                        'due_label' => EnrollmentRules::dateLabel($dates[$i + 1] ?? null),
                    ],
                    $type['parts'], array_keys($type['parts'])
                ) : [],
            ] : null,
            'grand_total' => (int) round($general + $costume + $voyage + $extraTotal + $tuition),
            'grand_total_includes_tuition' => $showTuition,
        ];
    }

    /** @return array<string, array<int, string>> ['B' => [1 => '2026-04-30', 2 => '2026-07-10']] */
    private function paymentDates(int $sessionId): array
    {
        $dates = [];
        foreach (DB::table('t_paiement_echeance')->where('session_id', $sessionId)->orderBy('type_paiement')->orderBy('tranche')->get(['type_paiement', 'tranche', 'date_limite']) as $d) {
            $dates[$d->type_paiement][(int) $d->tranche] = $d->date_limite;
        }

        return $dates;
    }

    private function workEducation(string $studentId, int $sessionId): ?string
    {
        $task = DB::table('t_work_education')->where('session_id', $sessionId)->where('student_id', $studentId)->orderBy('id')->value('tache');

        return $task !== null && trim((string) $task) !== '' ? (string) $task : null;
    }

    private function date(?string $value): ?string
    {
        $t = $value ? strtotime($value) : false;

        return $t && $t > 0 ? date('Y-m-d', $t) : null;
    }
}
