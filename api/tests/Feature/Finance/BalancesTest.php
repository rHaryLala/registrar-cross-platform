<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Balances;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesEnrollmentData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Solde de compte par session : accès, liste, rapprochement matricule ↔ ligne finance, solde enregistré ou calculé, filtres, correction du solde. */
class BalancesTest extends TestCase
{
    use MakesEnrollmentData;
    use MakesStudentData;

    private const BASE = '/api/v1/staff/finance/balances';

    private int $session;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tbl_2024_etudiant')->delete();
        DB::table('t_2023_session')->update(['selected' => 0]);
        $this->session = $this->makeSession('Premier semestre', '2026 - 2027', 1);
    }

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function as(int $level, string $type): User
    {
        return User::factory()->staff($level, $type)->create();
    }

    private function registrar(): User
    {
        return $this->as(3, 'registrar');
    }

    private function student(string $id, string $last, string $first, array $over = [], ?int $session = null, bool $enrol = true): void
    {
        DB::table('tbl_2024_etudiant')->insert(array_merge([
            'student_id' => $id, 'student_nom' => $last, 'student_prenom' => $first, 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2026-2027', 'period' => 'S1', 'remove' => 0,
        ], $over));
        if ($enrol) {
            $this->enroll($id, $session ?? $this->session);
        }
    }

    private function finance(int $id, ?string $balance, array $over = [], ?int $session = null): void
    {
        DB::table('finance')->insert(array_merge(['student_id' => $id, 'session_id' => $session ?? $this->session, 'total_payment' => '0', 'balance_amount' => $balance], $over));
    }

    private function list(string $qs = '', ?User $who = null)
    {
        return ($who ? $this->api($who) : $this->api($this->registrar()))->getJson(self::BASE.$qs);
    }

    // ------------------------------------------------------------------ accès

    public function test_the_page_is_open_to_levels_1_to_4_only(): void
    {
        foreach ([$this->as(1, 'superadmin'), $this->as(2, 'admin'), $this->registrar(), $this->as(4, 'comptabilite')] as $who) {
            $this->list('', $who)->assertOk();
        }
        $denied = [$this->as(5, 'media'), $this->as(6, 'chef_mention'), $this->as(7, 'teacher'), User::factory()->create(), $this->as(9, 'work_education'),
            User::factory()->staff(3, 'registrar')->inactive()->create()];
        foreach ($denied as $who) {
            $this->list('', $who)->assertForbidden()->assertJsonPath('code', 'forbidden.balances');
        }
    }

    public function test_anonymous_gets_401_and_the_session_payload_announces_the_page(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->putJson(self::BASE.'/1/1', ['balance' => 1])->assertUnauthorized();
        $this->api($this->registrar())->getJson('/api/v1/auth/me')->assertJsonPath('data.finance.balances', true);
        $this->api($this->as(2, 'admin'))->getJson('/api/v1/auth/me')->assertJsonPath('data.finance', ['balances' => true, 'deadlines' => true]);
        $this->api($this->as(4, 'comptabilite'))->getJson('/api/v1/auth/me')->assertJsonPath('data.finance.balances', true);
        $this->api($this->as(5, 'media'))->getJson('/api/v1/auth/me')->assertJsonPath('data.finance', null);
    }

    // ------------------------------------------------------------------ liste

    public function test_only_students_enrolled_and_in_good_standing_are_listed_in_matricule_order(): void
    {
        $this->student('30001', 'Rakoto', 'Jean', ['account_code' => 'AC1']);
        $this->student('30003', 'Andria', 'Zo');
        $this->student('30002', 'Suspendu', 'Un', ['suspended' => 1]);
        $this->student('30004', 'Parti', 'Deux', ['retrait_universite' => 1]);
        $this->student('30005', 'Retire', 'Trois', ['remove' => 1]);
        $this->student('30006', 'AutreSession', 'Quatre', [], $this->makeSession('Deuxième semestre', '2026 - 2027', 2));
        $this->student('30007', 'NonInscrit', 'Cinq', [], null, false);
        $this->enroll('30001', $this->session);   // inscrit deux fois : une seule ligne

        $r = $this->list('?session='.$this->session)->assertOk()->json('data');

        $this->assertSame(['30003', '30001'], array_column($r['rows'], 'student_id'));
        $this->assertSame('ANDRIA Zo', $r['rows'][0]['name']);
        $this->assertSame(['AC1', ''], [$r['rows'][1]['account_code'], $r['rows'][0]['account_code']]);
        $this->assertSame(2, $r['total']);
        $this->assertSame(2, $r['students_in_session']);
    }

    public function test_the_stored_balance_wins_and_otherwise_total_minus_payments_is_used(): void
    {
        $this->student('40001', 'Stocke', 'A');
        $this->student('40002', 'Calcule', 'B');
        $this->student('40003', 'Illisible', 'C');
        $this->student('40004', 'Credit', 'D');
        $this->finance(40001, '150000.50', ['total_payment' => '999 999']);   // le solde enregistré l'emporte
        $this->finance(40002, null, ['total_payment' => '1 200 000', 'amount_pay_1' => '500 000', 'amount_pay_2' => "250\u{00A0}000,50", 'amount_pay_3' => '', 'amount_pay_4' => 'n/a', 'amount_pay_5' => '49 999,50']);   // 1 200 000 − 800 000
        $this->finance(40003, null, ['total_payment' => 'beaucoup']);   // illisible = 0
        $this->finance(40004, '-25000.00');

        $rows = collect($this->list()->json('data.rows'))->keyBy('student_id');

        $this->assertEqualsWithDelta(150000.5, $rows['40001']['balance'], 0.001);
        $this->assertEqualsWithDelta(400000.0, $rows['40002']['balance'], 0.001, 'les cinq versements comptent');
        $this->assertSame(0.0, (float) $rows['40003']['balance']);
        $this->assertEqualsWithDelta(-25000.0, $rows['40004']['balance'], 0.001);
        $this->assertSame(['debitaire', 'debitaire', 'solde', 'creditaire'], [$rows['40001']['state'], $rows['40002']['state'], $rows['40003']['state'], $rows['40004']['state']]);
    }

    public function test_a_matricule_is_matched_to_its_finance_row_by_itself_then_by_its_numeric_part(): void
    {
        $this->student('20481MG', 'Suffixe', 'A');
        $this->student('MG20482', 'Prefixe', 'B');
        $this->student('20483', 'Exact', 'C');
        $this->student('99999', 'Sans', 'D');
        $this->finance(20481, '100');
        $this->finance(20482, '200');
        $this->finance(20483, '300');

        $rows = collect($this->list()->json('data.rows'))->keyBy('student_id');

        $this->assertSame([100.0, 200.0, 300.0, 0.0], [(float) $rows['20481MG']['balance'], (float) $rows['MG20482']['balance'], (float) $rows['20483']['balance'], (float) $rows['99999']['balance']]);
        $this->assertSame(['20481', '20482', '20483', null], [$rows['20481MG']['finance_id'], $rows['MG20482']['finance_id'], $rows['20483']['finance_id'], $rows['99999']['finance_id']]);
        $this->assertSame([true, true, true, false], [$rows['20481MG']['editable'], $rows['MG20482']['editable'], $rows['20483']['editable'], $rows['99999']['editable']]);
        $this->assertSame('sans_ligne', $rows['99999']['state'], 'pas de ligne finance : annoncé, pas « soldé »');
    }

    public function test_a_finance_row_of_another_session_is_not_used(): void
    {
        $other = $this->makeSession('Deuxième semestre', '2026 - 2027', 2);
        $this->student('50001', 'Autre', 'A');
        $this->finance(50001, '777', [], $other);

        $row = $this->list('?session='.$this->session)->json('data.rows.0');
        $this->assertSame([0.0, 'sans_ligne'], [(float) $row['balance'], $row['state']]);
    }

    public function test_the_session_defaults_to_the_active_one_then_to_the_latest(): void
    {
        $older = $this->makeSession('Premier semestre', '2025 - 2026', 1);
        $this->student('60001', 'Ancien', 'A', [], $older);
        $this->student('60002', 'Nouveau', 'B');

        // aucune session active : la plus récente (année, semestre, identifiant décroissants)
        $r = $this->list()->json('data');
        $this->assertSame($this->session, $r['session_id']);
        $this->assertSame('Premier semestre (2026 - 2027 - Semestre 1)', $r['sessions'][0]['label']);
        $this->assertSame(['60002'], array_column($r['rows'], 'student_id'));

        DB::table('t_2023_session')->where('session_id', $older)->update(['selected' => 1]);
        $this->assertSame($older, $this->list()->json('data.session_id'), 'la session active passe avant la plus récente');
        $this->assertSame($this->session, $this->list('?session='.$this->session)->json('data.session_id'), 'une session demandée l’emporte');
        $this->assertSame($this->session, $this->list('?session=99999999')->json('data.session_id'), 'session inconnue : la plus récente, pas la session active (comme l’ancienne page)');
        $this->assertSame(['60001'], array_column($this->list()->json('data.rows'), 'student_id'));
    }

    public function test_filters_search_sort_totals_and_counts(): void
    {
        $this->student('70001', 'Dette', 'Grosse', ['account_code' => 'ZZ9']);
        $this->student('70002', 'Dette', 'Petite', ['account_code' => 'AA1']);
        $this->student('70003', 'Avance', 'Yves');
        $this->student('70004', 'Quitte', 'Zo');
        $this->student('70005', 'Éloïse', 'Marie');
        $this->finance(70001, '900000');
        $this->finance(70002, '100000');
        $this->finance(70003, '-40000');
        $this->finance(70004, '0');

        $r = $this->list()->json('data');
        $this->assertSame(['debitaire' => 2, 'creditaire' => 1, 'solde' => 1, 'sans_ligne' => 1], $r['counts']);
        $this->assertSame(['debit' => 1000000.0, 'credit' => 40000.0, 'net' => 960000.0], array_map('floatval', $r['totals']));

        $d = $this->list('?etat[]=debitaire')->json('data');
        $this->assertSame(['70002', '70001'], array_column($d['rows'], 'student_id'));
        $this->assertSame(['debit' => 1000000.0, 'credit' => 0.0], ['debit' => (float) $d['totals']['debit'], 'credit' => (float) $d['totals']['credit']]);
        $this->assertSame(['debitaire' => 2, 'creditaire' => 1, 'solde' => 1, 'sans_ligne' => 1], $d['counts'], 'les compteurs ne dépendent pas du filtre d’état');
        $this->assertSame(3, $this->list('?etat[]=debitaire&etat[]=creditaire')->json('data.total'), 'plusieurs états : OU');
        $this->assertSame(5, $this->list('?etat[]=nimporte')->json('data.total'), 'un état inconnu est ignoré');

        $this->assertSame(['70005'], array_column($this->list('?q=eloise')->json('data.rows'), 'student_id'), 'accents et casse ignorés');
        $this->assertSame(['70002'], array_column($this->list('?q=aa1')->json('data.rows'), 'student_id'), 'le code de compte se cherche');
        $this->assertSame(['70001'], array_column($this->list('?q=dette+grosse')->json('data.rows'), 'student_id'), 'chaque mot doit se retrouver');
        $this->assertSame(['debitaire' => 2], array_filter($this->list('?q=dette')->json('data.counts')), 'les compteurs suivent la recherche');

        $this->assertSame(['70001', '70002', '70005', '70004', '70003'], array_column($this->list('?tri=solde')->json('data.rows'), 'student_id'), 'dette la plus forte d’abord ; à égalité, l’ordre des matricules décroissants');
        $this->assertSame(['70003', '70005', '70004', '70002', '70001'], array_column($this->list('?tri=solde&sens=asc')->json('data.rows'), 'student_id'));
        $this->assertSame(['70003', '70001', '70002', '70005', '70004'], array_column($this->list('?tri=nom')->json('data.rows'), 'student_id'), 'Avance, Dette Grosse, Dette Petite, Éloïse, Quitte (accents ignorés)');
        $this->assertSame(['70001', '70002', '70003', '70004', '70005'], array_column($this->list('?sens=asc')->json('data.rows'), 'student_id'), 'matricule croissant');
    }

    public function test_the_list_is_paged_by_200(): void
    {
        for ($i = 0; $i < 205; $i++) {
            $this->student(sprintf('8%04d', $i), 'N'.$i, 'p');
        }
        $a = $this->list()->json('data');
        $this->assertSame([205, 200, 5], [$a['total'], count($a['rows']), $a['remaining']]);
        $b = $this->list('?page=1')->json('data');
        $this->assertSame([5, 0], [count($b['rows']), $b['remaining']]);
        $this->assertSame('80004', $b['rows'][0]['student_id']);
    }

    // ------------------------------------------------------------------ écriture

    public function test_the_balance_is_corrected_and_stamped_with_who_and_when(): void
    {
        $this->student('90001', 'Rakoto', 'Jean');
        $this->finance(90001, '100000.00', ['last_change_user_id' => '7']);
        $reg = $this->registrar();
        Carbon::setTestNow('2026-11-17 08:30:15');

        $r = $this->api($reg)->putJson(self::BASE."/{$this->session}/90001", ['balance' => '-25 000,50'])->assertOk()->json('data');

        $this->assertTrue($r['changed']);
        $this->assertEqualsWithDelta(-25000.5, $r['row']['balance'], 0.001);
        $this->assertSame('creditaire', $r['row']['state']);
        $f = DB::table('finance')->where('student_id', 90001)->first();
        $this->assertSame(['-25000.50', (string) $reg->id, '2026-11-17 08:30:15'], [$f->balance_amount, $f->last_change_user_id, $f->last_change_datetime]);

        $audit = DB::table('t_audit_modification')->where('table_nom', 'finance')->orderByDesc('id')->first();
        $this->assertSame('UPDATE', $audit->operation);
        $changes = json_decode($audit->changements, true);
        $this->assertSame(['100000.00', '-25000.50'], [$changes['lignes'][0]['avant']['balance_amount'], $changes['lignes'][0]['apres']['balance_amount']]);
        $this->assertSame(1, DB::table('t_audit_requete')->where('libelle', 'Finance · solde de compte')->count());
    }

    public function test_numbers_are_accepted_as_json_numbers_or_french_text(): void
    {
        $this->student('90002', 'A', 'a');
        $this->finance(90002, '0');
        $reg = $this->registrar();
        foreach ([[1500, '1500.00'], ['2 500,5', '2500.50'], ["3\u{00A0}000", '3000.00'], ['  42 ', '42.00'], [0, '0.00'], ['-0,01', '-0.01'], [1.005, '1.01']] as [$in, $out]) {
            $this->api($reg)->putJson(self::BASE."/{$this->session}/90002", ['balance' => $in])->assertOk();
            $this->assertSame($out, DB::table('finance')->where('student_id', 90002)->value('balance_amount'), json_encode($in));
        }
    }

    public function test_saving_the_same_value_is_not_an_error_and_changes_nothing(): void
    {
        $this->student('90003', 'A', 'a');
        $this->finance(90003, '500.00', ['last_change_user_id' => '3', 'last_change_datetime' => '2026-01-01 00:00:00']);

        $r = $this->api($this->registrar())->putJson(self::BASE."/{$this->session}/90003", ['balance' => '500'])->assertOk()->json('data');

        $this->assertFalse($r['changed']);
        $f = DB::table('finance')->where('student_id', 90003)->first();
        $this->assertSame(['3', '2026-01-01 00:00:00'], [$f->last_change_user_id, $f->last_change_datetime]);
        $this->assertSame(0, DB::table('t_audit_requete')->where('libelle', 'Finance · solde de compte')->count());
    }

    public function test_the_cashier_can_correct_a_balance_too(): void
    {
        $this->student('90009', 'A', 'a');
        $this->finance(90009, '10');
        $cashier = $this->as(4, 'comptabilite');
        $this->api($cashier)->putJson(self::BASE."/{$this->session}/90009", ['balance' => 99])->assertOk();
        $this->assertSame(['99.00', (string) $cashier->id], [DB::table('finance')->where('student_id', 90009)->value('balance_amount'), DB::table('finance')->where('student_id', 90009)->value('last_change_user_id')]);
    }

    public function test_a_null_stored_balance_can_be_set_to_zero(): void
    {
        $this->student('90004', 'A', 'a');
        $this->finance(90004, null, ['total_payment' => '100', 'amount_pay_1' => '100']);
        $this->assertTrue($this->api($this->registrar())->putJson(self::BASE."/{$this->session}/90004", ['balance' => 0])->json('data.changed'));
        $this->assertSame('0.00', DB::table('finance')->where('student_id', 90004)->value('balance_amount'));
    }

    public function test_invalid_amounts_and_unknown_rows_are_refused_without_writing(): void
    {
        $this->student('90005', 'A', 'a');
        $this->finance(90005, '10.00');
        $reg = $this->registrar();
        $put = fn ($balance, $student = '90005', $session = null) => $this->api($reg)->putJson(self::BASE.'/'.($session ?? $this->session).'/'.$student, ['balance' => $balance]);

        foreach (['abc', '', '1e999', '12 34 abc', '99999999999999.99', '-99999999999999', null] as $bad) {
            $res = $put($bad);
            $this->assertContains($res->status(), [422], json_encode($bad));
        }
        $this->api($reg)->putJson(self::BASE."/{$this->session}/90005", [])->assertUnprocessable();
        $put(5, '12345678')->assertNotFound()->assertJsonPath('code', 'not_found.finance_row');
        $put(5, '90005', 999999)->assertNotFound();
        $this->api($reg)->putJson(self::BASE."/{$this->session}/abc", ['balance' => 1])->assertNotFound();   // l'adresse ne correspond pas
        $this->assertSame('10.00', DB::table('finance')->where('student_id', 90005)->value('balance_amount'));
        $this->assertSame(0, DB::table('t_audit_requete')->where('libelle', 'Finance · solde de compte')->count());
    }

    public function test_the_maximum_amount_is_accepted_and_one_cent_more_is_refused(): void
    {
        $this->student('90006', 'A', 'a');
        $this->finance(90006, '0');
        $reg = $this->registrar();
        $this->api($reg)->putJson(self::BASE."/{$this->session}/90006", ['balance' => '9999999999999,99'])->assertOk();
        $this->assertSame('9999999999999.99', DB::table('finance')->where('student_id', 90006)->value('balance_amount'));
        $this->api($reg)->putJson(self::BASE."/{$this->session}/90006", ['balance' => '10000000000000'])->assertUnprocessable()->assertJsonPath('code', 'validation.balance');
    }

    public function test_an_account_without_rights_gets_403_before_any_validation(): void
    {
        $this->student('90007', 'A', 'a');
        $this->finance(90007, '10');
        foreach ([$this->as(5, 'media'), $this->as(7, 'teacher')] as $who) {
            $this->api($who)->putJson(self::BASE."/{$this->session}/90007", [])->assertForbidden()->assertJsonPath('code', 'forbidden.balances');
        }
        $this->assertSame('10.00', DB::table('finance')->where('student_id', 90007)->value('balance_amount'));
    }

    public function test_the_domain_itself_refuses_a_malformed_finance_id(): void
    {
        $this->student('90008', 'A', 'a');
        $this->finance(90008, '10');
        $this->finance(12, '10');   // « 12abc » serait lu 12 par un simple (int) : la forme doit être refusée avant
        foreach (['abc', '', '1; DROP', '12abc', '12345678901', '-5'] as $bad) {
            try {
                app(Balances::class)->update($this->registrar(), $this->session, $bad, 5);
                $this->fail('un identifiant de ligne mal formé doit être refusé : '.$bad);
            } catch (\App\Exceptions\ApiProblem $e) {
                $this->assertSame(404, $e->status);
            }
        }
        $this->assertSame(['10.00', '10.00'], [DB::table('finance')->where('student_id', 90008)->value('balance_amount'), DB::table('finance')->where('student_id', 12)->value('balance_amount')]);
    }

    // ------------------------------------------------------------------ lecture des montants (unités)

    public function test_amount_reading_matches_the_legacy_helper(): void
    {
        foreach ([['', 0.0], [null, 0.0], ['12', 12.0], ['1 234,5', 1234.5], ["1\u{00A0}234", 1234.0], ['-5', -5.0], ['abc', 0.0], ['1,2,3', 0.0], [' 7 ', 7.0], ['1e3', 1000.0]] as [$in, $out]) {
            $this->assertSame($out, Balances::number($in), json_encode($in));
        }
        $this->assertNull(Balances::parseInput('1e999'));
        $this->assertNull(Balances::parseInput(['1']));
        $this->assertNull(Balances::parseInput(true));
        $this->assertSame(1.01, Balances::parseInput('1,005'));
    }
}
