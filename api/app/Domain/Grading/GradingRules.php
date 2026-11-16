<?php

namespace App\Domain\Grading;

/**
 * Règles de la remise de notes : port de `data/remise.notes.php` (remiseCalculerNote, remiseNoteVersGrade, remiseControler…), étendu aux
 * colonnes LIBRES : l'enseignant choisit le nombre de colonnes, leur titre et leur pondération ; seul le total (100 %) est imposé.
 *
 * Une remise est la feuille de notes d'un cours pour une année et un semestre. L'enseignant la remplit puis la transmet ; le registrariat la
 * contrôle et la valide. Cycle : brouillon → soumise → validée, ou soumise → rejetée → (l'enseignant reprend).
 *
 * La note sur 20 ne se SAISIT jamais : elle est toujours calculée depuis les composantes et leur pondération. Une composante pondérée mais NON
 * saisie rend la ligne « incomplète » (note nulle) : on ne calcule JAMAIS au prorata des seules composantes remplies, ce qui donnerait une note
 * d'apparence normale à une évaluation à moitié faite.
 *
 * Une COMPOSANTE est `['slot' => int, 'label' => string, 'weight' => float]` (slot = identité stable). Les valeurs d'une ligne sont `slot => ?float`.
 * Les méthodes sans suffixe `For` (computeNote, check, gradeFor) gardent la forme de l'ancienne feuille (cinq colonnes fixes) : elles servent
 * de pont avec l'ancien code, contre lequel tout est confronté.
 *
 * Fonctions pures : aucune base, aucun réseau.
 */
final class GradingRules
{
    /** Les cinq colonnes de l'ancienne feuille, dans l'ordre : champ de la ligne => [libellé, colonne du poids]. Elles deviennent les slots 1 à 5. */
    public const COMPONENTS = [
        'quiz' => ['label' => 'Quiz', 'short' => 'Quiz', 'weight' => 'poids_quiz'],
        'recherche' => ['label' => 'Recherche', 'short' => 'Rech.', 'weight' => 'poids_recherche'],
        'autres' => ['label' => 'Autres', 'short' => 'Autres', 'weight' => 'poids_autres'],
        'mi_semestre' => ['label' => 'Examen mi-sem.', 'short' => 'Mi-sem', 'weight' => 'poids_mi_semestre'],
        'examen_final' => ['label' => 'Examen final', 'short' => 'Final', 'weight' => 'poids_final'],
    ];

    /** Pondération proposée à l'ouverture d'une remise : celle des valeurs par défaut des colonnes de `t_remise_notes`. */
    public const DEFAULT_WEIGHTS = ['poids_quiz' => 10.0, 'poids_recherche' => 20.0, 'poids_autres' => 10.0, 'poids_mi_semestre' => 25.0, 'poids_final' => 35.0];

    public const SPECIAL_CODES = ['V', 'E', 'NN'];

    public const MAX_COMPONENTS = 12;

    public const MAX_LABEL = 40;

    // ----------------------------------------------------- pont avec l'ancienne feuille

    /**
     * La structure de l'ancienne feuille (cinq colonnes) sous forme de composantes : slots 1 à 5.
     *
     * @param  array<string, mixed>  $weights  poids_quiz…
     * @return list<array{slot: int, label: string, weight: float}>
     */
    public static function legacyComponents(array $weights): array
    {
        $out = [];
        $slot = 0;
        foreach (self::COMPONENTS as $meta) {
            $out[] = ['slot' => ++$slot, 'label' => $meta['label'], 'weight' => (float) ($weights[$meta['weight']] ?? 0)];
        }

        return $out;
    }

    /**
     * Les notes d'une ligne de l'ancienne feuille, par slot.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, mixed>
     */
    public static function legacyValues(array $line): array
    {
        $out = [];
        $slot = 0;
        foreach (array_keys(self::COMPONENTS) as $field) {
            $out[++$slot] = $line[$field] ?? null;
        }

        return $out;
    }

    /** @param array<string, mixed> $weights */
    public static function totalWeight(array $weights): float
    {
        return self::totalWeightOf(self::legacyComponents($weights));
    }

