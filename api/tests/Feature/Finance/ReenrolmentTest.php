<?php

namespace Tests\Feature\Finance;

use App\Domain\Directory\Directory;
use App\Domain\Finance\Reenrolment;
use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Autorisation de réinscription : liste, droits par niveau, bascules (simples et groupées), réinitialisation globale et son annulation. */
class ReenrolmentTest extends TestCase
{
    private const BASE = '/api/v1/staff/finance/reenrolment';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tbl_2024_etudiant')->delete();   // la base de test garde une fiche de référence : on la met de côté le temps du test (annulé en fin de test)
        DB::table('t_reinscription_reinitialisation')->delete();
    }

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function as(int $level, string $type): User
    {
        return User::factory()->staff($level, $type)->create();
    }

    private function super(): User
    {
        return $this->as(1, 'superadmin');
    }

    private function cashier(): User
    {
        return $this->as(4, 'comptabilite');
    }

    private function registrar(): User
    {
        return $this->as(3, 'registrar');
    }

    private function student(string $id, string $last, string $first, array $over = []): int
    {
        return DB::table('tbl_2024_etudiant')->insertGetId(array_merge([
            'student_id' => $id, 'student_nom' => $last, 'student_prenom' => $first, 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2025-2026', 'period' => 'S1',
            'remove' => 0, 'etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux', 'annee_etude' => '1', 'new_student' => 0, 'status' => 'Externe',
            'authorized_reinscription' => 0, 'dossier_ok' => 0,
        ], $over));
    }

    private function flags(int $id): object
    {
        return DB::table('tbl_2024_etudiant')->where('id', $id)->first(['authorized_reinscription as a', 'authorized_by as ab', 'authorized_date as ad', 'dossier_ok as d', 'dossier_checked_by as db', 'dossier_checked_date as dd']);
    }

    private function history(int $id): array
    {
        return DB::table('t_student_modification_history')->where('student_db_id', $id)->orderBy('id')->get()->all();
    }

    // ------------------------------------------------------------------ accès

    public function test_the_page_is_open_to_superadmin_registrar_and_cashier_only(): void
    {
        $this->student('E1', 'Andria', 'Zo');
        foreach ([$this->super(), $this->registrar(), $this->cashier()] as $who) {
            $this->api($who)->getJson(self::BASE)->assertOk();
        }
        $denied = [$this->as(2, 'admin'), $this->as(5, 'media'), $this->as(6, 'chef_mention'), $this->as(7, 'teacher'), User::factory()->create(), $this->as(9, 'work_education'),
            User::factory()->staff(4, 'comptabilite')->inactive()->create()];
        foreach ($denied as $who) {
            $this->api($who)->getJson(self::BASE)->assertForbidden()->assertJsonPath('code', 'forbidden.reenrolment');
        }
    }

    public function test_an_anonymous_visitor_gets_401_everywhere(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->putJson(self::BASE.'/students/1/file', ['value' => true])->assertUnauthorized();
        $this->postJson(self::BASE.'/bulk', [])->assertUnauthorized();
        $this->postJson(self::BASE.'/reset', [])->assertUnauthorized();
    }

    public function test_the_session_payload_tells_what_each_account_can_write(): void
    {
        $this->api($this->cashier())->getJson('/api/v1/auth/me')->assertJsonPath('data.finance.reenrolment', ['authorization' => true, 'file' => false, 'reset' => false]);
        $this->api($this->registrar())->getJson('/api/v1/auth/me')->assertJsonPath('data.finance.reenrolment', ['authorization' => false, 'file' => true, 'reset' => false]);
        $this->api($this->super())->getJson('/api/v1/auth/me')->assertJsonPath('data.finance.reenrolment', ['authorization' => true, 'file' => true, 'reset' => true]);
        $this->api($this->as(5, 'media'))->getJson('/api/v1/auth/me')->assertJsonPath('data.finance', null);
    }

    // ------------------------------------------------------------------ liste

    public function test_the_list_shows_every_student_not_removed_with_both_flags_and_who_set_them(): void
    {
        $cashier = $this->cashier();
        $reg = $this->registrar();
        $a = $this->student('E1', 'Andria', 'Zo', ['authorized_reinscription' => 1, 'authorized_by' => $cashier->id, 'authorized_date' => '2026-09-12 10:30:00', 'dossier_ok' => 1, 'dossier_checked_by' => $reg->id, 'dossier_checked_date' => '2026-09-13 08:00:00']);
        $b = $this->student('E2', 'Rakoto', 'Jean', ['annee_etude' => '4', 'status' => 'Interne']);
        $this->student('E3', 'Retiré', 'Un', ['remove' => 1]);

        $r = $this->api($this->super())->getJson(self::BASE)->assertOk()->json('data');

        $this->assertSame(2, $r['total']);
        $this->assertSame([$b, $a], array_column($r['rows'], 'id'), 'matricule décroissant, comme l’ancienne page');
        $row = $r['rows'][1];
        $this->assertSame('prets', $row['state']);
        $this->assertTrue($row['authorization']['value']);
        $this->assertSame(trim($cashier->prenom.' '.$cashier->nom), $row['authorization']['by']);
        $this->assertSame('2026-09-12', substr($row['authorization']['at'], 0, 10));
        $this->assertSame(trim($reg->prenom.' '.$reg->nom), $row['file']['by']);
        $this->assertSame('Externe', $row['residence']);
        $this->assertSame('Licence 1', $row['level_label']);
        $second = $r['rows'][0];
        $this->assertSame('aucun', $second['state']);
        $this->assertFalse($second['authorization']['value']);
        $this->assertNull($second['authorization']['by']);
        $this->assertNull($second['authorization']['at']);
        $this->assertSame('Master 1', $second['level_label']);
        $this->assertSame('Interne', $second['residence']);
    }

    public function test_the_four_states_exclude_each_other_and_add_up_to_the_total(): void
    {
        $this->student('E1', 'A', 'a', ['authorized_reinscription' => 1, 'dossier_ok' => 1]);
        $this->student('E2', 'B', 'b', ['authorized_reinscription' => 0, 'dossier_ok' => 1]);
        $this->student('E3', 'C', 'c', ['authorized_reinscription' => 1, 'dossier_ok' => 0]);
        $this->student('E4', 'D', 'd');
        $this->student('E5', 'E', 'e', ['authorized_reinscription' => null, 'dossier_ok' => null]);   // NULL compte comme « non coché »
        $this->student('E6', 'F', 'f', ['remove' => 1, 'authorized_reinscription' => 1, 'dossier_ok' => 1]);

        $r = $this->api($this->super())->getJson(self::BASE)->json('data');
        $s = $r['stats'];
        $this->assertSame(['total' => 5, 'prets' => 1, 'attente_caisse' => 1, 'attente_dossier' => 1, 'aucun' => 2, 'autorises' => 2, 'dossiers_ok' => 2], $s);
        $this->assertSame($s['total'], $s['prets'] + $s['attente_caisse'] + $s['attente_dossier'] + $s['aucun']);

        $facet = collect($r['facets']['etat']['options'])->pluck('count', 'value')->all();
        $this->assertSame(['prets' => 1, 'attente_caisse' => 1, 'attente_dossier' => 1, 'aucun' => 2], $facet);

        foreach (['prets' => 1, 'attente_caisse' => 1, 'attente_dossier' => 1, 'aucun' => 2] as $state => $n) {
            $x = $this->api($this->super())->getJson(self::BASE.'?etat='.$state)->json('data');
            $this->assertSame($n, $x['total'], $state);
            $this->assertSame([$state], array_unique(array_column($x['rows'], 'state')), $state);
        }
        $both = $this->api($this->super())->getJson(self::BASE.'?etat[]=prets&etat[]=attente_caisse')->json('data');
        $this->assertSame(2, $both['total'], 'plusieurs états : OU');
    }

    public function test_filters_search_and_sort(): void
    {
        $this->student('E1', 'Andria', 'Zo', ['etude_envisage' => 'Informatique', 'annee_scolaire' => '2025-2026', 'annee_etude' => '1']);
        $this->student('E2', 'Rakoto', 'Jean', ['etude_envisage' => 'Gestion', 'etude_option' => 'Finance', 'annee_scolaire' => '2024-2025', 'annee_etude' => '2']);
        $this->student('E3', 'Éloïse', 'Marie', ['etude_envisage' => 'Gestion', 'etude_option' => 'Marketing', 'annee_scolaire' => '2025-2026', 'annee_etude' => '4']);
        $q = fn (string $qs) => $this->api($this->cashier())->getJson(self::BASE.$qs)->json('data');

        $this->assertSame(2, $q('?mention=Gestion')['total']);
        $this->assertSame(1, $q('?mention=Gestion&parcours=Finance')['total']);
        $this->assertSame(2, $q('?annee=2025-2026')['total']);
        $this->assertSame(1, $q('?niveau=4')['total']);
        $this->assertSame(['E3'], array_column($q('?q=eloise')['rows'], 'student_id'), 'la recherche ignore les accents et la casse');
        $this->assertSame(['E2'], array_column($q('?q=Finance')['rows'], 'student_id'), 'le parcours se cherche aussi');
        $this->assertSame(['E3', 'E2', 'E1'], array_column($q('')['rows'], 'student_id'));
        $this->assertSame(['E1', 'E3', 'E2'], array_column($q('?tri=nom')['rows'], 'student_id'));   // Andria, Éloïse, Rakoto
        $f = $q('?mention=Gestion')['facets'];
        $this->assertSame(['Finance' => 1, 'Marketing' => 1], collect($f['parcours']['options'])->pluck('count', 'value')->all());
    }

    public function test_the_list_has_no_cycle_or_mention_perimeter_like_the_legacy_page(): void
    {
        $this->student('E1', 'Andria', 'Zo', ['annee_etude' => '1']);
        $this->student('E2', 'Rakoto', 'Jean', ['annee_etude' => '5', 'etude_envisage' => 'Gestion']);
        $reg = User::factory()->staff(3, 'registrar')->create(['perimetre_cycle' => 'L']);

        $this->assertSame(2, $this->api($reg)->getJson(self::BASE)->json('data.total'));
    }

    public function test_rights_and_last_batch_are_part_of_the_list_response(): void
    {
        $this->student('E1', 'Andria', 'Zo');
        $r = $this->api($this->cashier())->getJson(self::BASE)->json('data');
        $this->assertSame(['authorization' => true, 'file' => false, 'reset' => false], $r['rights']);
        $this->assertNull($r['last_batch']);
        $this->assertTrue($r['batches_available']);
    }

    // ------------------------------------------------------------------ bascule simple

    public function test_the_cashier_sets_authorization_and_the_server_stamps_author_and_time(): void
    {
        $cashier = $this->cashier();
        $id = $this->student('E1', 'Andria', 'Zo');
        Carbon::setTestNow('2026-11-16 09:15:42');

        $r = $this->api($cashier)->putJson(self::BASE."/students/$id/authorization", ['value' => true])->assertOk()->json('data');

        $this->assertTrue($r['changed']);
        $this->assertSame('Étudiant autorisé pour la réinscription', $r['message']);
        $this->assertTrue($r['row']['authorization']['value']);
        $this->assertSame(trim($cashier->prenom.' '.$cashier->nom), $r['row']['authorization']['by']);
        $this->assertSame('attente_dossier', $r['row']['state']);
        $this->assertSame(1, $r['stats']['attente_dossier']);
        $f = $this->flags($id);
        $this->assertSame([1, $cashier->id, '2026-11-16 09:15:42', 0], [(int) $f->a, (int) $f->ab, $f->ad, (int) $f->d]);

        $h = $this->history($id);
        $this->assertCount(1, $h);
        $this->assertSame(['authorized_reinscription', 'Autorisation de réinscription', 'Non', 'Oui', 'modification', $cashier->id, 'E1'], [$h[0]->field_name, $h[0]->field_label, $h[0]->old_value, $h[0]->new_value, $h[0]->action_type, $h[0]->action_by, $h[0]->student_id]);
        $this->assertSame('2026-11-16 09:15:42', $h[0]->action_date);

        $this->assertSame(1, DB::table('t_audit_requete')->where('libelle', 'Réinscription · autorisation')->count());
    }

    public function test_the_registrar_validates_the_file_and_can_withdraw_it(): void
    {
        $reg = $this->registrar();
        $id = $this->student('E1', 'Andria', 'Zo', ['dossier_ok' => 1, 'dossier_checked_by' => 1, 'dossier_checked_date' => '2026-01-01 00:00:00']);

        $r = $this->api($reg)->putJson(self::BASE."/students/$id/file", ['value' => false])->assertOk()->json('data');
        $this->assertSame('Validation du dossier retirée', $r['message']);
        $this->assertSame([0, $reg->id], [(int) $this->flags($id)->d, (int) $this->flags($id)->db]);
        $h = $this->history($id);
        $this->assertSame(['dossier_ok', 'Dossier vérifié par le registraire', 'Oui', 'Non'], [$h[0]->field_name, $h[0]->field_label, $h[0]->old_value, $h[0]->new_value]);
    }

    public function test_each_role_only_touches_its_own_column(): void
    {
        $id = $this->student('E1', 'Andria', 'Zo');

        $this->api($this->registrar())->putJson(self::BASE."/students/$id/authorization", ['value' => true])->assertForbidden()->assertJsonPath('code', 'forbidden.reenrolment_field');
        $this->api($this->cashier())->putJson(self::BASE."/students/$id/file", ['value' => true])->assertForbidden()->assertJsonPath('code', 'forbidden.reenrolment_field');
        $this->assertSame([0, 0], [(int) $this->flags($id)->a, (int) $this->flags($id)->d]);

        $this->api($this->super())->putJson(self::BASE."/students/$id/authorization", ['value' => true])->assertOk();
        $this->api($this->super())->putJson(self::BASE."/students/$id/file", ['value' => true])->assertOk();
        $this->assertSame([1, 1], [(int) $this->flags($id)->a, (int) $this->flags($id)->d]);
    }

    public function test_an_account_without_page_rights_gets_403_before_any_validation(): void
    {
        $id = $this->student('E1', 'Andria', 'Zo');
        $this->api($this->as(2, 'admin'))->putJson(self::BASE."/students/$id/file", [])->assertForbidden()->assertJsonPath('code', 'forbidden.reenrolment');
        $this->api($this->as(2, 'admin'))->postJson(self::BASE.'/bulk', [])->assertForbidden();
        $this->api($this->as(2, 'admin'))->postJson(self::BASE.'/reset', [])->assertForbidden();
    }

    public function test_setting_a_flag_already_set_changes_nothing_and_keeps_who_and_when(): void
    {
        $cashier = $this->cashier();
        $other = $this->cashier();
        $id = $this->student('E1', 'Andria', 'Zo', ['authorized_reinscription' => 1, 'authorized_by' => $other->id, 'authorized_date' => '2026-05-05 05:05:05']);

        $r = $this->api($cashier)->putJson(self::BASE."/students/$id/authorization", ['value' => true])->assertOk()->json('data');

        $this->assertFalse($r['changed']);
        $f = $this->flags($id);
        $this->assertSame([$other->id, '2026-05-05 05:05:05'], [(int) $f->ab, $f->ad]);
        $this->assertSame([], $this->history($id));
        $this->assertSame(0, DB::table('t_audit_requete')->where('libelle', 'Réinscription · autorisation')->count());
    }

    public function test_a_null_flag_counts_as_unchecked(): void
    {
        $id = $this->student('E1', 'Andria', 'Zo', ['authorized_reinscription' => null]);
        $this->assertFalse($this->api($this->cashier())->putJson(self::BASE."/students/$id/authorization", ['value' => false])->json('data.changed'));
        $this->assertTrue($this->api($this->cashier())->putJson(self::BASE."/students/$id/authorization", ['value' => true])->json('data.changed'));
    }

    public function test_unknown_or_removed_student_is_404_and_bad_input_is_422(): void
    {
        $cashier = $this->cashier();
        $removed = $this->student('E1', 'Andria', 'Zo', ['remove' => 1]);
        $ok = $this->student('E2', 'Rakoto', 'Jean');

        $this->api($cashier)->putJson(self::BASE.'/students/999999/authorization', ['value' => true])->assertNotFound()->assertJsonPath('code', 'not_found.student');
        $this->api($cashier)->putJson(self::BASE."/students/$removed/authorization", ['value' => true])->assertNotFound();
        $this->api($cashier)->putJson(self::BASE."/students/$ok/authorization", [])->assertUnprocessable();
        $this->api($cashier)->putJson(self::BASE."/students/$ok/authorization", ['value' => 'peut-être'])->assertUnprocessable();
        $this->api($cashier)->putJson(self::BASE."/students/$ok/other", ['value' => true])->assertNotFound();
        $this->assertSame(0, (int) $this->flags($ok)->a);
    }

    // ------------------------------------------------------------------ action groupée

    public function test_bulk_changes_only_students_not_already_in_the_requested_state_in_one_history_trail(): void
    {
        $cashier = $this->cashier();
        $a = $this->student('E1', 'A', 'a');
        $b = $this->student('E2', 'B', 'b', ['authorized_reinscription' => 1, 'authorized_by' => 1, 'authorized_date' => '2026-02-02 02:02:02']);
        $c = $this->student('E3', 'C', 'c');
        $gone = $this->student('E4', 'D', 'd', ['remove' => 1]);

        $r = $this->api($cashier)->postJson(self::BASE.'/bulk', ['field' => 'authorization', 'value' => true, 'ids' => [$a, $b, $c, $gone, 987654, $a]])->assertOk()->json('data');

        $this->assertSame([$a, $c], $r['changed']);
        $this->assertSame(1, $r['unchanged']);
        $this->assertEqualsCanonicalizing([$gone, 987654], $r['missing']);
        $this->assertSame('2 autorisation(s) accordée(s).', $r['message']);
        $this->assertSame([1, 1, 1], [(int) $this->flags($a)->a, (int) $this->flags($b)->a, (int) $this->flags($c)->a]);
        $this->assertSame([$cashier->id, 1], [(int) $this->flags($a)->ab, (int) $this->flags($b)->ab], 'l’étudiant déjà autorisé garde son auteur');
        $this->assertSame('2026-02-02 02:02:02', $this->flags($b)->ad);
        $this->assertSame(0, (int) $this->flags($gone)->a);
        $this->assertCount(1, $this->history($a));
        $this->assertSame('Action groupée (2 étudiants)', $this->history($a)[0]->commentaire);
        $this->assertSame([], $this->history($b));
        $this->assertSame(1, DB::table('t_audit_requete')->where('libelle', 'Réinscription · autorisation (groupé)')->count());
        $this->assertSame(2, (int) DB::table('t_audit_modification')->where('table_nom', 'tbl_2024_etudiant')->orderByDesc('id')->value('lignes'));
    }

    public function test_a_bulk_action_can_be_undone_by_replaying_the_opposite_on_the_changed_students(): void
    {
        $reg = $this->registrar();
        $ids = [$this->student('E1', 'A', 'a'), $this->student('E2', 'B', 'b'), $this->student('E3', 'C', 'c', ['dossier_ok' => 1])];

        $do = $this->api($reg)->postJson(self::BASE.'/bulk', ['field' => 'file', 'value' => true, 'ids' => $ids])->json('data');
        $this->assertCount(2, $do['changed']);
        $undo = $this->api($reg)->postJson(self::BASE.'/bulk', ['field' => 'file', 'value' => false, 'ids' => $do['changed']])->assertOk()->json('data');

        $this->assertSame($do['changed'], $undo['changed']);
        $this->assertSame('2 validation(s) de dossier retirée(s).', $undo['message']);
        $this->assertSame([0, 0, 1], array_map(fn ($i) => (int) $this->flags($i)->d, $ids), 'celui qui l’était déjà n’est pas touché');
    }

    public function test_bulk_validation_and_rights(): void
    {
        $cashier = $this->cashier();
        $a = $this->student('E1', 'A', 'a');
        $many = range(1, Reenrolment::BULK_MAX + 1);

        $this->api($cashier)->postJson(self::BASE.'/bulk', ['field' => 'authorization', 'value' => true, 'ids' => $many])->assertUnprocessable();
        $this->api($cashier)->postJson(self::BASE.'/bulk', ['field' => 'authorization', 'value' => true, 'ids' => []])->assertUnprocessable();
        $this->api($cashier)->postJson(self::BASE.'/bulk', ['field' => 'colonne', 'value' => true, 'ids' => [$a]])->assertUnprocessable();
        $this->api($cashier)->postJson(self::BASE.'/bulk', ['field' => 'authorization', 'value' => true, 'ids' => ['x']])->assertUnprocessable();
        $this->api($cashier)->postJson(self::BASE.'/bulk', ['field' => 'file', 'value' => true, 'ids' => [$a]])->assertForbidden()->assertJsonPath('code', 'forbidden.reenrolment_field');
        $this->api($cashier)->postJson(self::BASE.'/bulk', ['field' => 'authorization', 'value' => true, 'ids' => array_slice($many, 0, Reenrolment::BULK_MAX)])->assertOk();
        $this->assertSame(0, (int) $this->flags($a)->a);
    }

    // ------------------------------------------------------------------ réinitialisation globale

    private function seedFlags(User $cashier, User $reg): array
    {
        return [
            'both' => $this->student('E1', 'A', 'a', ['authorized_reinscription' => 1, 'authorized_by' => $cashier->id, 'authorized_date' => '2026-03-01 10:00:00', 'dossier_ok' => 1, 'dossier_checked_by' => $reg->id, 'dossier_checked_date' => '2026-03-02 11:00:00']),
            'auth' => $this->student('E2', 'B', 'b', ['authorized_reinscription' => 1, 'authorized_by' => $cashier->id, 'authorized_date' => '2026-03-03 10:00:00']),
            'file' => $this->student('E3', 'C', 'c', ['dossier_ok' => 1, 'dossier_checked_by' => $reg->id, 'dossier_checked_date' => '2026-03-04 11:00:00']),
            'none' => $this->student('E4', 'D', 'd'),
            'gone' => $this->student('E5', 'E', 'e', ['remove' => 1, 'authorized_reinscription' => 1, 'dossier_ok' => 1]),
        ];
    }

    public function test_only_the_superadmin_can_preview_reset_or_undo(): void
    {
        $this->student('E1', 'A', 'a', ['authorized_reinscription' => 1]);
        foreach ([$this->cashier(), $this->registrar()] as $who) {
            $this->api($who)->getJson(self::BASE.'/reset/preview?columns[]=authorization')->assertForbidden()->assertJsonPath('code', 'forbidden.reenrolment_reset');
            $this->api($who)->postJson(self::BASE.'/reset', ['columns' => ['authorization'], 'confirmation' => 'REINITIALISER'])->assertForbidden()->assertJsonPath('code', 'forbidden.reenrolment_reset');
            $this->api($who)->postJson(self::BASE.'/reset/0123456789abcdef/undo')->assertForbidden();
        }
        $this->assertSame(1, (int) DB::table('tbl_2024_etudiant')->value('authorized_reinscription'));
        $this->assertSame(0, DB::table('t_reinscription_reinitialisation')->count());
    }

    public function test_the_preview_counts_what_would_change_and_who_would_be_blocked_without_writing(): void
    {
        $this->seedFlags($this->cashier(), $this->registrar());
        $super = $this->super();

        $r = $this->api($super)->getJson(self::BASE.'/reset/preview?columns[]=authorization&columns[]=file&columns[]=bogus')->assertOk()->json('data');
        $this->assertSame(['authorization' => 2, 'file' => 2], $r['by_field']);
        $this->assertSame(3, $r['students']);
        $this->assertSame(1, $r['blocked'], 'un seul étudiant pouvait se réinscrire');

        $r = $this->api($super)->getJson(self::BASE.'/reset/preview?columns[]=file')->json('data');
        $this->assertSame(['file' => 2], $r['by_field']);
        $this->assertSame(2, $r['students']);

        $r = $this->api($super)->getJson(self::BASE.'/reset/preview')->json('data');
        $this->assertSame([[], 0, 0], [$r['by_field'], $r['students'], $r['blocked']]);
        $this->assertSame(0, DB::table('t_reinscription_reinitialisation')->count());
        $this->assertSame(0, DB::table('t_student_modification_history')->count());
    }

    public function test_the_reset_needs_the_confirmation_phrase_and_a_known_column(): void
    {
        $this->seedFlags($this->cashier(), $this->registrar());
        $super = $this->super();

        foreach (['', 'oui', 'REINITIALISE', 'tout'] as $phrase) {
            $r = $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['authorization'], 'confirmation' => $phrase]);
            $r->assertUnprocessable();
        }
        $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['bogus'], 'confirmation' => 'REINITIALISER'])->assertUnprocessable()->assertJsonPath('code', 'validation.columns');
        $this->api($super)->postJson(self::BASE.'/reset', ['columns' => 'authorization', 'confirmation' => 'REINITIALISER'])->assertUnprocessable();
        $this->assertSame(0, DB::table('t_reinscription_reinitialisation')->count());
        $this->assertSame(3, DB::table('tbl_2024_etudiant')->where('remove', 0)->where(fn ($q) => $q->where('authorized_reinscription', 1)->orWhere('dossier_ok', 1))->count());
    }

    public function test_the_confirmation_phrase_tolerates_case_accents_and_spaces(): void
    {
        $this->assertTrue(Reenrolment::confirmed('REINITIALISER'));
        $this->assertTrue(Reenrolment::confirmed('réinitialiser'));
        $this->assertTrue(Reenrolment::confirmed('  RÉINITIALISER '));
        $this->assertTrue(Reenrolment::confirmed('Réinitialiser'));
        $this->assertFalse(Reenrolment::confirmed('REINITIALISE'));
        $this->assertFalse(Reenrolment::confirmed('REINITIALISER TOUT'));
        $this->assertFalse(Reenrolment::confirmed(''));
    }

    public function test_the_reset_snapshots_traces_and_clears_only_the_chosen_column_for_students_not_removed(): void
    {
        $cashier = $this->cashier();
        $reg = $this->registrar();
        $ids = $this->seedFlags($cashier, $reg);
        $super = $this->super();
        Carbon::setTestNow('2026-11-16 14:00:07');

        $r = $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['authorization'], 'confirmation' => 'réinitialiser'])->assertOk()->json('data');

        $this->assertSame(['authorization' => 2], $r['by_field']);
        $this->assertSame(2, $r['total']);
        $this->assertSame('2 case(s) remise(s) à « non coché ». Vous pouvez annuler depuis le bandeau.', $r['message']);
        $this->assertSame('16/11/2026', $r['date']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $r['lot']);
        $this->assertSame(['prets' => 0, 'attente_caisse' => 2, 'attente_dossier' => 0, 'aucun' => 2], array_intersect_key($r['stats'], array_flip(['prets', 'attente_caisse', 'attente_dossier', 'aucun'])));

        foreach (['both', 'auth'] as $k) {
            $f = $this->flags($ids[$k]);
            $this->assertSame([0, $super->id, '2026-11-16 14:00:07'], [(int) $f->a, (int) $f->ab, $f->ad], $k);
        }
        $this->assertSame(1, (int) $this->flags($ids['both'])->d, 'l’autre colonne n’est pas touchée');
        $this->assertSame(1, (int) $this->flags($ids['gone'])->a, 'un étudiant retiré n’est pas touché');
        $none = $this->flags($ids['none']);
        $this->assertSame([0, null, null], [(int) $none->a, $none->ab, $none->ad], 'un étudiant non coché garde ses colonnes d’auteur et de date telles quelles');
        $file = $this->flags($ids['file']);
        $this->assertSame([0, null, null, 1, $reg->id, '2026-03-04 11:00:00'], [(int) $file->a, $file->ab, $file->ad, (int) $file->d, (int) $file->db, $file->dd], 'l’autre colonne reste intacte');

        $snap = DB::table('t_reinscription_reinitialisation')->where('lot', $r['lot'])->orderBy('student_db_id')->get();
        $this->assertCount(2, $snap);
        $this->assertSame(['authorized_reinscription', 1, $cashier->id, '2026-03-01 10:00:00', $super->id, '2026-11-16 14:00:07', null], [
            $snap[0]->colonne, (int) $snap[0]->ancienne_valeur, (int) $snap[0]->ancien_par, $snap[0]->ancienne_date, (int) $snap[0]->fait_par, $snap[0]->fait_le, $snap[0]->annule_le,
        ]);

        $h = $this->history($ids['both']);
        $this->assertSame(['authorized_reinscription', 'Oui', 'Non', 'Réinitialisation globale (lot '.$r['lot'].')'], [$h[0]->field_name, $h[0]->old_value, $h[0]->new_value, $h[0]->commentaire]);
        $this->assertSame([], $this->history($ids['none']));

        $audit = DB::table('t_audit_modification')->where('table_nom', 'tbl_2024_etudiant')->orderByDesc('id')->first();
        $this->assertSame(2, (int) $audit->lignes);
    }

    public function test_both_columns_reset_in_one_batch_and_the_last_batch_is_offered_for_undo(): void
    {
        $this->seedFlags($this->cashier(), $this->registrar());
        $super = $this->super();

        $r = $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['authorization', 'file'], 'confirmation' => 'REINITIALISER'])->assertOk()->json('data');
        $this->assertSame(['authorization' => 2, 'file' => 2], $r['by_field']);
        $this->assertSame(4, $r['total']);
        $this->assertSame(4, $r['stats']['aucun'], 'les quatre étudiants non retirés sont tous « ni l’un ni l’autre »');

        $list = $this->api($super)->getJson(self::BASE)->json('data');
        $this->assertSame($r['lot'], $list['last_batch']['lot']);
        $this->assertSame(4, $list['last_batch']['rows']);
        $this->assertSame(['authorization', 'file'], $list['last_batch']['columns']);
        $this->assertSame(trim($super->prenom.' '.$super->nom), $list['last_batch']['by']);

        // Les autres comptes ne voient pas le bandeau d'annulation.
        $this->assertNull($this->api($this->cashier())->getJson(self::BASE)->json('data.last_batch'));
    }

    public function test_the_undo_restores_value_author_and_date_exactly_and_logs_it(): void
    {
        $cashier = $this->cashier();
        $reg = $this->registrar();
        $ids = $this->seedFlags($cashier, $reg);
        $super = $this->super();
        $lot = $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['authorization', 'file'], 'confirmation' => 'REINITIALISER'])->json('data.lot');

        $r = $this->api($super)->postJson(self::BASE."/reset/$lot/undo")->assertOk()->json('data');

        $this->assertSame(0, $r['ignored']);
        $this->assertSame('4 case(s) restaurée(s) à leur état d’avant la réinitialisation.', $r['message']);
        $this->assertEqualsCanonicalizing([$ids['both'], $ids['auth']], $r['restored']['authorization']);
        $this->assertEqualsCanonicalizing([$ids['both'], $ids['file']], $r['restored']['file']);
        $f = $this->flags($ids['both']);
        $this->assertSame([1, $cashier->id, '2026-03-01 10:00:00', 1, $reg->id, '2026-03-02 11:00:00'], [(int) $f->a, (int) $f->ab, $f->ad, (int) $f->d, (int) $f->db, $f->dd]);
        $this->assertSame(['prets' => 1, 'attente_caisse' => 1, 'attente_dossier' => 1, 'aucun' => 1], array_intersect_key($r['stats'], array_flip(['prets', 'attente_caisse', 'attente_dossier', 'aucun'])));
        $this->assertSame(0, DB::table('t_reinscription_reinitialisation')->where('lot', $lot)->whereNull('annule_le')->count());
        $this->assertSame($super->id, (int) DB::table('t_reinscription_reinitialisation')->where('lot', $lot)->value('annule_par'));
        $h = $this->history($ids['both']);
        $this->assertCount(4, $h, 'deux colonnes × (réinitialisation + annulation)');
        $this->assertSame('Annulation de la réinitialisation globale (lot '.$lot.')', end($h)->commentaire);
        $undone = array_values(array_filter($h, fn ($x) => str_starts_with((string) $x->commentaire, 'Annulation')));
        $this->assertSame([['Non', 'Oui'], ['Non', 'Oui']], array_map(fn ($x) => [$x->old_value, $x->new_value], $undone), 'l’annulation remet « Oui »');

        // Une fois annulé, le lot n'est plus proposé, et l'annuler encore ne restaure rien.
        $this->assertNull($this->api($super)->getJson(self::BASE)->json('data.last_batch'));
        $again = $this->api($super)->postJson(self::BASE."/reset/$lot/undo")->assertOk()->json('data');
        $this->assertSame([[], 0], [$again['restored'], $again['ignored']]);
        $this->assertSame(1, (int) $this->flags($ids['both'])->a);
    }

    public function test_the_undo_does_not_overwrite_what_a_human_changed_since(): void
    {
        $cashier = $this->cashier();
        $reg = $this->registrar();
        $ids = $this->seedFlags($cashier, $reg);
        $super = $this->super();
        Carbon::setTestNow('2026-11-16 14:00:00');
        $lot = $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['authorization'], 'confirmation' => 'REINITIALISER'])->json('data.lot');

        // Après la réinitialisation, la caisse ré-autorise l'un des deux : l'annulation ne doit pas écraser ce geste, ni le dupliquer pour l'autre.
        Carbon::setTestNow('2026-11-16 14:05:00');
        $this->api($cashier)->putJson(self::BASE."/students/{$ids['both']}/authorization", ['value' => true])->assertOk();

        $r = $this->api($super)->postJson(self::BASE."/reset/$lot/undo")->assertOk()->json('data');

        $this->assertSame(1, $r['ignored']);
        $this->assertSame([$ids['auth']], $r['restored']['authorization']);
        $this->assertStringContainsString('1 laissée(s) telle(s) quelle(s)', $r['message']);
        $f = $this->flags($ids['both']);
        $this->assertSame([1, $cashier->id, '2026-11-16 14:05:00'], [(int) $f->a, (int) $f->ab, $f->ad], 'le geste de la caisse est conservé');
        $this->assertSame([1, $cashier->id, '2026-03-03 10:00:00'], [(int) $this->flags($ids['auth'])->a, (int) $this->flags($ids['auth'])->ab, $this->flags($ids['auth'])->ad]);
    }

    public function test_the_undo_checks_value_author_and_date_one_by_one(): void
    {
        $cashier = $this->cashier();
        $ids = [];
        foreach (['untouched', 'value', 'author', 'date'] as $i => $k) {
            $ids[$k] = $this->student('E'.($i + 1), 'N'.$i, 'n', ['authorized_reinscription' => 1, 'authorized_by' => $cashier->id, 'authorized_date' => '2026-03-01 10:00:00']);
        }
        $super = $this->super();
        Carbon::setTestNow('2026-11-16 14:00:00');
        $lot = $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['authorization'], 'confirmation' => 'REINITIALISER'])->json('data.lot');

        // Après la réinitialisation, trois étudiants sont repassés : à chaque fois UNE SEULE des trois marques du lot (valeur, auteur, date) a changé.
        DB::table('tbl_2024_etudiant')->where('id', $ids['value'])->update(['authorized_reinscription' => 1]);                       // valeur : 1, mais auteur et date du lot
        DB::table('tbl_2024_etudiant')->where('id', $ids['author'])->update(['authorized_by' => $cashier->id]);                       // auteur : la caisse, mais valeur 0 et date du lot
        DB::table('tbl_2024_etudiant')->where('id', $ids['date'])->update(['authorized_date' => '2026-11-16 14:00:01']);               // date : une seconde plus tard, mais valeur 0 et auteur du lot

        $r = $this->api($super)->postJson(self::BASE."/reset/$lot/undo")->assertOk()->json('data');

        $this->assertSame([$ids['untouched']], $r['restored']['authorization']);
        $this->assertSame(3, $r['ignored']);
        $this->assertSame([1, $cashier->id, '2026-03-01 10:00:00'], [(int) $this->flags($ids['untouched'])->a, (int) $this->flags($ids['untouched'])->ab, $this->flags($ids['untouched'])->ad]);
        $this->assertSame([1, $super->id, '2026-11-16 14:00:00'], [(int) $this->flags($ids['value'])->a, (int) $this->flags($ids['value'])->ab, $this->flags($ids['value'])->ad]);
        $this->assertSame([0, $cashier->id], [(int) $this->flags($ids['author'])->a, (int) $this->flags($ids['author'])->ab]);
        $this->assertSame([0, '2026-11-16 14:00:01'], [(int) $this->flags($ids['date'])->a, $this->flags($ids['date'])->ad]);
    }

    public function test_the_offered_batch_is_the_most_recent_one_not_yet_undone(): void
    {
        $this->seedFlags($this->cashier(), $this->registrar());
        $super = $this->super();
        Carbon::setTestNow('2026-11-16 10:00:00');
        $first = $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['authorization'], 'confirmation' => 'REINITIALISER'])->json('data.lot');
        Carbon::setTestNow('2026-11-16 11:00:00');
        $second = $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['file'], 'confirmation' => 'REINITIALISER'])->json('data.lot');
        $this->assertNotSame($first, $second);

        $this->assertSame($second, $this->api($super)->getJson(self::BASE)->json('data.last_batch.lot'));
        $this->api($super)->postJson(self::BASE."/reset/$second/undo")->assertOk();
        $this->assertSame($first, $this->api($super)->getJson(self::BASE)->json('data.last_batch.lot'), 'le lot annulé n’est plus proposé : le précédent reprend la place');
    }

    public function test_the_domain_enforces_the_bulk_size_and_counts_distinct_students_only(): void
    {
        $super = $this->super();
        $domain = app(Reenrolment::class);
        $ids = [];
        for ($i = 0; $i < Reenrolment::BULK_MAX; $i++) {
            $ids[] = $this->student('B'.$i, 'N', 'n');
        }

        $r = $domain->toggleMany($super, array_merge($ids, $ids), 'authorization', true);   // 200 entrées, 100 étudiants distincts : accepté
        $this->assertCount(Reenrolment::BULK_MAX, $r['changed']);

        foreach ([[], array_merge($ids, [$this->student('B100', 'N', 'n')])] as $bad) {
            try {
                $domain->toggleMany($super, $bad, 'authorization', true);
                $this->fail('une sélection vide ou de plus de '.Reenrolment::BULK_MAX.' étudiants doit être refusée');
            } catch (ApiProblem $e) {
                $this->assertSame([422, 'validation.bulk_size'], [$e->status, $e->problemCode]);
            }
        }
    }

    public function test_the_directory_engine_refuses_the_list_to_accounts_without_rights_on_its_own(): void
    {
        $this->student('E1', 'A', 'a');
        $this->expectException(ApiProblem::class);
        app(Directory::class)->listing($this->as(2, 'admin'), 'reenrolment', []);
    }

    public function test_a_stale_batch_is_no_longer_offered_but_can_still_be_undone_by_its_id(): void
    {
        $this->seedFlags($this->cashier(), $this->registrar());
        $super = $this->super();
        Carbon::setTestNow('2026-11-01 08:00:00');
        $lot = $this->api($super)->postJson(self::BASE.'/reset', ['columns' => ['authorization'], 'confirmation' => 'REINITIALISER'])->json('data.lot');

        Carbon::setTestNow('2026-11-08 07:59:59');
        $this->assertSame($lot, $this->api($super)->getJson(self::BASE)->json('data.last_batch.lot'), 'six jours et vingt-trois heures plus tard : encore proposé');
        Carbon::setTestNow('2026-11-08 08:00:01');
        $this->assertNull($this->api($super)->getJson(self::BASE)->json('data.last_batch'), 'au-delà de sept jours : plus proposé');
    }

    public function test_undo_rejects_unknown_or_malformed_batches(): void
    {
        $super = $this->super();
        $this->api($super)->postJson(self::BASE.'/reset/0123456789abcdef/undo')->assertNotFound()->assertJsonPath('code', 'not_found.batch');
        $this->api($super)->postJson(self::BASE.'/reset/NOT-A-BATCH/undo')->assertNotFound();   // l'adresse elle-même ne correspond pas
        $this->api($super)->postJson(self::BASE.'/reset/abc/undo')->assertUnprocessable()->assertJsonPath('code', 'validation.batch');   // adresse valide, mais pas un identifiant de lot
    }

    public function test_a_batch_that_nothing_matched_is_reported_as_empty(): void
    {
        $this->student('E1', 'A', 'a');
        $r = $this->api($this->super())->postJson(self::BASE.'/reset', ['columns' => ['file'], 'confirmation' => 'REINITIALISER'])->assertOk()->json('data');
        $this->assertSame([0, 'Rien à réinitialiser : aucun étudiant n’était coché.'], [$r['total'], $r['message']]);
        $this->assertSame(0, DB::table('t_audit_requete')->where('libelle', 'Réinscription · réinitialisation globale')->count(), 'rien n’a changé : pas de ligne de journal');
    }
}
