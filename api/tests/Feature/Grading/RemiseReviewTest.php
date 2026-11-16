<?php

namespace Tests\Feature\Grading;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesAttendanceData;
use Tests\Concerns\MakesStudentData;
use Tests\Concerns\MakesTeacherData;
use Tests\TestCase;

/** Validation des remises de notes par le registrariat : seul chemin qui écrit dans les notes, annulable 10 secondes. */
class RemiseReviewTest extends TestCase
{
    use MakesAttendanceData;
    use MakesStudentData;
    use MakesTeacherData;

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function registrar(int $level = 3): User
    {
        return User::factory()->staff($level, match ($level) { 1 => 'superadmin', 2 => 'admin', default => 'registrar' })->create();
    }

    /**
     * Une feuille TRANSMISE : les étudiants du cours, avec leurs cinq notes (ou un statut spécial).
     *
     * @param  array<string, list<float>|string|null>  $grades  matricule => 5 notes | 'V'/'E'/'NN' | null (feuille vierge)
     */
    private function submitted(int $course, int $uid, array $grades, string $status = 'soumise', ?string $year = null): int
    {
        $remise = DB::table('t_remise_notes')->insertGetId([
            'cours_id' => $course, 'sigle' => 'INF 101', 'titre_cours' => 'Intro', 'teacher_uid' => $uid, 'annee_scolaire' => $year ?? $this->year, 'semestre' => 1,
            'statut' => $status, 'soumis_le' => '2026-11-10 10:00:00', 'modifie_le' => '2026-11-10 10:00:00',
        ]);
        foreach ($grades as $student => $g) {
            $note = DB::table('t_2023_notes')->where('id_cours', (string) $course)->where('student_id', $student)->value('id');
            $row = ['remise_id' => $remise, 'note_id' => $note, 'student_id' => $student, 'modifie_le' => '2026-11-10 09:00:00'];
            if (is_array($g)) {
                $row += ['quiz' => $g[0], 'recherche' => $g[1], 'autres' => $g[2], 'mi_semestre' => $g[3], 'examen_final' => $g[4]];
            } elseif (is_string($g)) {
                $row['statut_special'] = $g;
            }
            DB::table('t_remise_notes_ligne')->insert($row);
        }

        return $remise;
    }

    /** Une feuille complète pour trois étudiants : 15,1 / 12 / V. @return array{0: int, 1: int, 2: int} remise, cours, uid */
    private function ready(): array
    {
        [, $course, $uid] = $this->classroom();

        return [$this->submitted($course, $uid, ['E001' => [10, 12, 14, 16, 18], 'E002' => [12, 12, 12, 12, 12], 'E003' => 'V']), $course, $uid];
    }

    private function grade(int $course, string $student): ?string
    {
        return DB::table('t_2023_notes')->where('id_cours', (string) $course)->where('student_id', $student)->value('grade');
    }

    /** Demande la validation puis laisse passer le délai d'annulation et finalise. */
    private function validate(User $reg, int $remise)
    {
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();
        $this->travel(11)->seconds();

        return $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/finalize");
    }

    // ------------------------------------------------------------------ accès

    public function test_only_the_registrariat_can_review(): void
    {
        [$remise] = $this->ready();
        [$teacher] = $this->makeTeacher();
        $inactive = User::factory()->staff(3, 'registrar')->inactive()->create();
        $accounting = User::factory()->staff(4, 'comptabilite')->create();

        foreach ([$teacher, User::factory()->create(), $inactive, $accounting] as $who) {
            $this->api($who)->getJson('/api/v1/review/remises')->assertForbidden();
            $this->api($who)->getJson("/api/v1/review/remises/{$remise}")->assertForbidden();
            $this->api($who)->postJson("/api/v1/review/remises/{$remise}/approve")->assertForbidden();
            $this->api($who)->postJson("/api/v1/review/remises/{$remise}/return", ['reason' => 'x'])->assertForbidden();
        }
        foreach ([1, 2, 3] as $level) {
            $this->api($this->registrar($level))->getJson('/api/v1/review/remises')->assertOk();
        }
        $this->assertNull($this->grade(DB::table('t_remise_notes')->value('cours_id'), 'E001'));
    }

    public function test_an_anonymous_visitor_is_asked_to_log_in(): void
    {
        $this->getJson('/api/v1/review/remises')->assertUnauthorized();
    }