    /** @param list<array{weight: float|int|string}> $components */
    public static function totalWeightOf(array $components): float
    {
        $total = 0.0;
        foreach ($components as $c) {
            $total += (float) $c['weight'];
        }

        return round($total, 2);
    }

    /** Forme de l'ancienne feuille. @param array<string, mixed> $line @param array<string, mixed> $weights */
    public static function computeNote(array $line, array $weights): array
    {
        return self::computeNoteFor(self::legacyValues($line), $line['statut_special'] ?? null, self::legacyComponents($weights));
    }

    // ------------------------------------------------------------------- calcul

    /**
     * Note finale d'une ligne, telle qu'elle sera proposée à la validation.
     *
     * @param  array<int, mixed>  $values  slot => note (ou null)
     * @param  list<array{slot: int, label: string, weight: float|int|string}>  $components
     * @return array{value: ?float, state: 'calculated'|'special'|'incomplete'|'empty', detail: string}
     */
    public static function computeNoteFor(array $values, mixed $special, array $components): array
    {
        $special = trim((string) ($special ?? ''));
        if ($special !== '') {
            return ['value' => null, 'state' => 'special', 'detail' => strtoupper($special)];
        }

        $sum = 0.0;
        $usedWeight = 0.0;
        $missing = [];
        $entered = 0;

        foreach ($components as $c) {
            $weight = (float) $c['weight'];
            if ($weight <= 0) {
                continue; // composante non utilisée dans ce cours
            }
            $usedWeight += $weight;

            $value = $values[$c['slot']] ?? null;
            if ($value === null || $value === '') {
                $missing[] = $c['label'];

                continue;
            }
            $entered++;
            $sum += ((float) $value) * $weight;
        }

        if ($usedWeight <= 0) {
            return ['value' => null, 'state' => 'empty', 'detail' => 'Aucune pondération définie'];
        }
        if ($entered === 0) {
            return ['value' => null, 'state' => 'empty', 'detail' => 'Aucune note saisie'];
        }
        if ($missing !== []) {
            return ['value' => null, 'state' => 'incomplete', 'detail' => 'Manque : '.implode(', ', $missing)];
        }

        return ['value' => round($sum / $usedWeight, 2), 'state' => 'calculated', 'detail' => 'Moyenne pondérée des composantes'];
    }

    /**
     * La valeur qui sera stockée dans `t_2023_notes.grade` (VARCHAR mêlant notes et codes : -2 validé, -3 échec, -4 pas encore noté),
     * ou null si la ligne n'est pas transférable en l'état. Sert à la VALIDATION (registrariat) et au décompte des « notes prêtes ».
     *
     * @param  array{value: ?float, state: string, detail: string}  $calc  résultat de computeNoteFor
     */
    public static function gradeFromNote(array $calc, bool $validationCourse = false): ?string
    {
        if ($calc['state'] === 'special') {
            return match ($calc['detail']) {
                'V' => '-2',
                'E' => '-3',
                'NN' => '-4',
                default => null,
            };
        }
        if ($calc['value'] === null) {
            return null;
        }
        // Un cours de validation ne s'exprime jamais en chiffres : la note sert seulement à décider entre validé et échec.
        if ($validationCourse) {
            return $calc['value'] >= 10 ? '-2' : '-3';
        }

        // 12.5 → « 12.5 », 14.0 → « 14 » (comme la conversion en chaîne de PHP).
        return (string) $calc['value'];
    }

    /** Forme de l'ancienne feuille. @param array<string, mixed> $line @param array<string, mixed> $weights */
    public static function gradeFor(array $line, array $weights, bool $validationCourse = false): ?string
    {
        return self::gradeFromNote(self::computeNote($line, $weights), $validationCourse);
    }

    // ------------------------------------------------------------------ contrôle

