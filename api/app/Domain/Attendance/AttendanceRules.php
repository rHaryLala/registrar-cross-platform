<?php

namespace App\Domain\Attendance;

/**
 * Règles d'assiduité du Bulletin académique (§472 à §476), port fidèle de data/presence.php.
 *
 * §476, pour un étudiant et un cours :
 *   - moins de 5 minutes de retard : à l'heure ;
 *   - de 5 à 9 minutes : un retard, et DEUX retards comptent comme UNE absence ;
 *   - 10 minutes ou plus : une absence ;
 *   - au-delà de 15 % des séances prévues, il doit quitter le cours et le registraire en est informé.
 *     Ni une inscription tardive ni une maladie n'excusent l'absence ; un certificat médical permet
 *     seulement de refaire les tests et examens manqués.
 * §474 : si l'enseignant arrive plus de 10 minutes après l'heure prévue sans avoir prévenu, les étudiants
 *   peuvent partir sans pénalité : les absences et retards de cette séance ne comptent pas.
 *
 * C'est la DURÉE du retard qui décide, pas le bouton choisi : un « retard » de 12 minutes est une absence,
 * un « retard » de 3 minutes n'en est pas un. Un retard sans durée compte pour 5 minutes.
 */
final class AttendanceRules
{
    public const LATE_TOLERATED = 5;    // en dessous : à l'heure

    public const LATE_AS_ABSENCE = 10;  // à partir de : une absence

    public const MAX_RATE = 15;         // % des séances prévues

    public const TEACHER_WAIT = 10;     // minutes d'attente de l'enseignant (§474)

    /** @return 'present'|'retard'|'absence'|null null = non pointé */
    public static function category(?string $status, int|string|null $minutes = null): ?string
    {
        return match ($status) {
            'present' => 'present',
            'absent' => 'absence',
            'retard' => self::lateCategory($minutes),
            default => null,
        };
    }

    private static function lateCategory(int|string|null $minutes): string
    {
        $m = ($minutes === null || $minutes === '') ? self::LATE_TOLERATED : (int) $minutes;

        return match (true) {
            $m >= self::LATE_AS_ABSENCE => 'absence',
            $m >= self::LATE_TOLERATED => 'retard',
            default => 'present',
        };
    }

    /** Minutes de retard de l'enseignant, ou null si une des deux heures manque. */
    public static function teacherLateMinutes(?string $plannedTime, ?string $arrivalTime): ?int
    {
        $planned = self::seconds($plannedTime);
        $arrival = self::seconds($arrivalTime);

        return $planned === null || $arrival === null ? null : max(0, intdiv($arrival - $planned, 60));
    }

    /** §474 : plus de 10 minutes de retard de l'enseignant, sans message aux étudiants. */
    public static function teacherLateWithoutPenalty(?string $plannedTime, ?string $arrivalTime, mixed $warned): bool
    {
        $late = self::teacherLateMinutes($plannedTime, $arrivalTime);

        return $late !== null && $late > self::TEACHER_WAIT && empty($warned);
    }

    /** « 08:30 » ou « 08:30:15 » en secondes depuis minuit ; null si absent ou illisible. */
    private static function seconds(?string $time): ?int
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($time), $m)) {
            return null;
        }

        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) ($m[3] ?? 0);
    }

    /**
     * Absences permises : 15 % des séances prévues, arrondi vers le bas.
     * 30 séances : 4 permises, la 5e dépasse (16,7 %) ; 20 séances : 3 permises (15 % pile), la 4e dépasse.
     */
    public static function allowedAbsences(int|string|null $planned): ?int
    {
        $n = (int) $planned;

        return $n <= 0 ? null : (int) floor($n * self::MAX_RATE / 100 + 1e-9);
    }

    /**
     * Bilan d'un étudiant dans un cours.
     *
     * @param  list<array{status: ?string, minutes_late: int|string|null, no_penalty: bool}>  $records
     * @return array{recorded:int, present:int, late:int, absent:int, no_penalty:int, converted_lates:int, counted:int,
     *               late_pending:bool, planned:?int, allowed:?int, rate:?float, state:string, gauge:int}
     *   state : ok | limit (une absence de plus et il dépasse) | exceeded | unknown (séances prévues non indiquées)
     */
    public static function summary(array $records, int|string|null $planned): array
    {
        $b = ['recorded' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'no_penalty' => 0];

        foreach ($records as $r) {
            $category = self::category($r['status'] ?? null, $r['minutes_late'] ?? null);
            if ($category === null) {
                continue;
            }
            $b['recorded']++;
            if ($category !== 'present' && ! empty($r['no_penalty'])) {
                $b['no_penalty']++; // §474 : l'enseignant n'est pas venu à l'heure
                continue;
            }
            match ($category) {
                'present' => $b['present']++,
                'retard' => $b['late']++,
                default => $b['absent']++,
            };
        }

        $b['converted_lates'] = intdiv($b['late'], 2);
        $b['counted'] = $b['absent'] + $b['converted_lates'];
        $b['late_pending'] = $b['late'] % 2 === 1; // le prochain retard fera une absence
        $b['planned'] = (int) $planned > 0 ? (int) $planned : null;
        $b['allowed'] = self::allowedAbsences($planned);
        $b['rate'] = $b['planned'] ? round($b['counted'] * 100 / $b['planned'], 1) : null;

        $b['state'] = match (true) {
            $b['allowed'] === null => 'unknown',
            $b['counted'] > $b['allowed'] => 'exceeded',
            $b['allowed'] > 0 && $b['counted'] === $b['allowed'] => 'limit',
            default => 'ok',
        };

        // Jauge : la barre est pleine au moment où l'on dépasse (comme l'ancienne page).
        $b['gauge'] = $b['allowed'] === null ? 0
            : ($b['state'] === 'exceeded' ? 100 : (int) min(100, round($b['counted'] * 100 / max(1, $b['allowed'] + 1))));

        return $b;
    }

    /** Libellé court d'un pointage (« Retard 7 min »). */
    public static function recordLabel(?string $status, int|string|null $minutes = null): string
    {
        $category = self::category($status, $minutes);
        if ($category === null) {
            return 'Non pointé';
        }
        if ($status === 'retard') {
            $m = ($minutes === null || $minutes === '') ? self::LATE_TOLERATED : (int) $minutes;
            $suffix = $category === 'absence' ? ' (compte absence)' : ($category === 'present' ? ' (toléré)' : '');

            return 'Retard '.$m.' min'.$suffix;
        }

        return $category === 'present' ? 'Présent' : 'Absent';
    }

    /** « Semestre 1 », « Semestre d'été » (3), « Semestre d'hiver » (4), « Tous semestres » (aucun). */
    public static function semesterLabel(int|string|null $semester): string
    {
        $s = (string) $semester;

        return match (true) {
            $s === '3' => "Semestre d'été",
            $s === '4' => "Semestre d'hiver",
            $s === '' => 'Tous semestres',
            default => 'Semestre '.$s,
        };
    }
}
