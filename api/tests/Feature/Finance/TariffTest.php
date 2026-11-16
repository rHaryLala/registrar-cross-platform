<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\ExtraFees;
use App\Domain\Finance\Tariff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Barème par session : lecture (base, surcharge, repli), enregistrement partiel, plusieurs filières, reprise, retour à la base, frais supplémentaires. */
class TariffTest extends TestCase
{
    use MakesStudentData;

    private const BASE = '/api/v1/staff/finance/tariff';

    private int $s1;

    private int $s2;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('t_2024_finance_detail_licence')->delete();
        DB::table('t_frais_perso_montant')->delete();
        DB::table('t_frais_perso')->delete();
        DB::table('filiere')->delete();
        DB::table('t_2023_session')->update(['selected' => 0]);
        foreach ([['INFO', 'Informatique', 1], ['GEST', 'Gestion', 1], ['THEO', 'Théologie', 1], ['EDUC', 'Éducation', 0]] as $i => [$sigle, $label, $open]) {
            DB::table('filiere')->insert(['filiere_id' => 8000 + $i, 'filiere_sigle' => $sigle, 'filiere_description' => $label, 'filiere_description_ang' => '', 'domaine_signe' => '', 'domaine_name' => '', 'ouverte' => $open]);
        }
        $year = Tariff::currentYear();
        $this->s1 = $this->makeSession('Premier semestre', $year, 1);
        $this->s2 = $this->makeSession('Deuxième semestre', $year, 2);
    }

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function as(int $level, string $type): User
    {
        return User::factory()->staff($level, $type)->create();
    }

    private function reg(): User
    {
        return $this->as(3, 'registrar');
    }

    /** Une ligne du barème ; les montants par défaut valent 0. */
    private function row(int $session, string $mention, int $level, string $status, int $semester, array $amounts = []): int
    {
        return DB::table('t_2024_finance_detail_licence')->insertGetId(array_merge([
            'frais_generaux' => 0, 'laboratory_info' => 0, 'laboratory_lang' => 0, 'dortoir' => 0, 'cafeteria' => 0, 'fond_depot' => 0, 'nb_jours_semestre' => 0, 'ecolage' => 0,
            'frais_graduation' => 0, 'frais_costume' => 0, 'frais_voyage' => 0,
        ], $amounts, ['session_id' => $session, 'categorie' => Tariff::category($level), 'std_status' => $status, 'std_mention' => $mention, 'level' => $level, 'semester' => $semester]));
    }

    private function show(string $qs = '', ?User $who = null)
    {
        return $this->api($who ?? $this->reg())->getJson(self::BASE.$qs);
    }

    private function save(array $body, ?User $who = null)
    {
        return $this->api($who ?? $this->reg())->putJson(self::BASE, $body);
    }

    private function rows(int $session, ?string $mention = null): array
    {
        $q = DB::table('t_2024_finance_detail_licence')->where('session_id', $session);
        if ($mention) {
            $q->where('std_mention', $mention);
        }

        return $q->orderBy('std_mention')->orderBy('level')->orderBy('std_status')->orderBy('semester')->get()->all();
    }

    private function card(array $data, string $sigle): array
    {
        return collect($data['mentions'])->firstWhere('sigle', $sigle);
    }

    // ------------------------------------------------------------------ accès

    public function test_open_to_levels_1_to_3_only(): void
    {
        foreach ([$this->as(1, 'superadmin'), $this->as(2, 'admin'), $this->reg()] as $who) {
            $this->show('', $who)->assertOk();
        }
        foreach ([$this->as(4, 'comptabilite'), $this->as(5, 'media'), $this->as(7, 'teacher'), User::factory()->create(), User::factory()->staff(3, 'registrar')->inactive()->create()] as $who) {
            $this->show('', $who)->assertForbidden()->assertJsonPath('code', 'forbidden.tariff');
        }
        $this->api($this->reg())->getJson('/api/v1/auth/me')->assertJsonPath('data.finance.tariff', true);
        $this->api($this->as(4, 'comptabilite'))->getJson('/api/v1/auth/me')->assertJsonPath('data.finance.tariff', null);
    }

    public function test_anonymous_gets_401(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->putJson(self::BASE, [])->assertUnauthorized();
    }

    public function test_the_403_comes_before_any_validation_on_every_write(): void
    {
        $who = $this->as(4, 'comptabilite');
        $this->api($who)->putJson(self::BASE, [])->assertForbidden();
        $this->api($who)->postJson(self::BASE.'/copy', [])->assertForbidden();
        $this->api($who)->postJson(self::BASE.'/reset', [])->assertForbidden();
        $this->api($who)->postJson('/api/v1/staff/finance/extra-fees', [])->assertForbidden();
        $this->api($who)->patchJson('/api/v1/staff/finance/extra-fees/1', [])->assertForbidden();
    }

    // ------------------------------------------------------------------ lecture

    public function test_the_year_is_the_requested_one_then_the_current_one_then_the_latest(): void
    {
        $old = $this->makeSession('Premier semestre', '2019 - 2020', 1);
        $d = $this->show()->json('data');
        $this->assertSame(Tariff::currentYear(), $d['year']);
        $this->assertSame('2019 - 2020', $d['years'][array_key_last($d['years'])]);
        $this->assertSame('2019 - 2020', $this->show('?annee='.urlencode('2019 - 2020'))->json('data.year'));
        $this->assertSame(Tariff::currentYear(), $this->show('?annee='.urlencode('1999 - 2000'))->json('data.year'), 'année inconnue : l’année en cours');
        $this->assertSame($old, $this->show('?annee='.urlencode('2019 - 2020'))->json('data.session.id'));

        // une année plus récente que l'année en cours existe (sessions créées d'avance) : l'année en cours reste celle qu'on ouvre
        $next = (int) substr(Tariff::currentYear(), 0, 4) + 1;
        $this->makeSession('Premier semestre', $next.' - '.($next + 1), 1);
        $d = $this->show()->json('data');
        $this->assertSame([$next.' - '.($next + 1), Tariff::currentYear()], [$d['years'][0], $d['year']]);
    }

    public function test_the_school_year_runs_from_july_to_june(): void
    {
        $this->assertSame('2026 - 2027', Tariff::currentYear(7, 2026));
        $this->assertSame('2026 - 2027', Tariff::currentYear(12, 2026));
        $this->assertSame('2025 - 2026', Tariff::currentYear(6, 2026));
        $this->assertSame('2025 - 2026', Tariff::currentYear(1, 2026));
    }

    public function test_semesters_target_and_base_editing(): void
    {
        $d = $this->show()->json('data');
        $this->assertSame([1, 2], array_column($d['sessions'], 'semester'));
        $this->assertSame([1, $this->s1, false], [$d['semester'], $d['target'], $d['editing_base']]);
        $d = $this->show('?semestre=2')->json('data');
        $this->assertSame([2, $this->s2], [$d['semester'], $d['target']]);
        $this->assertSame(1, $this->show('?semestre=9')->json('data.semester'), 'semestre inconnu : le premier');
        $d = $this->show('?semestre=2&base=1')->json('data');
        $this->assertSame([true, 0, 2], [$d['editing_base'], $d['target'], $d['semester']]);
    }

    public function test_only_open_mentions_have_a_card_and_a_missing_tariff_is_announced(): void
    {
        $this->row(0, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 100]);
        $d = $this->show()->json('data');
        $this->assertSame(['GEST', 'INFO', 'THEO'], array_column($d['mentions'], 'sigle'), 'EDUC est fermée : sans carte');
        $this->assertFalse($this->card($d, 'INFO')['no_tariff']);
        $this->assertTrue($this->card($d, 'GEST')['no_tariff']);
        $this->assertSame([0, 3], [$d['adjusted_count'], $d['mention_count']]);
    }

    public function test_cells_fall_back_to_the_base_and_say_where_they_come_from(): void
    {
        $this->row(0, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 100, 'ecolage' => 10]);
        $this->row(0, 'INFO', 1, 'Externe', 1, ['frais_generaux' => 90, 'ecolage' => 10]);
        $this->row($this->s1, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 150, 'ecolage' => 12]);

        $c = $this->card($this->show()->json('data'), 'INFO');
        $this->assertSame(['session', 150.0, 12.0], [$c['grid']['1|Interne']['source'], (float) $c['grid']['1|Interne']['values']['frais_generaux'], (float) $c['grid']['1|Interne']['values']['ecolage']]);
        $this->assertSame(['base', 90.0], [$c['grid']['1|Externe']['source'], (float) $c['grid']['1|Externe']['values']['frais_generaux']]);
        $this->assertSame(['none', null], [$c['grid']['2|Interne']['source'], $c['grid']['2|Interne']['values']]);
        $this->assertCount(12, $c['grid'], 'six niveaux (0 à 5) × deux statuts');
        $this->assertTrue($c['adjusted']);
        // une session sans surcharge hérite de la base ; l'autre semestre n'est pas mélangé
        $other = $this->card($this->show('?semestre=2')->json('data'), 'INFO');
        $this->assertSame('none', $other['grid']['1|Interne']['source'], 'le tarif du semestre 1 ne sert pas au semestre 2');
        $this->assertFalse($other['adjusted']);
    }

    public function test_editing_the_base_ignores_session_rows(): void
    {
        $this->row(0, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 100]);
        $this->row($this->s1, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 150]);
        $c = $this->card($this->show('?base=1')->json('data'), 'INFO');
        $this->assertSame(['base', 100.0, false], [$c['grid']['1|Interne']['source'], (float) $c['grid']['1|Interne']['values']['frais_generaux'], $c['adjusted']]);
    }

    public function test_which_fields_make_sense_depends_on_the_mention_and_the_semester(): void
    {
        $cols = fn (array $c) => array_column($c['fields'], 'column');
        $d1 = $this->show('?semestre=1')->json('data');
        $d2 = $this->show('?semestre=2')->json('data');
        $this->assertNotContains('frais_graduation', $cols($this->card($d1, 'INFO')), 'la graduation ne se règle qu’au semestre 2');
        $this->assertContains('frais_graduation', $cols($this->card($d2, 'INFO')));
        $this->assertContains('frais_costume', $cols($this->card($d1, 'THEO')), 'le costume : Théologie, semestre 1');
        $this->assertNotContains('frais_costume', $cols($this->card($d1, 'INFO')));
        $this->assertNotContains('frais_costume', $cols($this->card($d2, 'THEO')));
        $voyage = fn (array $c) => collect($c['fields'])->firstWhere('column', 'frais_voyage')['label'];
        $this->assertSame(['Colloque', 'Voyage d’étude'], [$voyage($this->card($d1, 'THEO')), $voyage($this->card($d1, 'INFO'))]);
        $this->assertCount(11 - 2, $this->card($d1, 'INFO')['fields']);
    }

    // ------------------------------------------------------------------ enregistrement

    public function test_a_save_creates_the_session_override_from_the_base_and_writes_only_what_is_sent(): void
    {
        $this->row(0, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 100, 'ecolage' => 10, 'dortoir' => 7, 'frais_voyage' => 55]);

        $r = $this->save(['session_id' => $this->s1, 'mention' => 'INFO', 'semester' => 1, 'amounts' => ['1|Interne' => ['frais_generaux' => 150]]])->assertOk()->json('data');

        $this->assertSame([1, 1, true], [$r['written'], $r['created'], $r['override']]);
        $this->assertSame('Barème de Premier semestre '.Tariff::currentYear().' enregistré (1 ligne(s), dont 1 nouvelle(s)).', $r['message']);
        $row = $this->rows($this->s1)[0];
        $this->assertSame([150.0, 10.0, 7.0, 55.0, 'L'], [(float) $row->frais_generaux, (float) $row->ecolage, (float) $row->dortoir, (float) $row->frais_voyage, $row->categorie], 'les montants non transmis sont HÉRITÉS de la base, jamais mis à zéro');
        $this->assertSame(100.0, (float) $this->rows(0)[0]->frais_generaux, 'la base n’est pas touchée');
    }

    public function test_an_update_changes_only_the_sent_amounts_and_never_zeroes_the_others(): void
    {
        $id = $this->row($this->s1, 'INFO', 4, 'Externe', 1, ['frais_generaux' => 100, 'ecolage' => 25000, 'frais_costume' => 33, 'frais_graduation' => 44]);
        DB::table('t_2024_finance_detail_licence')->where('id', $id)->update(['categorie' => 'X']);

        $r = $this->save(['session_id' => $this->s1, 'mention' => 'INFO', 'semester' => 1, 'amounts' => ['4|Externe' => ['ecolage' => 26000.5, 'inconnue' => 9, 'dortoir' => '12']]])->assertOk()->json('data');

        $this->assertSame([1, 0], [$r['written'], $r['created']]);
        $row = DB::table('t_2024_finance_detail_licence')->where('id', $id)->first();
        $this->assertSame([100.0, 26000.5, 33.0, 44.0, 12.0, 'M'], [(float) $row->frais_generaux, (float) $row->ecolage, (float) $row->frais_costume, (float) $row->frais_graduation, (float) $row->dortoir, $row->categorie]);
        $audit = DB::table('t_audit_modification')->where('table_nom', 't_2024_finance_detail_licence')->orderByDesc('id')->first();
        $this->assertSame('UPDATE', $audit->operation);
    }

    public function test_the_same_amounts_go_to_several_mentions_in_one_transaction(): void
    {
        $r = $this->save(['session_id' => $this->s2, 'mention' => 'INFO', 'mentions' => ['GEST', 'THEO', 'INFO', ''], 'semester' => 2, 'amounts' => ['1|Interne' => ['frais_generaux' => 200], '2|Externe' => ['ecolage' => 9]]])->assertOk()->json('data');

        $this->assertSame(['INFO', 'GEST', 'THEO'], $r['mentions']);
        $this->assertSame([6, 6], [$r['written'], $r['created']]);
        $this->assertStringContainsString('appliqué à 3 filières', $r['message']);
        foreach (['INFO', 'GEST', 'THEO'] as $m) {
            $this->assertCount(2, $this->rows($this->s2, $m), $m);
        }
        $this->assertSame(1, DB::table('t_audit_requete')->where('libelle', 'Finance · barème')->count());
    }

    public function test_a_save_on_the_base_writes_the_base(): void
    {
        $this->save(['session_id' => 0, 'mention' => 'INFO', 'semester' => 3, 'amounts' => ['0|Interne' => ['frais_generaux' => 5]]])->assertOk()->assertJsonPath('data.override', false);
        $this->assertSame(1, DB::table('t_2024_finance_detail_licence')->where('session_id', 0)->where('semester', 3)->count());
    }

    public function test_invalid_requests_are_refused_without_writing(): void
    {
        $ok = ['session_id' => $this->s1, 'mention' => 'INFO', 'semester' => 1, 'amounts' => ['1|Interne' => ['frais_generaux' => 150]]];
        $this->save(array_merge($ok, ['session_id' => 99999999]))->assertUnprocessable()->assertJsonPath('code', 'validation.session');
        $this->save(array_merge($ok, ['session_id' => -1]))->assertUnprocessable();
        $this->save(array_diff_key($ok, ['session_id' => 1]))->assertUnprocessable();
        $this->save(array_merge($ok, ['mention' => 'ZZZZ']))->assertUnprocessable()->assertJsonPath('code', 'validation.mention');
        $this->save(array_merge($ok, ['mention' => '']))->assertUnprocessable();
        $this->save(array_merge($ok, ['mentions' => ['NOPE']]))->assertUnprocessable();
        $this->save(array_merge($ok, ['semester' => 0]))->assertUnprocessable();
        $this->save(array_merge($ok, ['semester' => 5]))->assertUnprocessable();
        $this->save(array_merge($ok, ['session_id' => 0, 'semester' => 5]))->assertUnprocessable()->assertJsonPath('code', 'validation.semester');   // sur la base, seule la plage compte
        $this->save(array_merge($ok, ['session_id' => 0, 'semester' => 4]))->assertOk();
        DB::table('t_2024_finance_detail_licence')->delete();
        $this->save(array_merge($ok, ['semester' => 2]))->assertUnprocessable()->assertJsonPath('code', 'validation.semester');   // la session est celle du semestre 1
        $this->save(array_merge($ok, ['amounts' => []]))->assertUnprocessable()->assertJsonPath('code', 'validation.empty')->assertJsonPath('title', 'Aucun montant');
        $this->save(array_merge($ok, ['amounts' => ['1|Interne' => ['inconnue' => 5]]]))->assertUnprocessable();   // aucune valeur exploitable
        $this->assertSame([], $this->rows($this->s1));
    }

    public function test_a_bad_amount_refuses_the_whole_request_even_when_other_amounts_are_fine(): void
    {
        $base = ['session_id' => $this->s1, 'mention' => 'INFO', 'semester' => 1];
        foreach (['-5', -1, 'abc', '1e999', 1e13, [1], null, true] as $bad) {
            $this->save($base + ['amounts' => ['1|Interne' => ['frais_generaux' => 150], '2|Interne' => ['ecolage' => $bad]]])->assertUnprocessable()->assertJsonPath('code', 'validation.amount');
        }
        $this->assertSame([], $this->rows($this->s1), 'rien n’est écrit, pas même le montant valide');
        $this->save($base + ['amounts' => ['1|Interne' => ['frais_generaux' => 0, 'ecolage' => '12.5', 'dortoir' => 999999999999]]])->assertOk();
    }

    public function test_amount_reading(): void
    {
        $this->assertSame([0.0, 12.5, 7.0, null, null, null, null], [Tariff::amount(0), Tariff::amount('12.5'), Tariff::amount(' 7 '), Tariff::amount(-1), Tariff::amount('x'), Tariff::amount(1e13), Tariff::amount(null)]);
    }

    // ------------------------------------------------------------------ reprise et retour à la base

    public function test_copying_the_base_keeps_existing_adjustments_and_stays_in_the_semester_of_the_target(): void
    {
        $this->row(0, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 100]);
        $this->row(0, 'INFO', 1, 'Externe', 1, ['frais_generaux' => 90]);
        $this->row(0, 'INFO', 1, 'Interne', 2, ['frais_generaux' => 80]);   // autre semestre : jamais copié vers une session du semestre 1
        $this->row(0, 'GEST', 1, 'Interne', 1, ['frais_generaux' => 70]);
        $this->row($this->s1, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 999]);   // déjà ajustée : conservée

        $r = $this->api($this->reg())->postJson(self::BASE.'/copy', ['session_id' => $this->s1, 'mention' => 'INFO'])->assertOk()->json('data');

        $this->assertSame([1, 1], [$r['copied'], $r['ignored']]);
        $this->assertSame('1 ligne(s) reprise(s), 1 conservée(s) car déjà ajustée(s).', $r['message']);
        $rows = $this->rows($this->s1);
        $this->assertSame(['INFO', 'INFO'], array_column($rows, 'std_mention'));
        $this->assertSame(['Externe', 90.0, 'Interne', 999.0], [$rows[0]->std_status, (float) $rows[0]->frais_generaux, $rows[1]->std_status, (float) $rows[1]->frais_generaux], 'la ligne déjà ajustée (999) est conservée, l’autre est reprise de la base');
        $this->assertSame(0, DB::table('t_2024_finance_detail_licence')->where('session_id', $this->s1)->where('semester', 2)->count());

        $all = $this->api($this->reg())->postJson(self::BASE.'/copy', ['session_id' => $this->s1])->assertOk()->json('data');
        $this->assertSame([1, 2], [$all['copied'], $all['ignored']], 'sans filière : toutes les filières du semestre');
        $again = $this->api($this->reg())->postJson(self::BASE.'/copy', ['session_id' => $this->s1])->assertOk()->json('data');
        $this->assertSame(['Rien à reprendre : toutes les lignes sont déjà ajustées pour cette session.', 0], [$again['message'], $again['copied']]);
    }

    public function test_copy_from_another_session_and_its_refusals(): void
    {
        $this->row($this->s1, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 123]);
        $s1b = $this->makeSession('Premier semestre', '2018 - 2019', 1);
        $this->api($this->reg())->postJson(self::BASE.'/copy', ['session_id' => $s1b, 'source_session_id' => $this->s1])->assertOk()->assertJsonPath('data.copied', 1);
        $this->assertSame(123.0, (float) $this->rows($s1b)[0]->frais_generaux);

        $this->api($this->reg())->postJson(self::BASE.'/copy', ['session_id' => 0])->assertUnprocessable()->assertJsonPath('code', 'validation.base');
        $this->api($this->reg())->postJson(self::BASE.'/copy', ['session_id' => $this->s2, 'source_session_id' => 99999999])->assertUnprocessable();
        $this->api($this->reg())->postJson(self::BASE.'/copy', ['session_id' => 99999999])->assertUnprocessable();
        $self = $this->api($this->reg())->postJson(self::BASE.'/copy', ['session_id' => $this->s1, 'source_session_id' => $this->s1])->assertOk()->json('data');
        $this->assertSame([0, 0], [$self['copied'], $self['ignored']], 'reprendre une session sur elle-même ne fait rien (et ne compte rien d’« ignoré »)');
        $this->assertSame(1, DB::table('t_audit_modification')->where('table_nom', 't_2024_finance_detail_licence')->where('operation', 'INSERT')->count(), 'la reprise vers l’autre session est journalisée');
        $this->api($this->reg())->postJson(self::BASE.'/copy', ['session_id' => $this->s2, 'mention' => 'ZZZ'])->assertUnprocessable();
    }

    public function test_reset_removes_the_override_of_a_session_or_of_one_mention_and_never_the_base(): void
    {
        $this->row(0, 'INFO', 1, 'Interne', 1, ['frais_generaux' => 100]);
        $this->row($this->s1, 'INFO', 1, 'Interne', 1);
        $this->row($this->s1, 'INFO', 1, 'Interne', 2);   // autre semestre : supprimé aussi (comme l'ancien code)
        $this->row($this->s1, 'GEST', 1, 'Interne', 1);
        $this->row($this->s2, 'INFO', 1, 'Interne', 2);

        $one = $this->api($this->reg())->postJson(self::BASE.'/reset', ['session_id' => $this->s1, 'mention' => 'INFO'])->assertOk()->json('data');
        $this->assertSame([2, '2 ligne(s) supprimée(s) : cette session revient au barème de base.'], [$one['deleted'], $one['message']]);
        $this->assertCount(1, $this->rows($this->s1));
        $this->assertSame(1, DB::table('t_audit_modification')->where('table_nom', 't_2024_finance_detail_licence')->where('operation', 'DELETE')->count());

        $all = $this->api($this->reg())->postJson(self::BASE.'/reset', ['session_id' => $this->s1])->assertOk()->json('data');
        $this->assertSame(1, $all['deleted']);
        $none = $this->api($this->reg())->postJson(self::BASE.'/reset', ['session_id' => $this->s1])->assertOk()->json('data');
        $this->assertSame([0, 'Cette session utilisait déjà le barème de base.'], [$none['deleted'], $none['message']]);
        $this->assertCount(1, $this->rows($this->s2), 'l’autre session n’est pas touchée');
        $this->assertCount(1, $this->rows(0));
        $this->api($this->reg())->postJson(self::BASE.'/reset', ['session_id' => 0])->assertUnprocessable()->assertJsonPath('code', 'validation.base');
    }

    // ------------------------------------------------------------------ frais supplémentaires

    private function fee(string $label = 'Alliance française', array $extra = []): int
    {
        return DB::table('t_frais_perso')->insertGetId(array_merge(['libelle' => $label, 'actif' => 1, 'ordre' => 1, 'condition_frais' => 'aucune', 'semestres' => ''], $extra));
    }

    private function feeApi(string $method, string $url, array $body = [])
    {
        return $this->api($this->reg())->json($method, '/api/v1/staff/finance/extra-fees'.$url, $body);
    }

    public function test_an_extra_fee_is_created_or_reactivated_never_duplicated(): void
    {
        $r = $this->feeApi('POST', '', ['label' => '  Alliance   française ', 'condition' => 'diplomant', 'semesters' => [2, 2, 9, 5, 0, '1']])->assertCreated()->json('data');
        $this->assertTrue($r['created']);
        $row = DB::table('t_frais_perso')->where('id', $r['id'])->first();
        $this->assertSame(['Alliance française', 1, 'diplomant', '1,2', 1], [$row->libelle, (int) $row->actif, $row->condition_frais, $row->semestres, (int) $row->ordre]);

        DB::table('t_frais_perso')->where('id', $r['id'])->update(['actif' => 0]);
        $again = $this->feeApi('POST', '', ['label' => 'Alliance française'])->assertOk()->json('data');
        $this->assertSame([$r['id'], false], [$again['id'], $again['created']]);
        $this->assertSame(1, (int) DB::table('t_frais_perso')->where('id', $r['id'])->value('actif'));
        $this->assertSame(1, DB::table('t_frais_perso')->count());
        $second = $this->feeApi('POST', '', ['label' => 'Photocopies'])->assertCreated()->json('data');
        $this->assertSame(2, (int) DB::table('t_frais_perso')->where('id', $second['id'])->value('ordre'));
    }

    public function test_extra_fee_labels_and_conditions_are_validated(): void
    {
        $this->feeApi('POST', '', ['label' => ''])->assertUnprocessable()->assertJsonPath('code', 'validation.label');
        $this->feeApi('POST', '', ['label' => str_repeat('x', 121)])->assertUnprocessable();
        $this->feeApi('POST', '', [])->assertUnprocessable();
        $this->feeApi('POST', '', ['label' => 'X', 'condition' => 'nimporte'])->assertUnprocessable()->assertJsonPath('code', 'validation.condition');
        $this->assertSame(0, DB::table('t_frais_perso')->count());
    }

    public function test_an_extra_fee_is_renamed_toggled_and_configured(): void
    {
        $id = $this->fee();
        $other = $this->fee('Photocopies', ['ordre' => 2]);

        $this->assertTrue($this->feeApi('PATCH', "/$id", ['label' => 'Alliance Française'])->assertOk()->json('data.changed'));
        $this->assertFalse($this->feeApi('PATCH', "/$id", ['label' => 'Alliance Française'])->assertOk()->json('data.changed'), 'même nom : rien à faire');
        $this->feeApi('PATCH', "/$id", ['label' => 'Photocopies'])->assertUnprocessable()->assertJsonPath('code', 'validation.label');

        $this->assertTrue($this->feeApi('PATCH', "/$id", ['active' => false])->assertOk()->json('data.changed'));
        $this->assertFalse($this->feeApi('PATCH', "/$id", ['active' => false])->json('data.changed'));
        $this->assertSame(0, (int) DB::table('t_frais_perso')->where('id', $id)->value('actif'));
        $this->feeApi('PATCH', "/$id", ['active' => true])->assertOk();
        $this->assertSame(1, (int) DB::table('t_frais_perso')->where('id', $id)->value('actif'));

        $r = $this->feeApi('PATCH', "/$id", ['condition' => 'nouvel_etudiant', 'semesters' => '2,1,2'])->assertOk()->json('data');
        $this->assertSame(['nouvel_etudiant', '1,2'], [DB::table('t_frais_perso')->where('id', $id)->value('condition_frais'), DB::table('t_frais_perso')->where('id', $id)->value('semestres')]);
        $this->assertStringContainsString('nouveaux étudiants, semestre(s) 1,2', $r['message']);
        $this->feeApi('PATCH', "/$id", ['condition' => 'zzz'])->assertUnprocessable();
        $this->feeApi('PATCH', '/99999', ['active' => true])->assertNotFound()->assertJsonPath('code', 'not_found.extra_fee');
        $this->feeApi('PATCH', "/$id", [])->assertUnprocessable();
        $this->assertSame('Photocopies', DB::table('t_frais_perso')->where('id', $other)->value('libelle'));
    }

    public function test_extra_fee_amounts_fall_back_to_the_base_and_an_empty_cell_clears_the_override(): void
    {
        $fee = $this->fee();
        DB::table('t_frais_perso_montant')->insert([
            ['frais_id' => $fee, 'session_id' => 0, 'std_mention' => 'INFO', 'level' => 1, 'std_status' => 'Interne', 'semester' => 1, 'montant' => 5000],
            ['frais_id' => $fee, 'session_id' => 0, 'std_mention' => 'INFO', 'level' => 2, 'std_status' => 'Interne', 'semester' => 1, 'montant' => 6000],
            ['frais_id' => $fee, 'session_id' => $this->s1, 'std_mention' => 'INFO', 'level' => 1, 'std_status' => 'Interne', 'semester' => 1, 'montant' => 0],
        ]);
        $this->row(0, 'INFO', 1, 'Interne', 1);

        $c = $this->card($this->show()->json('data'), 'INFO');
        $this->assertEquals(['amount' => 0.0, 'inherited' => false], $c['extra'][$fee]['1|Interne'], '0 est une valeur : « rien à payer ici »');
        $this->assertNotNull($c['extra'][$fee]['1|Interne']['amount']);
        $this->assertEquals(['amount' => 6000.0, 'inherited' => true], $c['extra'][$fee]['2|Interne']);
        $this->assertSame(['amount' => null, 'inherited' => false], $c['extra'][$fee]['3|Interne']);

        $r = $this->save(['session_id' => $this->s1, 'mention' => 'INFO', 'semester' => 1, 'extra' => [$fee => ['1|Interne' => '', '2|Interne' => '7000', '3|Externe' => 0]]])->assertOk()->json('data');
        $this->assertSame([2, 1], [$r['extra_written'], $r['extra_cleared']]);
        $this->assertSame(1, DB::table('t_audit_modification')->where('table_nom', 't_frais_perso_montant')->where('operation', 'DELETE')->count(), 'l’effacement d’un montant est journalisé');
        $this->assertStringContainsString('2 montant(s) de frais supplémentaires, 1 revenu(s) au barème de base.', $r['message']);
        $c = $this->card($this->show()->json('data'), 'INFO');
        $this->assertEquals(['amount' => 5000.0, 'inherited' => true], $c['extra'][$fee]['1|Interne'], 'case vidée : retour à la base');
        $this->assertEquals(['amount' => 7000.0, 'inherited' => false], $c['extra'][$fee]['2|Interne']);
        $this->assertEquals(['amount' => 0.0, 'inherited' => false], $c['extra'][$fee]['3|Externe']);
        $this->assertNotNull($c['extra'][$fee]['3|Externe']['amount']);
        $this->assertSame(0, DB::table('t_2024_finance_detail_licence')->where('session_id', $this->s1)->count(), 'des frais supplémentaires seuls n’écrivent pas de ligne de barème');
    }

    public function test_the_extra_fee_domain_itself_refuses_negative_or_unreadable_amounts(): void
    {
        $fee = $this->fee();
        $r = app(ExtraFees::class)->saveAmounts($this->s1, 'INFO', 1, [$fee => ['1|Interne' => -5, '2|Interne' => 'abc', '3|Interne' => 1e13, '4|Interne' => 12]]);
        $this->assertSame([1, 0], [$r['written'], $r['cleared']]);
        $this->assertSame([['level' => 4, 'montant' => 12.0]], DB::table('t_frais_perso_montant')->get(['level', 'montant'])->map(fn ($x) => ['level' => (int) $x->level, 'montant' => (float) $x->montant])->all());
    }

    public function test_extra_fees_only_show_in_the_semesters_where_they_apply_and_unknown_fees_are_ignored(): void
    {
        $fee = $this->fee('Graduation bis', ['semestres' => '2']);
        $d1 = $this->show('?semestre=1')->json('data');
        $d2 = $this->show('?semestre=2')->json('data');
        $this->assertEmpty((array) $this->card($d1, 'INFO')['extra']);
        $this->assertArrayHasKey((string) $fee, (array) $this->card($d2, 'INFO')['extra']);
        $this->assertCount(1, $d1['extra_fees']);
        $this->assertTrue($d1['extra_fees_installed']);

        $this->save(['session_id' => $this->s2, 'mention' => 'INFO', 'semester' => 2, 'extra' => [987654 => ['1|Interne' => 5], $fee => ['9|Interne' => 5, '1|Autre' => 5]]])->assertUnprocessable()->assertJsonPath('code', 'validation.empty');
        $this->assertSame(0, DB::table('t_frais_perso_montant')->count());
    }

    public function test_a_bad_extra_amount_refuses_the_whole_request(): void
    {
        $fee = $this->fee();
        $this->save(['session_id' => $this->s1, 'mention' => 'INFO', 'semester' => 1, 'amounts' => ['1|Interne' => ['frais_generaux' => 5]], 'extra' => [$fee => ['1|Interne' => '-3']]])->assertUnprocessable()->assertJsonPath('code', 'validation.amount');
        $this->assertSame(0, DB::table('t_2024_finance_detail_licence')->count());
    }

    public function test_every_write_is_journalised(): void
    {
        $fee = $this->fee();
        Carbon::setTestNow('2026-11-18 09:00:00');
        $this->save(['session_id' => $this->s1, 'mention' => 'INFO', 'semester' => 1, 'amounts' => ['1|Interne' => ['frais_generaux' => 5]], 'extra' => [$fee => ['1|Interne' => 9]]])->assertOk();
        $this->assertSame(1, DB::table('t_audit_modification')->where('table_nom', 't_2024_finance_detail_licence')->where('operation', 'INSERT')->count());
        $this->assertSame(1, DB::table('t_audit_modification')->where('table_nom', 't_frais_perso_montant')->where('operation', 'INSERT')->count());
        $this->feeApi('PATCH', "/$fee", ['active' => false])->assertOk();
        $this->assertSame(1, DB::table('t_audit_modification')->where('table_nom', 't_frais_perso')->where('operation', 'UPDATE')->count());
        $this->assertInstanceOf(ExtraFees::class, app(ExtraFees::class));
    }
}
