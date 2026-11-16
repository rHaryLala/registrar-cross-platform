<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesEnrollmentData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Échéances de paiement : accès, choix de la session, effectifs par type, enregistrement des dates (ordre, retrait, journal). */
class DeadlinesTest extends TestCase
{
    use MakesEnrollmentData;
    use MakesStudentData;

    private const BASE = '/api/v1/staff/finance/deadlines';

    private int $session;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('t_2023_session')->update(['selected' => 0]);
        DB::table('t_2024_etudiant_finace')->delete();
        $this->session = $this->makeSession('Premier semestre', '2026 - 2027', 1);
        DB::table('t_2023_session')->where('session_id', $this->session)->update(['date_entry' => '2026-09-28', 'graduation_date' => '2027-02-12']);
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

    private function show(string $qs = '', ?User $who = null)
    {
        return $this->api($who ?? $this->registrar())->getJson(self::BASE.$qs);
    }

    private function save(array $dates, ?int $session = null, ?User $who = null)
    {
        return $this->api($who ?? $this->registrar())->putJson(self::BASE.'/'.($session ?? $this->session), ['dates' => $dates]);
    }

    private function rows(?int $session = null): array
    {
        return DB::table('t_paiement_echeance')->where('session_id', $session ?? $this->session)->orderBy('type_paiement')->orderBy('tranche')->get()->all();
    }

    private function billed(string $student, string $mode, ?int $session = null): void
    {
        $this->finance($student, $session ?? $this->session, ['mode_payement' => $mode]);
    }

    // ------------------------------------------------------------------ accès

    public function test_open_to_levels_1_to_4_only(): void
    {
        foreach ([$this->as(1, 'superadmin'), $this->as(2, 'admin'), $this->registrar(), $this->as(4, 'comptabilite')] as $who) {
            $this->show('', $who)->assertOk();
        }
        foreach ([$this->as(5, 'media'), $this->as(6, 'chef_mention'), $this->as(7, 'teacher'), User::factory()->create(), $this->as(9, 'work_education'), User::factory()->staff(3, 'registrar')->inactive()->create()] as $who) {
            $this->show('', $who)->assertForbidden()->assertJsonPath('code', 'forbidden.deadlines');
        }
        $this->api($this->as(4, 'comptabilite'))->getJson('/api/v1/auth/me')->assertJsonPath('data.finance.deadlines', true);
        $this->api($this->as(5, 'media'))->getJson('/api/v1/auth/me')->assertJsonPath('data.finance', null);
    }

    public function test_anonymous_gets_401(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->putJson(self::BASE.'/1', ['dates' => []])->assertUnauthorized();
    }

    public function test_the_403_comes_before_any_validation(): void
    {
        $this->api($this->as(7, 'teacher'))->putJson(self::BASE.'/'.$this->session, [])->assertForbidden()->assertJsonPath('code', 'forbidden.deadlines');
        $this->assertSame([], $this->rows());
    }

    // ------------------------------------------------------------------ sessions

    public function test_sessions_are_ordered_by_year_then_semester_rank_1_3_2_4(): void
    {
        $ids = [];
        foreach ([[1, '2025 - 2026'], [2, '2025 - 2026'], [3, '2025 - 2026'], [4, '2025 - 2026']] as [$sem, $year]) {
            $ids[$sem] = $this->makeSession('S'.$sem, $year, $sem);
        }
        $s = $this->show()->json('data.sessions');
        $order = array_column(array_filter($s, fn ($x) => $x['year'] === '2025 - 2026'), 'semester');
        $this->assertSame([1, 3, 2, 4], array_values($order));
        $this->assertSame($this->session, end($s)['id'], 'la session de 2026 - 2027 vient après celles de 2025 - 2026');
        $this->assertSame('S1 2025 - 2026', collect($s)->firstWhere('id', $ids[1])['label']);
    }

    public function test_the_session_is_the_requested_one_then_the_latest_billed_then_the_active_then_the_last(): void
    {
        $old = $this->makeSession('Deuxième semestre', '2025 - 2026', 2);
        // rien de demandé, aucune facturation, aucune session active : la plus récente
        $this->assertSame($this->session, $this->show()->json('data.session.id'));
        // la session active
        DB::table('t_2023_session')->where('session_id', $old)->update(['selected' => 1]);
        $this->assertSame($old, $this->show()->json('data.session.id'));
        // la dernière ligne de frais créée l'emporte sur la session active (et non la première)
        $this->finance('A0', $old);
        $this->finance('A1', $this->session);
        $this->assertSame($this->session, $this->show()->json('data.session.id'));
        // une session demandée l'emporte sur tout ; une session inconnue est ignorée
        $this->assertSame($old, $this->show('?session='.$old)->json('data.session.id'));
        $this->assertSame($this->session, $this->show('?session=99999999')->json('data.session.id'));
    }