    /**
     * Ce que l'enseignant doit lever avant de transmettre, et ce que le registraire regardera.
     *
     * @param  list<array<string, mixed>>  $lines  chaque ligne : `values` (slot => note), `statut_special`, `student_id`, `last_name`, `first_name`
     * @param  list<array{slot: int, label: string, weight: float|int|string}>  $components
     * @return array{blockers: list<string>, warnings: list<string>, ready: int, total: int}
     */
    public static function checkFor(array $lines, array $components): array
    {
        $blockers = [];
        $warnings = [];
        $ready = 0;
        $incomplete = [];
        $outOfScale = [];
        $notGraded = [];

        $total = self::totalWeightOf($components);
        if (abs($total - 100.0) > 0.01) {
            $blockers[] = 'La pondération totalise '.rtrim(rtrim(number_format($total, 2, ',', ' '), '0'), ',').' % au lieu de 100 %.';
        }
        if ($lines === []) {
            $blockers[] = 'Aucun étudiant inscrit à ce cours pour cette année et ce semestre.';
        }

        foreach ($lines as $line) {
            $calc = self::computeNoteFor($line['values'] ?? [], $line['statut_special'] ?? null, $components);
            $name = trim(($line['last_name'] ?? '').' '.($line['first_name'] ?? '')) ?: (string) $line['student_id'];

            if ($calc['state'] === 'incomplete') {
                $incomplete[] = $name;

                continue;
            }
            if ($calc['state'] === 'empty') {
                $notGraded[] = $name;

                continue;
            }
            if ($calc['value'] !== null && ($calc['value'] < 0 || $calc['value'] > 20)) {
                $outOfScale[] = $name.' ('.$calc['value'].')';

                continue;
            }
            if (self::gradeFromNote($calc) !== null) {
                $ready++;
            }
        }

        if ($outOfScale !== []) {
            $blockers[] = 'Note hors barème 0-20 : '.self::shortList($outOfScale).'.';
        }
        if ($incomplete !== []) {
            $blockers[] = count($incomplete).' étudiant(s) avec une composante pondérée non saisie : '.self::shortList($incomplete).'.';
        }
        if ($notGraded !== []) {
            $warnings[] = count($notGraded).' étudiant(s) sans aucune note : '.self::shortList($notGraded).'. Ils seront enregistrés « pas encore noté » (NN).';
        }

        return ['blockers' => $blockers, 'warnings' => $warnings, 'ready' => $ready, 'total' => count($lines)];
    }

    /** Forme de l'ancienne feuille (lignes à plat : quiz, recherche…). @param list<array<string, mixed>> $lines @param array<string, mixed> $weights */
    public static function check(array $lines, array $weights): array
    {
        $lines = array_map(fn ($l) => $l + ['values' => self::legacyValues($l)], $lines);

        return self::checkFor($lines, self::legacyComponents($weights));
    }

    /** Tronque une énumération pour qu'un message reste lisible. */
    public static function shortList(array $names, int $max = 5): string
    {
        if (count($names) <= $max) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, $max)).' et '.(count($names) - $max).' autre(s)';
    }

    // ----------------------------------------------------------- lecture des saisies

    /** « 12,5 » ou « 12.5 » → 12.5 ; vide → null ; hors 0 à 20 ou non numérique → false. Arrondi à 2 décimales. */
    public static function parseComponent(string $raw): float|false|null
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $normalized = str_replace(',', '.', $raw);
        if (! is_numeric($normalized)) {
            return false;
        }
        $value = round((float) $normalized, 2);

        return $value < 0 || $value > 20 ? false : $value;
    }

    /** Un poids saisi : vide ou non numérique = 0 ; borné à 0–100, arrondi à 2 décimales. */
    public static function parseWeight(string $raw): float
    {
        $value = str_replace(',', '.', trim($raw));
        if ($value === '' || ! is_numeric($value)) {
            return 0.0;
        }

        return round(max(0.0, min(100.0, (float) $value)), 2);
    }

    /** Un titre de colonne : espaces réduits et retirés en bordure ; vide ou trop long = refusé (null). */
    public static function cleanLabel(string $raw): ?string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $raw));

        return $label === '' || mb_strlen($label) > self::MAX_LABEL ? null : $label;
    }
}
