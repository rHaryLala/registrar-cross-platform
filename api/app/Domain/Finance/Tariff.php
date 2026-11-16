<?php

namespace App\Domain\Finance;

use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Barème financier par session (port de `src/gestion_finance.php`, `src/finance/bareme.grille.php`, `data/finance.bareme.php` et `app/finance-bareme.save.php`).
 *
 * LE MODÈLE. `t_2024_finance_detail_licence` a deux étages : `session_id = 0` est le BARÈME DE BASE (repli de toute session), une autre valeur est la SURCHARGE
 * d'une session, qui n'existe que si le registrariat l'a ajustée. Une lecture cherche d'abord la session, puis retombe sur la base : aucune session ne peut se
 * retrouver sans barème. Une ligne est identifiée par (session, mention, niveau, statut, semestre) ; la catégorie (L / M) se déduit du niveau.
 *
 * Accès : niveaux 1 à 3 (superadmin, administration, registrariat), comme l'ancienne page : modifier le barème change ce que paient tous les étudiants. Un seul
 * réglage : `allows()`.
 *
 * Ce qui ne change pas : seuls les montants TRANSMIS sont écrits (jamais d'écrasement par zéro), une surcharge naît complète en partant de la base, la recopie
 * n'écrase pas une ligne déjà ajustée et se limite au semestre de la session cible, le retour à la base supprime la surcharge, le barème de base lui-même ne
 * se supprime ni ne se remplace, plusieurs filières reçoivent les mêmes montants en une transaction.
 * Ce qui change : un montant négatif ou énorme est refusé (l'ancien code les écrivait), chaque ligne écrite est journalisée (avant/après).
 */
final class Tariff
{
    public const BASE = 0;

    /** Plus grand montant accepté (la colonne est un flottant : au-delà, la valeur serait déformée). */
    public const MAX_AMOUNT = 999999999999.0;

    /** @var array<string, string> colonne => libellé, dans l'ordre d'affichage */
    public const FIELDS = [
        'frais_generaux' => 'Frais généraux / semestre',
        'laboratory_info' => 'Labo INFO / semestre',
        'laboratory_lang' => 'Labo LANGUE / semestre',
        'dortoir' => 'Dortoir / jour',
        'cafeteria' => 'Cantine / jour',
        'fond_depot' => 'Fond de dépôt',
        'nb_jours_semestre' => 'Nombre de jours (internes)',
        'ecolage' => 'Écolage (multiplié par le nombre de crédits)',
        'frais_graduation' => 'Frais de graduation',
        'frais_costume' => 'Frais de costume',
        'frais_voyage' => 'Voyage d’étude',
    ];

    public function __construct(private readonly AuditTrail $audit, private readonly ExtraFees $extraFees) {}

    public static function allows(User $user): bool
    {
        return $user->isActive() && (int) $user->level >= 1 && (int) $user->level <= 3;
    }

    public function require(User $user): void
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.tariff', 'Accès refusé', 'Le barème financier est réservé au superadmin, à l’administration et au registrariat.');
        }
    }

    // ------------------------------------------------------------------ référentiels

    /** @return list<array{level: int, label: string, category: string}> */
    public static function levels(): array
    {
        return [
            ['level' => 0, 'label' => 'Remise à niveau', 'category' => 'L'], ['level' => 1, 'label' => 'Licence 1', 'category' => 'L'], ['level' => 2, 'label' => 'Licence 2', 'category' => 'L'],
            ['level' => 3, 'label' => 'Licence 3', 'category' => 'L'], ['level' => 4, 'label' => 'Master 1', 'category' => 'M'], ['level' => 5, 'label' => 'Master 2', 'category' => 'M'],
        ];
    }

    public static function category(int $level): string
    {
        return $level < 4 ? 'L' : 'M';
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return ['Interne', 'Externe'];
    }

    /**
     * Le champ a-t-il un sens pour cette mention et ce semestre ? Un champ non pertinent n'est pas rendu (graduation : semestre 2 ; costume : Théologie au semestre 1).
     */
    public static function relevant(string $column, string $mention, int $semester): bool
    {
        return match ($column) {
            'frais_graduation' => $semester === 2,
            'frais_costume' => $mention === 'THEO' && $semester === 1,
            default => true,
        };
    }

    /** L'année scolaire en cours : de juillet à juin (« 2026 - 2027 » à partir de juillet 2026). */
    public static function currentYear(?int $month = null, ?int $year = null): string
    {
        $month ??= (int) date('m');
        $year ??= (int) date('Y');

        return $month >= 7 ? $year.' - '.($year + 1) : ($year - 1).' - '.$year;
    }

    /** @return list<string> les années du catalogue, la plus récente d'abord */
    public function years(): array
    {
        return array_map(fn ($r) => (string) $r->session_year, DB::select('SELECT DISTINCT session_year FROM t_2023_session WHERE session_year <> "" ORDER BY session_year DESC'));
    }

    /** @param list<string> $years */
    public function chooseYear(array $years, ?string $requested): string
    {
        if ($years === []) {
            return self::currentYear();
        }
        $requested = $requested !== null ? trim($requested) : '';
        if ($requested !== '' && in_array($requested, $years, true)) {
            return $requested;
        }
        $current = self::currentYear();

        return in_array($current, $years, true) ? $current : $years[0];
    }

    /** @return array<int, array{id: int, name: string, year: string, semester: int}> sessions de l'année, par numéro de semestre */
    public function sessionsOf(string $year): array
    {
        $bySemester = [];
        foreach (DB::select('SELECT session_id, session_name, session_year, session_semester FROM t_2023_session WHERE session_year = ? ORDER BY session_semester', [$year]) as $s) {
            $bySemester[(int) $s->session_semester] = ['id' => (int) $s->session_id, 'name' => (string) $s->session_name, 'year' => (string) $s->session_year, 'semester' => (int) $s->session_semester];
        }

        return $bySemester;
    }

    /** @return list<array{sigle: string, label: string}> les mentions OUVERTES (ou toutes si le réglage d'ouverture n'existe pas) */
    public function openMentions(): array
    {
        $where = Schema::hasColumn('filiere', 'ouverte') ? 'ouverte = 1' : '1';

        return array_map(fn ($m) => ['sigle' => (string) $m->filiere_sigle, 'label' => (string) $m->filiere_description], DB::select("SELECT filiere_sigle, filiere_description FROM filiere WHERE $where ORDER BY filiere_sigle"));
    }

    /** @return list<string> les mentions qui ont un barème propre dans cette session */
    public function adjustedMentions(int $sessionId): array
    {
        return array_map(fn ($r) => (string) $r->std_mention, DB::select('SELECT DISTINCT std_mention FROM t_2024_finance_detail_licence WHERE session_id = ? ORDER BY std_mention', [$sessionId]));
    }

    // ------------------------------------------------------------------ lecture

    /**
     * La grille d'une mention et d'un semestre : une case par niveau et statut, avec le repli sur la base et sa provenance (`source` : session, base, none).
     *
     * @return array<string, array{source: string, values: array<string, float>|null}> clé « niveau|statut »
     */
    public function grid(int $sessionId, string $mention, int $semester): array
    {
        $read = function (int $sid) use ($mention, $semester): array {
            $byKey = [];
            foreach (DB::select('SELECT * FROM t_2024_finance_detail_licence WHERE session_id = ? AND std_mention = ? AND semester = ?', [$sid, $mention, $semester]) as $l) {
                $byKey[$l->level.'|'.$l->std_status] = (array) $l;   // doublon : la dernière ligne lue l'emporte, comme l'ancienne grille
            }

            return $byKey;
        };
        $base = $read(self::BASE);
        $over = $sessionId !== self::BASE ? $read($sessionId) : [];

        $grid = [];
        foreach (self::levels() as $n) {
            foreach (self::statuses() as $status) {
                $key = $n['level'].'|'.$status;
                $row = $over[$key] ?? null;
                $source = $row !== null ? 'session' : 'base';
                $row ??= $base[$key] ?? null;
                $values = null;
                if ($row !== null) {
                    $values = [];
                    foreach (array_keys(self::FIELDS) as $c) {
                        $values[$c] = round((float) $row[$c], 2);
                    }
                }
                $grid[$key] = ['source' => $row === null ? 'none' : $source, 'values' => $values];
            }
        }

        return $grid;
    }

    /**
     * Ce que l'écran affiche : l'année, les semestres et leurs sessions, la cible (une session ou le barème de base), les filières avec leur grille et les frais supplémentaires.
     *
     * @param  array<string, mixed>  $query  annee, semestre, base (« 1 » : le barème de base)
     * @return array<string, mixed>
     */
    public function show(User $user, array $query): array
    {
        $this->require($user);
        $years = $this->years();
        $year = $this->chooseYear($years, is_scalar($query['annee'] ?? null) ? (string) $query['annee'] : null);
        $sessions = $this->sessionsOf($year);

        $semesters = array_keys($sessions);
        $semester = is_scalar($query['semestre'] ?? null) ? (int) $query['semestre'] : 0;
        if (! in_array($semester, $semesters, true)) {
            $semester = $semesters[0] ?? 1;
        }
        $session = $sessions[$semester] ?? null;
        $editBase = ($query['base'] ?? '') === '1';
        $target = $editBase ? self::BASE : ($session['id'] ?? self::BASE);

        $mentions = $this->openMentions();
        $adjusted = $target === self::BASE ? [] : $this->adjustedMentions($target);
        $fees = $this->extraFees->installed() ? $this->extraFees->definitions(false) : [];
        $activeFees = array_values(array_filter($fees, fn ($f) => $f['active'] && ExtraFees::appliesToSemester($f['semesters'], $semester)));

        $cards = [];
        if ($session !== null || $editBase) {
            foreach ($mentions as $m) {
                $grid = $this->grid($target, $m['sigle'], $semester);
                $fields = [];
                foreach (self::FIELDS as $col => $label) {
                    if (self::relevant($col, $m['sigle'], $semester)) {
                        $fields[] = ['column' => $col, 'label' => $col === 'frais_voyage' && $m['sigle'] === 'THEO' ? 'Colloque' : $label];
                    }
                }
                $cards[] = [
                    'sigle' => $m['sigle'], 'label' => $m['label'], 'adjusted' => in_array($m['sigle'], $adjusted, true),
                    'no_tariff' => count(array_filter($grid, fn ($c) => $c['values'] !== null)) === 0,
                    'fields' => $fields, 'grid' => $grid,
                    'extra' => $activeFees === [] ? (object) [] : $this->extraFees->grid($target, $m['sigle'], $semester, $activeFees),
                ];
            }
        }

        return [
            'years' => $years, 'year' => $year,
            'sessions' => array_values($sessions), 'semester' => $semester, 'session' => $session, 'editing_base' => $editBase, 'target' => $target,
            'levels' => self::levels(), 'statuses' => self::statuses(),
            'mentions' => $cards, 'adjusted_count' => count(array_intersect($adjusted, array_column($mentions, 'sigle'))), 'mention_count' => count($mentions),
            'extra_fees' => $fees, 'extra_fee_conditions' => ExtraFees::CONDITIONS, 'extra_fees_installed' => $this->extraFees->installed(),
        ];
    }

    // ------------------------------------------------------------------ écriture

    /** Les cibles de l'action : la session (0 = barème de base) et les mentions, toutes vérifiées contre le catalogue. @return array{0: int, 1: array<string, mixed>} */
    private function target(mixed $sessionId): array
    {
        if (! is_numeric($sessionId) || (int) $sessionId < 0) {
            throw new ApiProblem(422, 'validation.session', 'Session manquante', 'Choisissez une session ou le barème de base.');
        }
        $id = (int) $sessionId;
        if ($id === self::BASE) {
            return [$id, ['name' => 'Barème de base', 'year' => '', 'semester' => null]];
        }
        $s = DB::selectOne('SELECT session_name, session_year, session_semester FROM t_2023_session WHERE session_id = ? LIMIT 1', [$id]);
        if (! $s) {
            throw new ApiProblem(422, 'validation.session', 'Session inconnue', 'Cette session n’existe pas : rechargez la page.');
        }

        return [$id, ['name' => (string) $s->session_name, 'year' => (string) $s->session_year, 'semester' => (int) $s->session_semester]];
    }

    private function mention(mixed $sigle): string
    {
        $sigle = trim((string) $sigle);
        if ($sigle !== '' && ! DB::table('filiere')->where('filiere_sigle', $sigle)->exists()) {
            throw new ApiProblem(422, 'validation.mention', 'Mention inconnue', 'Mention inconnue : '.$sigle.'.');
        }

        return $sigle;
    }

    /** Un montant du barème : un nombre fini, positif ou nul, dans la plage de la colonne ; null sinon. */
    public static function amount(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric(trim($value)))) {
            return null;
        }
        $n = (float) (is_string($value) ? trim($value) : $value);

        return is_finite($n) && $n >= 0 && $n <= self::MAX_AMOUNT ? $n : null;
    }

    /**
     * Enregistre les montants d'une mention, d'un semestre et d'une session ; les mêmes montants peuvent aller à d'autres mentions, en une transaction.
     *
     * @param  array<string, mixed>  $body  session_id, mention, mentions[] (supplémentaires), semester, amounts{"niveau|statut":{colonne: montant}}, extra{frais_id:{"niveau|statut": montant|""}}
     * @return array<string, mixed>
     */
    public function save(User $user, array $body): array
    {
        $this->require($user);
        [$sessionId, $session] = $this->target($body['session_id'] ?? null);
        $mention = $this->mention($body['mention'] ?? '');
        if ($mention === '') {
            throw new ApiProblem(422, 'validation.mention', 'Mention manquante', 'Choisissez la filière à enregistrer.');
        }
        $targets = [$mention];
        foreach (is_array($body['mentions'] ?? null) ? $body['mentions'] : [] as $other) {
            $other = $this->mention($other);
            if ($other !== '' && ! in_array($other, $targets, true)) {
                $targets[] = $other;
            }
        }
        $semester = is_numeric($body['semester'] ?? null) ? (int) $body['semester'] : 0;
        if ($semester < 1 || $semester > 4) {
            throw new ApiProblem(422, 'validation.semester', 'Semestre invalide', 'Le semestre doit être compris entre 1 et 4.');
        }
        if ($sessionId !== self::BASE && $session['semester'] !== $semester) {
            throw new ApiProblem(422, 'validation.semester', 'Semestre incohérent', 'La session « '.$session['name'].' » ne correspond pas au semestre '.$semester.'.');
        }

        $amounts = is_array($body['amounts'] ?? null) ? $body['amounts'] : [];
        $extra = is_array($body['extra'] ?? null) ? $body['extra'] : [];
        if ($amounts === [] && $extra === []) {
            throw new ApiProblem(422, 'validation.empty', 'Aucun montant', 'Aucun montant transmis.');
        }
        $this->checkAmounts($amounts, $extra);

        $result = DB::transaction(function () use ($sessionId, $targets, $semester, $amounts, $extra) {
            $perMention = [];
            $written = $created = $extraWritten = $extraCleared = 0;
            foreach ($targets as $m) {
                $r = $amounts === [] ? ['written' => 0, 'created' => 0] : $this->saveAmounts($sessionId, $m, $semester, $amounts);
                if ($extra !== []) {
                    $x = $this->extraFees->saveAmounts($sessionId, $m, $semester, $extra);
                    $r['extra_written'] = $x['written'];
                    $r['extra_cleared'] = $x['cleared'];
                    $extraWritten += $x['written'];
                    $extraCleared += $x['cleared'];
                }
                $perMention[$m] = $r;
                $written += $r['written'];
                $created += $r['created'];
            }
            if ($written === 0 && $extraWritten === 0 && $extraCleared === 0) {
                throw new ApiProblem(422, 'validation.empty', 'Aucune valeur exploitable', 'Aucune valeur exploitable dans les montants transmis.');
            }

            return compact('perMention', 'written', 'created', 'extraWritten', 'extraCleared');
        });

        $period = $sessionId === self::BASE ? 'Barème de base' : 'Barème de '.$session['name'].' '.$session['year'];
        $n = count($targets);
        $tail = $result['created'] > 0 ? ', dont '.$result['created'].' nouvelle(s)' : '';
        $message = $n === 1 ? $period.' enregistré ('.$result['written'].' ligne(s)'.$tail.').' : $period.' appliqué à '.$n.' filières ('.$result['written'].' ligne(s)'.$tail.').';
        $bits = [];
        if ($result['extraWritten'] > 0) {
            $bits[] = $result['extraWritten'].' montant(s) de frais supplémentaires';
        }
        if ($result['extraCleared'] > 0) {
            $bits[] = $result['extraCleared'].' revenu(s) au barème de base';
        }
        if ($bits !== []) {
            $message .= ' '.implode(', ', $bits).'.';
        }

        return [
            'session_id' => $sessionId, 'mentions' => $targets, 'semester' => $semester, 'written' => $result['written'], 'created' => $result['created'],
            'extra_written' => $result['extraWritten'], 'extra_cleared' => $result['extraCleared'], 'by_mention' => $result['perMention'], 'override' => $sessionId !== self::BASE, 'message' => $message,
        ];
    }

    /** Refuse tout le message si un montant est illisible, négatif ou énorme (aucune écriture partielle). */
    private function checkAmounts(array $amounts, array $extra): void
    {
        foreach ($amounts as $cell => $values) {
            if (! is_array($values)) {
                throw new ApiProblem(422, 'validation.amount', 'Montant invalide', 'Les montants de « '.$cell.' » sont illisibles.');
            }
            foreach ($values as $column => $value) {
                if (isset(self::FIELDS[$column]) && self::amount($value) === null) {
                    throw new ApiProblem(422, 'validation.amount', 'Montant invalide', 'Montant invalide pour « '.self::FIELDS[$column].' » ('.$cell.') : un nombre positif ou nul est attendu.', ['field' => "amounts.$cell.$column"]);
                }
            }
        }
        foreach ($extra as $feeId => $cells) {
            foreach (is_array($cells) ? $cells : [] as $cell => $value) {
                // une case vide (texte vide, devenu null en JSON) efface le montant : ce n'est pas une erreur
                if ($value !== null && trim((string) (is_scalar($value) ? $value : 'x')) !== '' && self::amount($value) === null) {
                    throw new ApiProblem(422, 'validation.amount', 'Montant invalide', 'Montant invalide pour un frais supplémentaire ('.$cell.') : un nombre positif ou nul est attendu.', ['field' => "extra.$feeId.$cell"]);
                }
            }
        }
    }

    /** @return array{written: int, created: int} port de `baremeEnregistrer` */
    private function saveAmounts(int $sessionId, string $mention, int $semester, array $values): array
    {
        $columns = array_keys(self::FIELDS);
        $written = 0;
        $created = 0;
        foreach (self::levels() as $n) {
            foreach (self::statuses() as $status) {
                $cell = $values[$n['level'].'|'.$status] ?? null;
                if (! is_array($cell) || $cell === []) {
                    continue;   // rien de transmis pour cette case : on n'y touche pas
                }
                $amounts = [];
                foreach ($cell as $column => $value) {
                    if (in_array($column, $columns, true) && ($a = self::amount($value)) !== null) {
                        $amounts[$column] = $a;
                    }
                }
                if ($amounts === []) {
                    continue;
                }
                $row = DB::selectOne('SELECT * FROM t_2024_finance_detail_licence WHERE session_id = ? AND std_mention = ? AND level = ? AND std_status = ? AND semester = ? LIMIT 1', [$sessionId, $mention, $n['level'], $status, $semester]);
                if (! $row) {
                    // Création de la surcharge : on part du barème de base, pour que les montants non transmis soient hérités, pas mis à zéro.
                    $base = DB::selectOne('SELECT * FROM t_2024_finance_detail_licence WHERE session_id = ? AND std_mention = ? AND level = ? AND std_status = ? AND semester = ? LIMIT 1', [self::BASE, $mention, $n['level'], $status, $semester]);
                    $start = [];
                    foreach ($columns as $c) {
                        $start[$c] = $base !== null && isset($base->$c) ? (float) $base->$c : 0.0;
                    }
                    $new = array_merge($start, $amounts) + ['session_id' => $sessionId, 'categorie' => self::category($n['level']), 'std_status' => $status, 'std_mention' => $mention, 'level' => $n['level'], 'semester' => $semester];
                    $id = DB::table('t_2024_finance_detail_licence')->insertGetId($new);
                    $this->audit->insert('t_2024_finance_detail_licence', 'id', ['id' => $id] + $new);
                    $created++;
                    $written++;

                    continue;
                }
                $before = (array) $row;
                $update = $amounts + ['categorie' => self::category($n['level'])];
                DB::table('t_2024_finance_detail_licence')->where('id', $row->id)->update($update);
                $this->audit->update('t_2024_finance_detail_licence', 'id', $before, array_merge($before, $update));
                $written++;
            }
        }

        return ['written' => $written, 'created' => $created];
    }

    /**
     * « Reprendre la base » : recopie le barème d'une session vers une autre, pour ajustement. Les lignes déjà présentes dans la cible ne sont PAS écrasées, et seule
     * la tranche du semestre de la session cible est copiée (`baremeCopier`).
     *
     * @param  array<string, mixed>  $body  session_id (cible), source_session_id (défaut : la base), mention (facultative)
     * @return array{copied: int, ignored: int, message: string}
     */
    public function copy(User $user, array $body): array
    {
        $this->require($user);
        [$target] = $this->target($body['session_id'] ?? null);
        if ($target === self::BASE) {
            throw new ApiProblem(422, 'validation.base', 'Recopie impossible', 'Le barème de base ne peut pas être remplacé par une recopie.');
        }
        $source = (int) ($body['source_session_id'] ?? self::BASE);
        if ($source < 0 || ($source !== self::BASE && ! DB::table('t_2023_session')->where('session_id', $source)->exists())) {
            throw new ApiProblem(422, 'validation.session', 'Session inconnue', 'La session à reprendre n’existe pas.');
        }
        $mention = $this->mention($body['mention'] ?? '');

        $result = ['copied' => 0, 'ignored' => 0];
        if ($source !== $target) {
            $result = DB::transaction(function () use ($source, $target, $mention) {
                $semester = DB::table('t_2023_session')->where('session_id', $target)->value('session_semester');
                $query = DB::table('t_2024_finance_detail_licence')->where('session_id', $source);
                if ($mention !== '') {
                    $query->where('std_mention', $mention);
                }
                if ($semester !== null) {
                    $query->where('semester', (int) $semester);
                }
                $copied = 0;
                $ignored = 0;
                $inserted = [];
                foreach ($query->get() as $src) {
                    $exists = DB::table('t_2024_finance_detail_licence')->where(['session_id' => $target, 'std_mention' => $src->std_mention, 'level' => $src->level, 'std_status' => $src->std_status, 'semester' => $src->semester])->exists();
                    if ($exists) {
                        $ignored++;

                        continue;
                    }
                    $new = ['session_id' => $target, 'categorie' => self::category((int) $src->level), 'std_status' => $src->std_status, 'std_mention' => $src->std_mention, 'level' => $src->level, 'semester' => $src->semester];
                    foreach (array_keys(self::FIELDS) as $c) {
                        $new[$c] = (float) ($src->$c ?? 0);
                    }
                    $inserted[] = ['id' => DB::table('t_2024_finance_detail_licence')->insertGetId($new)] + $new;
                    $copied++;
                }
                $this->audit->insertMany('t_2024_finance_detail_licence', 'id', $inserted);

                return ['copied' => $copied, 'ignored' => $ignored];
            });
        }

        return $result + ['message' => $result['copied'] > 0
            ? $result['copied'].' ligne(s) reprise(s)'.($result['ignored'] > 0 ? ', '.$result['ignored'].' conservée(s) car déjà ajustée(s).' : '.')
            : 'Rien à reprendre : toutes les lignes sont déjà ajustées pour cette session.'];
    }

    /**
     * Retour au barème de base : supprime la surcharge d'une session (d'une seule filière si `mention` est donnée). Le barème de base ne se supprime jamais.
     *
     * @param  array<string, mixed>  $body  session_id, mention (facultative)
     * @return array{deleted: int, message: string}
     */
    public function reset(User $user, array $body): array
    {
        $this->require($user);
        [$session] = $this->target($body['session_id'] ?? null);
        if ($session === self::BASE) {
            throw new ApiProblem(422, 'validation.base', 'Suppression impossible', 'Le barème de base ne peut pas être supprimé.');
        }
        $mention = $this->mention($body['mention'] ?? '');

        $deleted = DB::transaction(function () use ($session, $mention) {
            $query = DB::table('t_2024_finance_detail_licence')->where('session_id', $session);
            if ($mention !== '') {
                $query->where('std_mention', $mention);
            }
            $rows = $query->get()->map(fn ($r) => (array) $r)->all();
            if ($rows !== []) {
                $ids = array_column($rows, 'id');
                DB::table('t_2024_finance_detail_licence')->whereIn('id', $ids)->delete();
                $this->audit->delete('t_2024_finance_detail_licence', 'id', $rows);
            }

            return count($rows);
        });

        return ['deleted' => $deleted, 'message' => $deleted > 0 ? $deleted.' ligne(s) supprimée(s) : cette session revient au barème de base.' : 'Cette session utilisait déjà le barème de base.'];
    }
}
