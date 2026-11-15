<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\AttendanceRules as R;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceRulesTest extends TestCase
{
    private const LEGACY = 'C:/laragon/www/registrar/data/presence.php';

    // Correspondance des états de l'ancien code vers les nôtres.
    private const STATES = ['ok' => 'ok', 'limite' => 'limit', 'depasse' => 'exceeded', 'inconnu' => 'unknown'];

    private function loadLegacy(): void
    {
        if (! is_file(self::LEGACY)) {
            $this->markTestSkipped('Ancien dépôt absent : comparaison impossible.');
        }
        require_once self::LEGACY;
    }

    /** @return array<string, array{0: ?string, 1: int|string|null, 2: ?string}> */
    public static function categories(): array
    {
        return [
            'présent' => ['present', null, 'present'],
            'absent' => ['absent', null, 'absence'],
            'non pointé' => [null, null, null],
            'statut inconnu' => ['inconnu', null, null],
            'retard sans durée = 5 min' => ['retard', null, 'retard'],
            'retard durée vide' => ['retard', '', 'retard'],
            'retard 1 min : à l\'heure' => ['retard', 1, 'present'],
            'retard 4 min : à l\'heure' => ['retard', 4, 'present'],
            'retard 5 min : retard' => ['retard', 5, 'retard'],
            'retard 9 min : retard' => ['retard', 9, 'retard'],
            'retard 10 min : absence' => ['retard', 10, 'absence'],
            'retard 12 min : absence' => ['retard', 12, 'absence'],
            'retard 0 min' => ['retard', 0, 'present'],
        ];
    }

    #[DataProvider('categories')]
    public function test_a_record_counts_by_duration_not_by_button(?string $status, int|string|null $minutes, ?string $expected): void
    {
        $this->assertSame($expected, R::category($status, $minutes));
    }

    #[DataProvider('categories')]
    public function test_category_matches_the_legacy_function(?string $status, int|string|null $minutes): void
    {
        $this->loadLegacy();
        $this->assertSame(presenceCategorie($status, $minutes), R::category($status, $minutes));
        $this->assertSame(presenceLibellePointage($status, $minutes), R::recordLabel($status, $minutes));
    }

    /** @return array<string, array{0: mixed, 1: ?int}> */
    public static function allowances(): array
    {
        return [
            'inconnu' => [null, null], 'zéro' => [0, null], 'négatif' => [-3, null], 'chaîne vide' => ['', null],
            '6 séances' => [6, 0], '7 séances' => [7, 1], '13 séances' => [13, 1], '20 séances (15 % pile)' => [20, 3],
            '30 séances' => [30, 4], '40 séances' => [40, 6], '100 séances' => [100, 15],
        ];
    }

    #[DataProvider('allowances')]
    public function test_allowed_absences_are_fifteen_percent_rounded_down(mixed $planned, ?int $expected): void
    {
        $this->assertSame($expected, R::allowedAbsences($planned));
    }

    public function test_state_boundaries_follow_the_bulletin_examples(): void
    {
        $abs = fn (int $n) => array_fill(0, $n, ['status' => 'absent', 'minutes_late' => null, 'no_penalty' => false]);

        $this->assertSame('ok', R::summary($abs(3), 30)['state']);        // 4 permises
        $this->assertSame('limit', R::summary($abs(4), 30)['state']);     // à la limite
        $this->assertSame('exceeded', R::summary($abs(5), 30)['state']);  // 16,7 % : dépasse
        $this->assertSame('limit', R::summary($abs(3), 20)['state']);     // 15 % pile : permis
        $this->assertSame('exceeded', R::summary($abs(4), 20)['state']);
        $this->assertSame('unknown', R::summary($abs(9), null)['state']);
        $this->assertSame('ok', R::summary([], 6)['state'], '0 permise et 0 absence : jamais « à la limite »');
        $this->assertSame('exceeded', R::summary($abs(1), 6)['state'], '0 permise : la première absence dépasse');
    }

    public function test_two_lates_make_one_absence_and_an_odd_late_is_pending(): void
    {
        $late = fn () => ['status' => 'retard', 'minutes_late' => 7, 'no_penalty' => false];

        $one = R::summary([$late()], 20);
        $this->assertSame([0, true], [$one['counted'], $one['late_pending']]);

        $two = R::summary([$late(), $late()], 20);
        $this->assertSame([1, false, 1], [$two['counted'], $two['late_pending'], $two['converted_lates']]);

        $three = R::summary([$late(), $late(), $late()], 20);
        $this->assertSame([1, true], [$three['counted'], $three['late_pending']]);
    }

    public function test_teacher_lateness_cancels_penalties_only_beyond_ten_minutes_without_warning(): void
    {
        $this->assertSame(10, R::teacherLateMinutes('08:00:00', '08:10:00'));
        $this->assertFalse(R::teacherLateWithoutPenalty('08:00:00', '08:10:00', 0), 'exactement 10 min : encore pénalisé');
        $this->assertTrue(R::teacherLateWithoutPenalty('08:00:00', '08:11:00', 0));
        $this->assertFalse(R::teacherLateWithoutPenalty('08:00:00', '08:30:00', 1), 'l\'enseignant a prévenu');
        $this->assertFalse(R::teacherLateWithoutPenalty('08:00:00', null, 0), 'heure d\'arrivée inconnue');
        $this->assertFalse(R::teacherLateWithoutPenalty(null, '08:30:00', 0), 'heure prévue inconnue');
        $this->assertSame(0, R::teacherLateMinutes('09:00:00', '08:50:00'), 'arrivé en avance : jamais négatif');
    }

    public function test_a_session_without_penalty_counts_neither_as_late_nor_absent(): void
    {
        $s = R::summary([
            ['status' => 'absent', 'minutes_late' => null, 'no_penalty' => true],
            ['status' => 'retard', 'minutes_late' => 8, 'no_penalty' => true],
            ['status' => 'present', 'minutes_late' => null, 'no_penalty' => true],
        ], 20);

        $this->assertSame([3, 1, 0, 0, 2, 0], [$s['recorded'], $s['present'], $s['late'], $s['absent'], $s['no_penalty'], $s['counted']]);
    }

    public function test_semester_labels(): void
    {
        $this->assertSame('Semestre 1', R::semesterLabel(1));
        $this->assertSame('Semestre 2', R::semesterLabel('2'));
        $this->assertSame("Semestre d'été", R::semesterLabel(3));
        $this->assertSame("Semestre d'hiver", R::semesterLabel(4));
        $this->assertSame('Tous semestres', R::semesterLabel(null));
    }

    /** Le plus fort : des centaines de scénarios aléatoires, ancien code et nouveau code, résultat identique champ par champ. */
    public function test_summary_matches_the_legacy_presenceBilan_on_random_scenarios(): void
    {
        $this->loadLegacy();
        mt_srand(20261114);
        $statuses = ['present', 'retard', 'absent', null];
        $mismatches = [];

        for ($i = 0; $i < 500; $i++) {
            $records = [];
            for ($n = mt_rand(0, 25); $n > 0; $n--) {
                $status = $statuses[mt_rand(0, 3)];
                $minutes = $status === 'retard' ? [null, '', 0, 3, 5, 7, 9, 10, 15][mt_rand(0, 8)] : null;
                $records[] = ['status' => $status, 'minutes_late' => $minutes, 'no_penalty' => mt_rand(0, 9) === 0];
            }
            $planned = [null, 0, 5, 6, 7, 13, 20, 30, 45][mt_rand(0, 8)];

            $legacyRecords = array_map(fn ($r) => ['statut' => $r['status'], 'minutes_retard' => $r['minutes_late'], 'sans_penalite' => $r['no_penalty']], $records);
            $old = presenceBilan($legacyRecords, $planned);
            $new = R::summary($records, $planned);

            $pairs = [
                'recorded' => 'pointees', 'present' => 'presences', 'late' => 'retards', 'absent' => 'absences', 'no_penalty' => 'sans_penalite',
                'converted_lates' => 'retards_convertis', 'counted' => 'comptees', 'late_pending' => 'retard_en_attente',
                'planned' => 'prevues', 'allowed' => 'permises', 'rate' => 'taux',
            ];
            foreach ($pairs as $mine => $theirs) {
                if ($new[$mine] !== $old[$theirs]) {
                    $mismatches[] = "scénario {$i} : {$mine} = ".json_encode($new[$mine]).' / ancien '.json_encode($old[$theirs]);
                }
            }
            if ($new['state'] !== self::STATES[$old['etat']]) {
                $mismatches[] = "scénario {$i} : état {$new['state']} / ancien {$old['etat']}";
            }

            // La jauge de l'ancienne page : comptées / (permises + 1), pleine quand on dépasse.
            $legacyGauge = $old['permises'] === null ? 0
                : ($old['etat'] === 'depasse' ? 100 : min(100, (int) round($old['comptees'] * 100 / max(1, $old['permises'] + 1))));
            if ($new['gauge'] !== $legacyGauge) {
                $mismatches[] = "scénario {$i} : jauge {$new['gauge']} / ancien {$legacyGauge}";
            }
        }

        $this->assertSame([], array_slice($mismatches, 0, 5), count($mismatches).' écart(s) sur 500 scénarios');
    }

    public function test_teacher_lateness_matches_the_legacy_functions_on_random_times(): void
    {
        $this->loadLegacy();
        mt_srand(474);
        for ($i = 0; $i < 300; $i++) {
            $planned = mt_rand(0, 4) === 0 ? null : sprintf('%02d:%02d:%02d', mt_rand(7, 17), mt_rand(0, 59), mt_rand(0, 59));
            $arrival = mt_rand(0, 4) === 0 ? null : sprintf('%02d:%02d:%02d', mt_rand(7, 18), mt_rand(0, 59), mt_rand(0, 59));
            $warned = mt_rand(0, 1);
            $legacy = ['heure_prevue' => $planned, 'arrivee_enseignant' => $arrival, 'message_retard' => $warned];

            $this->assertSame(presenceRetardEnseignant($legacy), R::teacherLateMinutes($planned, $arrival), "{$planned} → {$arrival}");
            $this->assertSame(presenceSeanceSansPenalite($legacy), R::teacherLateWithoutPenalty($planned, $arrival, $warned), "{$planned} → {$arrival} ({$warned})");
        }
    }
}