    public function test_a_teacher_cannot_validate_their_own_sheet(): void
    {
        [$remise] = $this->ready();
        $teacher = User::query()->where('user_type', 'teacher')->first() ?? $this->makeTeacher()[0];

        $this->api($teacher)->postJson("/api/v1/review/remises/{$remise}/approve")->assertForbidden()->assertJsonPath('code', 'forbidden.registrar_only');
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count());
    }

    // ----------------------------------------------------------------- la file

    public function test_the_queue_lists_sheets_to_validate_first_with_their_readiness(): void
    {
        [$remise, $course, $uid] = $this->ready();
        $draft = DB::table('t_remise_notes')->insertGetId(['cours_id' => $course, 'teacher_uid' => $uid, 'annee_scolaire' => '2025 - 2026', 'semestre' => 1, 'statut' => 'brouillon']);
        $done = DB::table('t_remise_notes')->insertGetId(['cours_id' => $course, 'teacher_uid' => $uid, 'annee_scolaire' => '2024 - 2025', 'semestre' => 1, 'statut' => 'validee']);

        $res = $this->api($this->registrar())->getJson('/api/v1/review/remises')->assertOk();

        $this->assertSame([$remise, $draft, $done], array_column($res->json('data.items'), 'id'), 'à valider, puis brouillon, puis validée');
        $this->assertSame([3, 3, 0], [$res->json('data.items.0.summary.ready'), $res->json('data.items.0.summary.total'), $res->json('data.items.0.summary.blockers')]);
        $this->assertNull($res->json('data.items.1.summary'));
        $this->assertEquals(['soumise' => 1, 'brouillon' => 1, 'validee' => 1], $res->json('data.counts'));
        $this->assertSame(['2026 - 2027', '2025 - 2026', '2024 - 2025'], $res->json('data.years'));
        $this->assertSame('Andry Rasoa', $res->json('data.items.0.teacher'));
    }

    public function test_the_queue_can_be_filtered_by_status_and_year(): void
    {
        [$remise, $course, $uid] = $this->ready();
        DB::table('t_remise_notes')->insert(['cours_id' => $course, 'teacher_uid' => $uid, 'annee_scolaire' => '2025 - 2026', 'semestre' => 1, 'statut' => 'rejetee', 'motif_rejet' => 'Refaire']);
        $reg = $this->registrar();

        $this->assertSame([$remise], array_column($this->api($reg)->getJson('/api/v1/review/remises?status=soumise')->json('data.items'), 'id'));
        $this->assertSame(['rejetee'], array_column($this->api($reg)->getJson('/api/v1/review/remises?status=rejetee')->json('data.items'), 'status'));
        $this->assertSame(['2025 - 2026'], array_column($this->api($reg)->getJson('/api/v1/review/remises?year='.rawurlencode('2025 - 2026'))->json('data.items'), 'year'));
        $this->assertCount(2, $this->api($reg)->getJson('/api/v1/review/remises?status=nimporte')->json('data.items'), 'un statut inconnu est ignoré');
        $this->api($reg)->getJson('/api/v1/review/remises?year=nimporte')->assertStatus(422);
    }

    // ------------------------------------------------------------------ détail

    public function test_the_detail_shows_what_the_validation_would_write_against_what_is_there(): void
    {
        [$remise, $course] = $this->ready();
        DB::table('t_2023_notes')->where('id_cours', (string) $course)->where('student_id', 'E002')->update(['grade' => '12']);   // déjà la bonne note

        $d = $this->api($this->registrar())->getJson("/api/v1/review/remises/{$remise}")->assertOk()->json('data');

        $lines = collect($d['lines'])->keyBy('student_id');
        $this->assertSame([null, '15.1', true], [$lines['E001']['current_grade'], $lines['E001']['new_grade'], $lines['E001']['linked']]);
        $this->assertSame(['12', '12'], [$lines['E002']['current_grade'], $lines['E002']['new_grade']]);
        $this->assertSame('-2', $lines['E003']['new_grade']);
        $this->assertSame([2, true, 'soumise', null], [$d['changes'], $d['can_approve'], $d['status'], $d['pending']]);
        $this->assertSame(['Quiz', 'Recherche', 'Autres', 'Examen mi-sem.', 'Examen final'], array_column($d['components'], 'label'));
        $this->assertEquals(15.1, $lines['E001']['note']['value']);
    }

    public function test_a_line_without_a_note_is_shown_but_never_written(): void
    {
        [$remise, $course] = $this->ready();
        DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->update(['note_id' => null]);

        $d = $this->api($this->registrar())->getJson("/api/v1/review/remises/{$remise}")->json('data');
        $this->validate($this->registrar(), $remise)->assertOk()->assertJsonPath('data.written', 2);

        $this->assertSame([false, null], [collect($d['lines'])->firstWhere('student_id', 'E001')['linked'], collect($d['lines'])->firstWhere('student_id', 'E001')['new_grade']], 'rien à écrire : aucune note proposée');
        $this->assertNull($this->grade($course, 'E001'));
    }

    public function test_an_unknown_sheet_is_not_found(): void
    {
        $this->api($this->registrar())->getJson('/api/v1/review/remises/999999')->assertNotFound()->assertJsonPath('code', 'review.not_found');
        $this->api($this->registrar())->postJson('/api/v1/review/remises/999999/approve')->assertNotFound();
    }

    // ------------------------------------------------- demander et annuler (10 s)

    public function test_approving_writes_no_grade_it_only_opens_the_cancellation_window(): void
    {
        [$remise, $course] = $this->ready();

        $res = $this->api($this->registrar())->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();

        $this->assertSame(10, $res->json('data.seconds'));
        $this->assertGreaterThan(8000, $res->json('data.remaining_ms'));
        $this->assertLessThanOrEqual(10000, $res->json('data.remaining_ms'));
        $this->assertSame([null, null, null], [$this->grade($course, 'E001'), $this->grade($course, 'E002'), $this->grade($course, 'E003')]);
        $this->assertSame('soumise', DB::table('t_remise_notes')->where('id', $remise)->value('statut'));
        $this->assertSame(0, DB::table('t_notes_modification_history')->count());
        $this->assertSame(1, DB::table('t_remise_validation_attente')->count());
    }

    public function test_asking_twice_keeps_the_first_deadline(): void
    {
        [$remise] = $this->ready();
        $reg = $this->registrar();

        $first = $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->json('data.until');
        $this->travel(4)->seconds();
        $second = $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();

        $this->assertSame($first, $second->json('data.until'));
        $this->assertSame(1, DB::table('t_remise_validation_attente')->count());
    }

    public function test_undoing_within_the_window_leaves_everything_as_it_was(): void
    {
        [$remise, $course] = $this->ready();
        $reg = $this->registrar();
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();
        $this->travel(5)->seconds();

        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/undo")->assertOk()->assertJsonPath('data.undone', true);
        $this->travel(30)->seconds();

        $this->assertSame(0, DB::table('t_remise_validation_attente')->count());
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/finalize")->assertStatus(409)->assertJsonPath('code', 'review.nothing_pending');
        $this->assertSame(['soumise', null], [DB::table('t_remise_notes')->where('id', $remise)->value('statut'), $this->grade($course, 'E001')]);
        $this->assertSame(0, Artisan::call('remises:finalize-due'));
    }

    public function test_undoing_after_the_window_is_too_late(): void
    {
        [$remise] = $this->ready();
        $reg = $this->registrar();
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();
        $this->travel(11)->seconds();

        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/undo")->assertStatus(409)->assertJsonPath('code', 'review.too_late');
        $this->assertSame(1, DB::table('t_remise_validation_attente')->count());
    }

    public function test_undoing_when_nothing_was_asked_is_refused(): void
    {
        [$remise] = $this->ready();

        $this->api($this->registrar())->postJson("/api/v1/review/remises/{$remise}/undo")->assertStatus(409)->assertJsonPath('code', 'review.nothing_to_undo');
    }

    public function test_finalizing_before_the_deadline_is_refused_with_the_time_left(): void
    {
        [$remise, $course] = $this->ready();
        $reg = $this->registrar();
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();
        $this->travel(3)->seconds();

        $res = $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/finalize")->assertStatus(409)->assertJsonPath('code', 'review.not_due');

        $this->assertGreaterThan(5000, $res->json('remaining_ms'));
        $this->assertNull($this->grade($course, 'E001'));
    }

    public function test_a_sheet_that_blocks_cannot_be_approved(): void
    {
        [, $course, $uid] = $this->classroom();
        $remise = $this->submitted($course, $uid, ['E001' => [10, null, null, null, null], 'E002' => [12, 12, 12, 12, 12], 'E003' => null]);

        $res = $this->api($this->registrar())->postJson("/api/v1/review/remises/{$remise}/approve")->assertStatus(422)->assertJsonPath('code', 'review.not_ready');

        $this->assertStringContainsString('composante pondérée non saisie', implode(' ', $res->json('check.blockers')));
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count());
        $this->assertFalse($this->api($this->registrar())->getJson("/api/v1/review/remises/{$remise}")->json('data.can_approve'));
    }

    public function test_only_a_submitted_sheet_can_be_approved(): void
    {
        [, $course, $uid] = $this->classroom();
        foreach (['brouillon' => '2020 - 2021', 'rejetee' => '2021 - 2022', 'validee' => '2022 - 2023'] as $status => $year) {
            $remise = $this->submitted($course, $uid, ['E001' => [10, 12, 14, 16, 18]], $status, $year);

            $this->api($this->registrar())->postJson("/api/v1/review/remises/{$remise}/approve")->assertStatus(409)->assertJsonPath('code', 'review.not_submitted');
        }
    }

    // ------------------------------------------------------------- la validation

    public function test_the_validation_writes_every_grade_with_its_history(): void
    {
        [$remise, $course] = $this->ready();
        $reg = $this->registrar();

        $res = $this->validate($reg, $remise)->assertOk();

        $this->assertSame(['validee', 3, false], [$res->json('data.status'), $res->json('data.written'), $res->json('data.already')]);
        $this->assertSame(['15.1', '12', '-2'], [$this->grade($course, 'E001'), $this->grade($course, 'E002'), $this->grade($course, 'E003')]);
        $r = DB::table('t_remise_notes')->where('id', $remise)->first();
        $this->assertSame(['validee', $reg->id, null], [$r->statut, (int) $r->traite_par, $r->motif_rejet]);
        $this->assertNotNull($r->traite_le);
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count());

        $h = DB::table('t_notes_modification_history')->orderBy('student_id')->get();
        $this->assertSame(['E001', 'E002', 'E003'], $h->pluck('student_id')->all());
        $this->assertSame([null, '15.10', 'creation', $reg->id], [$h[0]->old_grade, $h[0]->new_grade, $h[0]->action_type, (int) $h[0]->action_by]);
        $this->assertSame('-2.00', $h[2]->new_grade);
        $this->assertSame('Validation de la remise #'.$remise.' de INF 101 ('.$this->year.')', $h[0]->commentaire);
        $n = DB::table('t_2023_notes')->where('id_cours', (string) $course)->where('student_id', 'E001')->first();
        $this->assertSame([(string) $reg->id], [$n->last_change_user_id]);
        $this->assertNotNull($n->last_change_datetime);
        $j = DB::table('t_remise_notes_journal')->where('action', 'validation')->first();
        $this->assertSame(['statut', 'soumise', 'validee — 3 note(s) transferee(s)', $reg->id], [$j->champ, $j->ancienne_valeur, $j->nouvelle_valeur, (int) $j->auteur_id]);
    }

    public function test_special_statuses_and_blank_lines_become_codes(): void
    {
        [, $course, $uid] = $this->classroom();
        $remise = $this->submitted($course, $uid, ['E001' => 'E', 'E002' => 'NN', 'E003' => null]);

        $this->validate($this->registrar(), $remise)->assertOk();

        $this->assertSame(['-3', '-4', '-4'], [$this->grade($course, 'E001'), $this->grade($course, 'E002'), $this->grade($course, 'E003')], 'une feuille vierge devient « pas encore noté »');
    }

    public function test_a_validation_course_is_written_as_validated_or_failed(): void
    {
        [, $course, $uid] = $this->classroom();
        DB::table('t_2023_notes')->where('id_cours', (string) $course)->update(['cours_category' => 5]);
        $remise = $this->submitted($course, $uid, ['E001' => [9, 9, 9, 9, 9], 'E002' => [10, 10, 10, 10, 10], 'E003' => [20, 20, 20, 20, 20]]);

        $this->validate($this->registrar(), $remise)->assertOk();

        $this->assertSame(['-3', '-2', '-2'], [$this->grade($course, 'E001'), $this->grade($course, 'E002'), $this->grade($course, 'E003')]);
    }

    public function test_a_course_titled_spiritual_formation_is_written_as_validated_or_failed(): void
    {
        [, $course, $uid] = $this->classroom();
        DB::table('t_2023_notes')->where('id_cours', (string) $course)->update(['title_cours' => 'Formation spirituelle II']);   // catégorie 1, mais le titre dit tout
        $remise = $this->submitted($course, $uid, ['E001' => [9, 9, 9, 9, 9], 'E002' => [15, 15, 15, 15, 15], 'E003' => 'NN']);

        $this->validate($this->registrar(), $remise)->assertOk();

        $this->assertSame(['-3', '-2', '-4'], [$this->grade($course, 'E001'), $this->grade($course, 'E002'), $this->grade($course, 'E003')]);
    }

    public function test_validating_clears_an_old_reason_for_return(): void
    {
        [$remise] = $this->ready();
        DB::table('t_remise_notes')->where('id', $remise)->update(['motif_rejet' => 'Ancien motif de renvoi']);

        $this->validate($this->registrar(), $remise)->assertOk();

        $this->assertNull(DB::table('t_remise_notes')->where('id', $remise)->value('motif_rejet'));
    }

    public function test_the_domain_itself_requires_and_trims_the_reason(): void
    {
        [$remise] = $this->ready();
        $review = app(\App\Domain\Grading\RemiseReview::class);
        $reg = $this->registrar();

        foreach (['', '   ', "\n\t"] as $blank) {
            try {
                $review->reject($reg, $remise, $blank);
                $this->fail('motif vide accepté');
            } catch (\App\Exceptions\ApiProblem $e) {
                $this->assertSame('review.reason_required', $e->problemCode);
            }
        }
        $this->assertSame('soumise', DB::table('t_remise_notes')->where('id', $remise)->value('statut'));

        $review->reject($reg, $remise, "  \n À corriger \t ");
        $this->assertSame('À corriger', DB::table('t_remise_notes')->where('id', $remise)->value('motif_rejet'));
    }

    public function test_a_grade_that_does_not_change_is_neither_written_nor_historised(): void
    {
        [$remise, $course] = $this->ready();
        DB::table('t_2023_notes')->where('id_cours', (string) $course)->where('student_id', 'E002')->update(['grade' => '12', 'last_change_user_id' => 'avant']);

        $this->validate($this->registrar(), $remise)->assertOk()->assertJsonPath('data.written', 2);

        $this->assertSame('avant', DB::table('t_2023_notes')->where('id_cours', (string) $course)->where('student_id', 'E002')->value('last_change_user_id'));
        $this->assertSame(['E001', 'E003'], DB::table('t_notes_modification_history')->orderBy('student_id')->pluck('student_id')->all());
    }

    public function test_replacing_an_existing_grade_is_a_modification_and_keeps_the_old_value(): void
    {
        [$remise, $course] = $this->ready();
        DB::table('t_2023_notes')->where('id_cours', (string) $course)->where('student_id', 'E001')->update(['grade' => '8.5']);
        DB::table('t_2023_notes')->where('id_cours', (string) $course)->where('student_id', 'E003')->update(['grade' => 'abandon']);   // ancienne saisie libre

        $this->validate($this->registrar(), $remise)->assertOk();

        $h = DB::table('t_notes_modification_history')->orderBy('student_id')->get()->keyBy('student_id');
        $this->assertSame(['modification', '8.50'], [$h['E001']->action_type, $h['E001']->old_grade]);
        $this->assertSame(['modification', null], [$h['E003']->action_type, $h['E003']->old_grade], 'une valeur non numérique ne fait pas échouer l’historique');
        $this->assertSame('-2', $this->grade($course, 'E003'));
    }

    public function test_finalizing_twice_validates_once(): void
    {
        [$remise] = $this->ready();
        $reg = $this->registrar();
        $this->validate($reg, $remise)->assertOk();

        $again = $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/finalize")->assertOk();

        $this->assertTrue($again->json('data.already'));
        $this->assertSame(3, DB::table('t_notes_modification_history')->count());
        $this->assertSame(1, DB::table('t_remise_notes_journal')->where('action', 'validation')->count());
    }

    public function test_the_validation_is_all_or_nothing(): void
    {
        [$remise, $course] = $this->ready();
        $reg = $this->registrar();
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();
        $this->travel(11)->seconds();
        // La feuille a changé entre la demande et la fin du délai (l'enseignant l'a reprise) : rien ne doit être écrit.
        DB::table('t_remise_notes_ligne')->where('student_id', 'E002')->update(['quiz' => null]);

        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/finalize")->assertStatus(422)->assertJsonPath('code', 'review.not_ready');

        $this->assertSame([null, null, null], [$this->grade($course, 'E001'), $this->grade($course, 'E002'), $this->grade($course, 'E003')]);
        $this->assertSame(['soumise', 0], [DB::table('t_remise_notes')->where('id', $remise)->value('statut'), DB::table('t_notes_modification_history')->count()]);
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count(), 'la demande périmée est levée');
    }

    public function test_a_sheet_returned_in_the_meantime_is_not_validated(): void
    {
        [$remise, $course] = $this->ready();
        $reg = $this->registrar();
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();
        DB::table('t_remise_notes')->where('id', $remise)->update(['statut' => 'rejetee']);   // l'ancienne application l'a renvoyée
        $this->travel(11)->seconds();

        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/finalize")->assertStatus(409)->assertJsonPath('code', 'review.not_submitted');
        $this->assertNull($this->grade($course, 'E001'));
    }

    public function test_a_free_column_sheet_is_validated_with_its_own_weights(): void
    {
        [, $course, $uid] = $this->classroom();
        $remise = $this->submitted($course, $uid, ['E001' => null, 'E002' => null, 'E003' => null]);
        foreach ([[1007, 'Devoir', 40], [1008, 'Examen', 60]] as $i => [$slot, $label, $weight]) {
            DB::table('t_remise_notes_composante')->insert(['remise_id' => $remise, 'slot' => $slot, 'position' => $i + 1, 'libelle' => $label, 'poids' => $weight]);
        }
        foreach (['E001' => [10, 15], 'E002' => [20, 0], 'E003' => [12, 18]] as $student => [$a, $b]) {
            $line = DB::table('t_remise_notes_ligne')->where('remise_id', $remise)->where('student_id', $student)->value('id');
            DB::table('t_remise_notes_valeur')->insert([['ligne_id' => $line, 'slot' => 1007, 'valeur' => $a], ['ligne_id' => $line, 'slot' => 1008, 'valeur' => $b]]);
        }

        $d = $this->api($this->registrar())->getJson("/api/v1/review/remises/{$remise}")->json('data');
        $this->validate($this->registrar(), $remise)->assertOk();

        $this->assertSame(['Devoir', 'Examen'], array_column($d['components'], 'label'));
        $this->assertSame(['13', '8', '15.6'], [$this->grade($course, 'E001'), $this->grade($course, 'E002'), $this->grade($course, 'E003')]);   // 10×0,4+15×0,6 ; 20×0,4+0×0,6 ; 12×0,4+18×0,6
    }

    // --------------------------------------------------------------------- renvoi

    public function test_returning_a_sheet_needs_a_reason_and_hands_it_back_to_the_teacher(): void
    {
        [$remise, $course] = $this->ready();
        $reg = $this->registrar();
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();

        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/return", ['reason' => '   '])->assertStatus(422);
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/return", [])->assertStatus(422);
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/return", ['reason' => '  Le quiz de E001 est faux.  '])->assertOk()->assertJsonPath('data.status', 'rejetee');

        $r = DB::table('t_remise_notes')->where('id', $remise)->first();
        $this->assertSame(['rejetee', 'Le quiz de E001 est faux.', $reg->id], [$r->statut, $r->motif_rejet, (int) $r->traite_par]);
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count(), 'une demande de validation en cours est levée');
        $this->assertNull($this->grade($course, 'E001'));
        $j = DB::table('t_remise_notes_journal')->where('action', 'rejet')->first();
        $this->assertSame('rejetee — Le quiz de E001 est faux.', $j->nouvelle_valeur);
    }

    public function test_only_a_submitted_sheet_can_be_returned(): void
    {
        [, $course, $uid] = $this->classroom();
        $remise = $this->submitted($course, $uid, ['E001' => [10, 12, 14, 16, 18]], 'validee');

        $this->api($this->registrar())->postJson("/api/v1/review/remises/{$remise}/return", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'review.not_submitted');
    }

    public function test_a_returned_sheet_goes_back_to_the_teacher_who_corrects_and_resubmits(): void
    {
        [$teacher, $course] = $this->classroom();
        $reg = $this->registrar();
        foreach (['E001', 'E002', 'E003'] as $s) {
            foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $i => $c) {
                $this->api($teacher)->putJson("/api/v1/teacher/grading/courses/{$course}/cells/{$s}", $this->base() + ['field' => $c, 'value' => 10 + $i])->assertOk();
            }
        }
        $this->api($teacher)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();
        $remise = DB::table('t_remise_notes')->value('id');

        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/return", ['reason' => 'Corrigez le quiz.'])->assertOk();
        $this->api($teacher)->putJson("/api/v1/teacher/grading/courses/{$course}/cells/E001", $this->base() + ['field' => 'c1', 'value' => 19])->assertOk();
        $this->api($teacher)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();
        $res = $this->validate($reg, $remise)->assertOk();

        $this->assertSame(3, $res->json('data.written'));
        $this->assertSame('13.45', $this->grade($course, 'E001'), '19×0,1 + 11×0,2 + 12×0,1 + 13×0,25 + 14×0,35');
        $this->assertSame('12.55', $this->grade($course, 'E002'), '10×0,1 + 11×0,2 + 12×0,1 + 13×0,25 + 14×0,35');
    }

    // ------------------------------------------------- journal et commande planifiée

    public function test_the_validation_is_logged_in_the_shared_activity_log(): void
    {
        [$remise] = $this->ready();
        $reg = $this->registrar();

        $this->validate($reg, $remise)->assertOk();

        $labels = DB::table('t_audit_requete')->orderBy('id')->pluck('libelle')->all();
        $this->assertSame(['Validation des notes · demande', 'Validation des notes · validation'], $labels);
        $last = DB::table('t_audit_requete')->orderByDesc('id')->first();
        $this->assertSame($reg->id, (int) $last->user_id);
        $this->assertStringContainsString(',t_2023_notes,', $last->tables_touchees);
        $this->assertStringContainsString(',t_notes_modification_history,', $last->tables_touchees);
        $this->assertSame(3, (int) DB::table('t_audit_modification')->where('requete_id', $last->id)->where('table_nom', 't_2023_notes')->value('lignes') + 2, 'une modification journalisée par note écrite');
    }

    public function test_the_scheduled_command_validates_due_requests_only(): void
    {
        [$remise, $course, $uid] = $this->ready();
        $other = $this->submitted($course, $uid, ['E001' => [10, 10, 10, 10, 10]], 'soumise', '2025 - 2026');
        $reg = $this->registrar();
        $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->assertOk();
        $this->travel(8)->seconds();
        $this->api($reg)->postJson("/api/v1/review/remises/{$other}/approve")->assertOk();
        $this->travel(4)->seconds();   // la première est échue (12 s), la seconde pas (4 s)

        $this->assertSame(0, Artisan::call('remises:finalize-due'));

        $this->assertSame(['validee', 'soumise'], [DB::table('t_remise_notes')->where('id', $remise)->value('statut'), DB::table('t_remise_notes')->where('id', $other)->value('statut')]);
        $this->assertSame('15.1', $this->grade($course, 'E001'));
        $this->assertSame(1, DB::table('t_remise_validation_attente')->count());
        $log = DB::table('t_audit_requete')->where('libelle', 'Validation des notes · validation (différée)')->first();
        $this->assertSame($reg->id, (int) $log->user_id, 'au nom du compte qui avait demandé');
        $this->assertSame(0, Artisan::call('remises:finalize-due'), 'rejouée, elle ne refait rien');
        $this->assertSame(3, DB::table('t_notes_modification_history')->count());
    }

    // ------------------------------------------------------------------ validation groupée

    private function bulk(User $who, string $action, array $ids)
    {
        return $this->api($who)->postJson("/api/v1/review/remises/bulk/{$action}", ['ids' => $ids]);
    }

    /** Une feuille prête (15,1 / 12 / V) pour un autre cours. */
    private function readyToo(): array
    {
        return $this->ready();
    }

    public function test_bulk_approve_opens_one_undoable_request_per_ready_sheet_and_writes_no_grade(): void
    {
        [$a, $courseA] = $this->ready();
        [$b, $courseB] = $this->readyToo();
        $reg = $this->registrar();

        $d = $this->bulk($reg, 'approve', [$a, $b])->assertOk()->json('data');

        $this->assertSame([$a, $b], array_column($d['approved'], 'id'));
        $this->assertSame([], $d['skipped']);
        $this->assertSame(10, $d['seconds']);
        $this->assertSame(2, DB::table('t_remise_validation_attente')->count(), 'une demande par feuille');
        $this->assertSame([null, null], [$this->grade($courseA, 'E001'), $this->grade($courseB, 'E001')], 'aucune note écrite avant la fin du délai');
        $this->assertSame(['soumise', 'soumise'], [DB::table('t_remise_notes')->where('id', $a)->value('statut'), DB::table('t_remise_notes')->where('id', $b)->value('statut')]);
        foreach ($d['approved'] as $item) {
            $this->assertGreaterThan(8000, $item['remaining_ms']);
        }
    }

    public function test_a_sheet_that_cannot_go_is_returned_with_its_reason_and_does_not_stop_the_others(): void
    {
        [$ok] = $this->ready();
        [, $course, $uid] = $this->classroom();
        $blocked = $this->submitted($course, $uid, ['E001' => [10, null, null, null, null]]);       // incomplète
        [, $c3, $u3] = $this->classroom();
        $draft = $this->submitted($c3, $u3, ['E001' => [10, 10, 10, 10, 10]], 'brouillon');
        $reg = $this->registrar();

        $d = $this->bulk($reg, 'approve', [$blocked, $ok, $draft, 999999])->assertOk()->json('data');

        $this->assertSame([$ok], array_column($d['approved'], 'id'));
        $codes = collect($d['skipped'])->pluck('code', 'id')->all();
        $this->assertSame([$blocked => 'review.not_ready', $draft => 'review.not_submitted', 999999 => 'review.not_found'], $codes);
        $skipped = collect($d['skipped'])->keyBy('id');
        $this->assertSame(['Incohérences à lever', 'Remise non transmise', 'Remise introuvable'], [$skipped[$blocked]['title'], $skipped[$draft]['title'], $skipped[999999]['title']]);
        $this->assertSame('Seule une remise transmise peut être validée.', $skipped[$draft]['detail']);
        $this->assertSame([$ok], DB::table('t_remise_validation_attente')->pluck('remise_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_only_the_registrariat_can_use_the_bulk_endpoints(): void
    {
        [$remise] = $this->ready();
        [$teacher] = $this->makeTeacher('Zo', 'Rabe');

        foreach ([$teacher, User::factory()->create(), User::factory()->staff(3, 'registrar')->inactive()->create(), User::factory()->staff(4, 'comptabilite')->create()] as $who) {
            foreach (['approve', 'undo', 'finalize'] as $action) {
                $this->bulk($who, $action, [$remise])->assertForbidden();
            }
        }
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count());
    }

    public function test_the_bulk_endpoints_require_a_session(): void
    {
        foreach (['approve', 'undo', 'finalize'] as $action) {
            $this->postJson("/api/v1/review/remises/bulk/{$action}", ['ids' => [1]])->assertUnauthorized();
        }
    }

    public function test_the_selection_must_be_between_one_and_the_maximum(): void
    {
        [$remise] = $this->ready();
        $reg = $this->registrar();

        foreach (['approve', 'undo', 'finalize'] as $action) {
            $this->api($reg)->postJson("/api/v1/review/remises/bulk/{$action}", [])->assertUnprocessable();
            $this->bulk($reg, $action, [])->assertUnprocessable();
            $this->bulk($reg, $action, range(1, 26))->assertUnprocessable();
            $this->bulk($reg, $action, ['x'])->assertUnprocessable();
            $this->bulk($reg, $action, [0])->assertUnprocessable();
        }
        $this->bulk($reg, 'approve', range(1, 25))->assertOk();   // 25 : accepté (les feuilles inconnues sont simplement rendues)
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count());

        $this->assertSame([$remise], array_column($this->bulk($reg, 'approve', [$remise, $remise, (string) $remise])->json('data.approved'), 'id'), 'les doublons ne comptent qu’une fois');
    }

    public function test_the_domain_also_checks_the_selection_and_the_access(): void
    {
        $review = app(\App\Domain\Grading\RemiseReview::class);

        foreach ([[$this->registrar(), []], [$this->registrar(), range(1, 26)], [User::factory()->staff(4, 'comptabilite')->create(), [1]]] as [$who, $ids]) {
            try {
                $review->approveMany($who, $ids);
                $this->fail('sélection acceptée');
            } catch (\App\Exceptions\ApiProblem $e) {
                $this->assertContains($e->status, [403, 422]);
            }
        }
    }

    public function test_bulk_undo_cancels_the_requests_still_in_their_delay(): void
    {
        [$a, $course] = $this->ready();
        [$b] = $this->readyToo();
        [$c] = $this->readyToo();
        $reg = $this->registrar();
        $this->bulk($reg, 'approve', [$a, $b])->assertOk();

        $d = $this->bulk($reg, 'undo', [$a, $b, $c])->assertOk()->json('data');

        $this->assertSame([$a, $b], array_column($d['undone'], 'id'));
        $this->assertSame([[$c, 'review.nothing_to_undo']], array_map(fn ($s) => [$s['id'], $s['code']], $d['skipped']));
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count());
        $this->travel(15)->seconds();
        $this->assertSame(0, Artisan::call('remises:finalize-due'));
        $this->assertNull($this->grade($course, 'E001'), 'annulée : rien ne se valide, même après le délai');
    }

    public function test_bulk_undo_is_refused_per_sheet_once_the_delay_is_over(): void
    {
        [$a] = $this->ready();
        [$b] = $this->readyToo();
        $reg = $this->registrar();
        $this->bulk($reg, 'approve', [$a])->assertOk();
        $this->travel(6)->seconds();
        $this->bulk($reg, 'approve', [$b])->assertOk();
        $this->travel(5)->seconds();   // a : 11 s (échue) ; b : 5 s

        $d = $this->bulk($reg, 'undo', [$a, $b])->assertOk()->json('data');

        $this->assertSame([$b], array_column($d['undone'], 'id'));
        $this->assertSame([[$a, 'review.too_late']], array_map(fn ($s) => [$s['id'], $s['code']], $d['skipped']));
    }

    public function test_bulk_finalize_validates_every_due_sheet_and_writes_the_grades(): void
    {
        [$a, $courseA] = $this->ready();
        [$b, $courseB] = $this->readyToo();
        $reg = $this->registrar();
        $this->bulk($reg, 'approve', [$a, $b])->assertOk();
        $this->travel(11)->seconds();

        $d = $this->bulk($reg, 'finalize', [$a, $b])->assertOk()->json('data');

        $this->assertSame([$a, $b], array_column($d['validated'], 'id'));
        $this->assertSame([], $d['skipped']);
        $this->assertSame(['validee', 'validee'], [DB::table('t_remise_notes')->where('id', $a)->value('statut'), DB::table('t_remise_notes')->where('id', $b)->value('statut')]);
        $this->assertSame(['15.1', '12', '-2', '15.1', '12', '-2'], [
            $this->grade($courseA, 'E001'), $this->grade($courseA, 'E002'), $this->grade($courseA, 'E003'),
            $this->grade($courseB, 'E001'), $this->grade($courseB, 'E002'), $this->grade($courseB, 'E003'),
        ]);
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count());
        $this->assertSame(6, DB::table('t_notes_modification_history')->count());
    }

    public function test_bulk_finalize_leaves_a_sheet_whose_delay_is_still_running_and_says_how_long(): void
    {
        [$a] = $this->ready();
        [$b] = $this->readyToo();
        $reg = $this->registrar();
        $this->bulk($reg, 'approve', [$a])->assertOk();
        $this->travel(7)->seconds();
        $this->bulk($reg, 'approve', [$b])->assertOk();
        $this->travel(4)->seconds();   // a : 11 s ; b : 4 s

        $d = $this->bulk($reg, 'finalize', [$a, $b])->assertOk()->json('data');

        $this->assertSame([$a], array_column($d['validated'], 'id'));
        $this->assertSame($b, $d['skipped'][0]['id']);
        $this->assertSame('review.not_due', $d['skipped'][0]['code']);
        $this->assertEqualsWithDelta(6000, $d['skipped'][0]['remaining_ms'], 1500);
        $this->assertSame('soumise', DB::table('t_remise_notes')->where('id', $b)->value('statut'));
        $this->assertSame(1, DB::table('t_remise_validation_attente')->where('remise_id', $b)->count(), 'la demande de b reste intacte');
    }

    public function test_a_sheet_sent_back_during_the_delay_is_not_validated_but_the_others_are(): void
    {
        [$a, $courseA] = $this->ready();
        [$b, $courseB] = $this->readyToo();
        $reg = $this->registrar();
        $this->bulk($reg, 'approve', [$a, $b])->assertOk();
        DB::table('t_remise_notes')->where('id', $a)->update(['statut' => 'rejetee', 'motif_rejet' => 'Corrigez']);
        $this->travel(11)->seconds();

        $d = $this->bulk($reg, 'finalize', [$a, $b])->assertOk()->json('data');

        $this->assertSame([$b], array_column($d['validated'], 'id'));
        $this->assertSame($a, $d['skipped'][0]['id']);
        $this->assertSame([null, '15.1'], [$this->grade($courseA, 'E001'), $this->grade($courseB, 'E001')]);
        $this->assertSame(0, DB::table('t_remise_validation_attente')->count(), 'la demande périmée de a est levée');
    }

    public function test_finalizing_the_same_sheets_twice_validates_them_once(): void
    {
        [$a] = $this->ready();
        $reg = $this->registrar();
        $this->bulk($reg, 'approve', [$a])->assertOk();
        $this->travel(11)->seconds();
        $this->bulk($reg, 'finalize', [$a])->assertOk();

        $d = $this->bulk($reg, 'finalize', [$a])->assertOk()->json('data');

        $this->assertSame([true], array_column($d['validated'], 'already'));
        $this->assertSame(3, DB::table('t_notes_modification_history')->count(), 'rien n’est réécrit');
    }

    public function test_each_bulk_request_leaves_one_activity_log_entry(): void
    {
        [$a] = $this->ready();
        [$b] = $this->readyToo();
        $reg = $this->registrar();
        $this->bulk($reg, 'approve', [$a, $b])->assertOk();
        $this->travel(11)->seconds();
        $this->bulk($reg, 'finalize', [$a, $b])->assertOk();

        $this->assertSame(['Validation des notes · demande groupée', 'Validation des notes · validation groupée'], DB::table('t_audit_requete')->orderBy('id')->pluck('libelle')->all());
        $log = DB::table('t_audit_requete')->where('libelle', 'Validation des notes · demande groupée')->first();
        $this->assertSame(2, DB::table('t_audit_modification')->where('requete_id', $log->id)->where('table_nom', 't_remise_validation_attente')->count(), 'une trace par demande ouverte');
    }

    public function test_responses_are_never_cached(): void
    {
        [$remise] = $this->ready();
        $reg = $this->registrar();

        $this->assertStringContainsString('no-store', (string) $this->api($reg)->getJson('/api/v1/review/remises')->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->api($reg)->getJson("/api/v1/review/remises/{$remise}")->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->api($reg)->postJson("/api/v1/review/remises/{$remise}/approve")->headers->get('Cache-Control'));
    }
}