    public function test_previous_and_next_sessions_and_the_period(): void
    {
        $before = $this->makeSession('Premier semestre', '2025 - 2026', 1);
        $d = $this->show('?session='.$this->session)->json('data');
        $this->assertSame([$before, null], [$d['previous'], $d['next']]);
        $this->assertSame(['2026-09-28', '2027-02-12'], [$d['session']['start'], $d['session']['end']]);
        $d = $this->show('?session='.$before)->json('data');
        $this->assertSame($this->session, $d['next']);
    }

    // ------------------------------------------------------------------ effectifs et types montrés

    public function test_counts_per_type_distinct_students_and_the_ones_without_a_type(): void
    {
        $this->billed('E1', 'E');
        $this->billed('E2', 'E');
        $this->finance('E2', $this->session, ['mode_payement' => 'E', 'id' => null]);   // même étudiant, deuxième ligne : une seule fois
        $this->billed('B1', 'B');
        $this->billed('N1', '');
        $this->billed('N2', 'Z');   // type inconnu : « sans type »
        $this->finance('GONE', $this->session, ['mode_payement' => 'A', 'remove' => 1]);

        $d = $this->show()->json('data');
        $this->assertSame(['B' => 1, 'E' => 2], $d['counts']);
        $this->assertSame([2, 5], [$d['without_type'], $d['enrolled']]);
    }

    public function test_old_types_are_shown_only_when_a_file_or_a_date_still_uses_them(): void
    {
        $this->assertSame(['A', 'B', 'E'], array_keys($this->show()->json('data.types')));
        $this->billed('C1', 'C');
        $this->assertSame(['A', 'B', 'C', 'E'], array_keys($this->show()->json('data.types')));
        DB::table('t_paiement_echeance')->insert(['session_id' => $this->session, 'type_paiement' => 'D', 'tranche' => 1, 'date_limite' => '2026-10-03']);
        $d = $this->show()->json('data');
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], array_keys($d['types']));
        $this->assertSame([1, 1 + 2 + 2 + 3 + 4], [$d['filled'], $d['expected']], 'attendues : A 1 + B 2 + C 2 + D 3 + E 4');
        $this->assertFalse($d['types']['C']['proposed']);
        $this->assertTrue($d['types']['E']['proposed']);
        $this->assertSame([40, 30, 30], $d['types']['D']['parts']);
    }

    public function test_the_session_list_says_which_sessions_already_have_dates(): void
    {
        $other = $this->makeSession('Deuxième semestre', '2026 - 2027', 2);
        DB::table('t_paiement_echeance')->insert(['session_id' => $other, 'type_paiement' => 'A', 'tranche' => 1, 'date_limite' => '2027-03-01']);
        $s = collect($this->show()->json('data.sessions'))->keyBy('id');
        $this->assertSame([false, true], [$s[$this->session]['configured'], $s[$other]['configured']]);
    }

    public function test_the_same_semester_of_last_year_can_be_proposed_as_a_copy(): void
    {
        $last = $this->makeSession('Premier semestre', '2025 - 2026', 1);
        $other = $this->makeSession('Deuxième semestre', '2025 - 2026', 2);
        DB::table('t_paiement_echeance')->insert([
            ['session_id' => $last, 'type_paiement' => 'B', 'tranche' => 1, 'date_limite' => '2025-12-12'], ['session_id' => $last, 'type_paiement' => 'B', 'tranche' => 2, 'date_limite' => '2026-02-13'],
            ['session_id' => $last, 'type_paiement' => 'D', 'tranche' => 1, 'date_limite' => '2025-10-03'],   // D n'est pas un type montré ici : écarté
            ['session_id' => $other, 'type_paiement' => 'A', 'tranche' => 1, 'date_limite' => '2026-04-01'],   // autre semestre : jamais proposé
        ]);

        $copy = $this->show()->json('data.copy');
        $this->assertSame($last, $copy['session_id']);
        $this->assertSame('Premier semestre 2025 - 2026', $copy['label']);
        $this->assertSame(['B' => [1 => '2025-12-12', 2 => '2026-02-13']], $copy['dates']);
        $this->assertNull($this->show('?session='.$last)->json('data.copy'), 'rien avant 2025 - 2026');
    }

    // ------------------------------------------------------------------ enregistrement

    public function test_dates_are_saved_with_author_and_time_and_returned(): void
    {
        $reg = $this->registrar();
        Carbon::setTestNow('2026-11-17 09:00:00');

        $r = $this->save(['A' => [1 => '2026-12-11'], 'B' => [1 => '2026-12-11', 2 => '2027-02-12'], 'E' => [1 => '2026-11-13', 2 => '2026-12-11', 3 => '2027-01-15', 4 => '2027-02-12']], null, $reg)->assertOk()->json('data');

        $this->assertSame(7, $r['changed']);
        $this->assertSame('Échéances enregistrées.', $r['message']);
        $this->assertSame(['2026-12-11'], array_values($r['dates']['A']));
        $this->assertSame([1 => '2026-11-13', 2 => '2026-12-11', 3 => '2027-01-15', 4 => '2027-02-12'], $r['dates']['E']);
        $row = DB::table('t_paiement_echeance')->where(['session_id' => $this->session, 'type_paiement' => 'B', 'tranche' => 2])->first();
        $this->assertSame(['2027-02-12', $reg->id, '2026-11-17 09:00:00'], [$row->date_limite, (int) $row->modifie_par, $row->modifie_le]);
        $this->assertSame(1, DB::table('t_audit_requete')->where('libelle', 'Finance · échéances de paiement')->count());
        $this->assertSame(7, (int) DB::table('t_audit_modification')->where('table_nom', 't_paiement_echeance')->where('operation', 'INSERT')->sum('lignes'));
    }

    public function test_the_author_and_time_change_only_when_the_date_changes(): void
    {
        $first = $this->registrar();
        $second = $this->registrar();
        Carbon::setTestNow('2026-11-17 09:00:00');
        $this->save(['B' => [1 => '2026-12-11', 2 => '2027-02-12']], null, $first)->assertOk();
        Carbon::setTestNow('2026-11-18 10:30:00');

        $r = $this->save(['B' => [1 => '2026-12-11', 2 => '2027-02-19']], null, $second)->assertOk()->json('data');

        $this->assertSame(1, $r['changed']);
        $rows = $this->rows();
        $this->assertSame([[$first->id, '2026-11-17 09:00:00'], [$second->id, '2026-11-18 10:30:00']], array_map(fn ($x) => [(int) $x->modifie_par, $x->modifie_le], $rows), 'la tranche 1 garde son auteur, la 2 prend le nouveau');
        $this->assertSame(1, (int) DB::table('t_audit_modification')->where('table_nom', 't_paiement_echeance')->where('operation', 'UPDATE')->sum('lignes'));
    }

    public function test_saving_the_same_dates_again_changes_nothing(): void
    {
        $dates = ['A' => [1 => '2026-12-11'], 'B' => [1 => '2026-12-11', 2 => '2027-02-12']];
        $this->save($dates)->assertOk();
        $before = DB::table('t_audit_requete')->count();
        $r = $this->save($dates)->assertOk()->json('data');
        $this->assertSame([0, 'Rien n’avait changé.'], [$r['changed'], $r['message']]);
        $this->assertSame($before, DB::table('t_audit_requete')->count(), 'rien n’a changé : pas de ligne de journal');
    }

    public function test_an_empty_date_or_a_missing_rank_removes_the_slot_and_an_absent_type_is_untouched(): void
    {
        $this->save(['A' => [1 => '2026-12-11'], 'B' => [1 => '2026-12-11', 2 => '2027-02-12'], 'E' => [1 => '2026-11-13', 2 => '2026-12-11']])->assertOk();

        // B : tranche 1 vidée, tranche 2 absente du message (= retirée) ; A absent : intact ; E : tranche 2 retirée par une chaîne vide
        $r = $this->save(['B' => [1 => ''], 'E' => [1 => '2026-11-13', 2 => '']])->assertOk()->json('data');

        $this->assertSame(3, $r['changed']);
        $this->assertSame(['A' => [1 => '2026-12-11'], 'E' => [1 => '2026-11-13']], $r['dates']);
        $this->assertSame(3, (int) DB::table('t_audit_modification')->where('table_nom', 't_paiement_echeance')->where('operation', 'DELETE')->sum('lignes'));
    }

    public function test_each_tranche_must_come_after_the_previous_one(): void
    {
        $this->save(['E' => [1 => '2026-12-11', 2 => '2026-12-11']])->assertUnprocessable()->assertJsonPath('code', 'validation.deadlines')->assertJsonPath('field', 'dates.E.2');
        $this->save(['B' => [1 => '2027-02-12', 2 => '2026-12-11']])->assertUnprocessable();
        $this->save(['E' => [1 => '2026-11-13', 2 => '', 3 => '2026-12-01']])->assertOk();   // une tranche vide n'interrompt pas la comparaison : la 3e suit la 1re
        $this->assertSame([], DB::table('t_paiement_echeance')->where('session_id', $this->session)->where('type_paiement', 'B')->get()->all());
        $this->save(['E' => [1 => '2026-11-13', 2 => '', 3 => '2026-11-01']])->assertUnprocessable();
        $this->assertCount(2, $this->rows(), 'une saisie refusée n’écrit rien (la précédente reste)');
    }

    public function test_unreadable_dates_and_unknown_types_are_refused_without_writing(): void
    {
        foreach (['abc', '2026-02-30', '2026-13-01', '2026-5-1', '12/05/2026', '2026-05-01 10:00', '2026-05-01x'] as $bad) {
            $this->save(['A' => [1 => $bad]])->assertUnprocessable()->assertJsonPath('code', 'validation.deadlines');
        }
        $this->save(['Z' => [1 => '2026-12-11']])->assertUnprocessable();
        $this->save(['A' => '2026-12-11'])->assertUnprocessable();
        $this->api($this->registrar())->putJson(self::BASE.'/'.$this->session, [])->assertUnprocessable();
        $this->api($this->registrar())->putJson(self::BASE.'/'.$this->session, ['dates' => 'oui'])->assertUnprocessable();
        $this->assertSame([], $this->rows());
    }

    public function test_an_unknown_session_is_404_and_nothing_is_written(): void
    {
        $this->save(['A' => [1 => '2026-12-11']], 99999999)->assertNotFound()->assertJsonPath('code', 'not_found.session');
        $this->assertSame(0, DB::table('t_paiement_echeance')->where('session_id', 99999999)->count());
    }

    public function test_a_failed_save_is_all_or_nothing(): void
    {
        $this->save(['A' => [1 => '2026-12-11']])->assertOk();
        // la validation est faite AVANT toute écriture : B valide + E invalide n'écrit pas B
        $this->save(['B' => [1 => '2026-12-11', 2 => '2027-02-12'], 'E' => [1 => 'nimporte']])->assertUnprocessable();
        $this->assertSame(['A'], array_map(fn ($r) => $r->type_paiement, $this->rows()));
    }

    public function test_saving_never_touches_the_dates_of_another_session(): void
    {
        $other = $this->makeSession('Deuxième semestre', '2026 - 2027', 2);
        DB::table('t_paiement_echeance')->insert(['session_id' => $other, 'type_paiement' => 'B', 'tranche' => 1, 'date_limite' => '2027-03-05', 'modifie_par' => 77, 'modifie_le' => '2026-10-01 08:00:00']);

        $this->save(['B' => [1 => '2026-12-11', 2 => '2027-02-12']])->assertOk();
        $this->save(['B' => [1 => '', 2 => '']])->assertOk();   // et le retrait ne touche pas non plus à l'autre session

        $o = DB::table('t_paiement_echeance')->where('session_id', $other)->get();
        $this->assertCount(1, $o);
        $this->assertSame(['2027-03-05', 77, '2026-10-01 08:00:00'], [$o[0]->date_limite, (int) $o[0]->modifie_par, $o[0]->modifie_le]);
        $this->assertSame([], $this->rows());
    }

    public function test_the_cashier_can_save_too(): void
    {
        $cashier = $this->as(4, 'comptabilite');
        $this->save(['A' => [1 => '2026-12-11']], null, $cashier)->assertOk();
        $this->assertSame($cashier->id, (int) $this->rows()[0]->modifie_par);
    }

    public function test_the_dates_feed_the_enrolment_sheet_read_side(): void
    {
        $this->save(['B' => [1 => '2026-12-11', 2 => '2027-02-12']])->assertOk();
        $read = DB::table('t_paiement_echeance')->where('session_id', $this->session)->where('type_paiement', 'B')->orderBy('tranche')->pluck('date_limite')->all();
        $this->assertSame(['2026-12-11', '2027-02-12'], $read);
    }
}
