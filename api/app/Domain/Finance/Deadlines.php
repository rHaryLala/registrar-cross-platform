<?php

namespace App\Domain\Finance;

use App\Domain\Student\EnrollmentRules;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Échéances de paiement : la date limite de chaque tranche, session par session (port de `src/finance/echeances.php` et `data/paiement.echeances.php`).
 *
 * Un type de paiement (`t_2024_etudiant_finace.mode_payement`) partage le reste à payer en tranches : A en une fois, B en deux, E en quatre ; C et D ne sont plus
 * proposés au guichet mais restent sur d'anciens dossiers. Les dates sont lues par la fiche d'inscription (PDF et écran) et par le guichet.
 *
 * Accès : niveaux 1 à 4 (superadmin, administration, registrariat, comptabilité). L'ancienne page s'arrêtait au niveau 3 ; la comptabilité y est admise comme sur
 * le solde de compte (décision du 16/11/2026, à confirmer pour cette page).
 *
 * Ce qui ne change pas : mêmes types et parts, chaque tranche doit venir APRÈS la précédente du même type, une date vide retire la tranche, un type absent n'est
 * pas touché, l'auteur et l'heure ne changent que si la date change. Ce qui change : chaque ligne écrite est journalisée (avant/après), les erreurs de saisie
 * sont rendues par champ.
 */
final class Deadlines
{
    /** Les types encore proposés au guichet. */
    public const PROPOSED = ['A', 'B', 'E'];

    /** L'ordre des semestres dans une année (1 puis 3 puis 2 puis 4), comme l'ancienne page. */
    private const SEMESTER_RANK = [1 => 0, 3 => 1, 2 => 2, 4 => 3];

    public function __construct(private readonly AuditTrail $audit) {}

    public static function allows(User $user): bool
    {
        return $user->isActive() && (int) $user->level >= 1 && (int) $user->level <= 4;
    }

    public function require(User $user): void
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.deadlines', 'Accès refusé', 'Les échéances de paiement sont réservées au superadmin, à l’administration, au registrariat et à la comptabilité.');
        }
    }

    /** @return array<string, array{label: string, parts: list<int>, proposed: bool}> */
    public static function types(): array
    {
        $out = [];
        foreach (EnrollmentRules::paymentTypes() as $code => $t) {
            $out[$code] = ['label' => $t['label'], 'parts' => array_values($t['parts']), 'proposed' => in_array($code, self::PROPOSED, true)];
        }

        return $out;
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('t_paiement_echeance');
    }

    private static function validDay(mixed $v): ?string
    {
        $v = trim((string) $v);
        $d = \DateTime::createFromFormat('!Y-m-d', $v);

        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    // ------------------------------------------------------------------ lecture

    /** Les dates d'une session : ['B' => [1 => '2026-04-30', 2 => '2026-07-10']]. @return array<string, array<int, string>> */
    public function datesOf(int $sessionId): array
    {
        if ($sessionId <= 0 || ! $this->tableExists()) {
            return [];
        }
        $dates = [];
        foreach (DB::select('SELECT type_paiement, tranche, date_limite FROM t_paiement_echeance WHERE session_id = ? ORDER BY type_paiement, tranche', [$sessionId]) as $l) {
            $dates[$l->type_paiement][(int) $l->tranche] = (string) $l->date_limite;
        }

        return $dates;
    }

    /**
     * Les sessions de la plus ancienne à la plus récente (année, rang du semestre, identifiant).
     *
     * @return list<array{id: int, label: string, year: string, semester: int, start: string|null, end: string|null, configured: bool}>
     */
    public function sessions(): array
    {
        $rows = DB::select('SELECT session_id, session_name, session_year, session_semester, date_entry, graduation_date FROM t_2023_session');
        usort($rows, fn ($a, $b) => [$a->session_year, self::SEMESTER_RANK[(int) $a->session_semester] ?? 9, (int) $a->session_id] <=> [$b->session_year, self::SEMESTER_RANK[(int) $b->session_semester] ?? 9, (int) $b->session_id]);
        $configured = $this->tableExists() ? array_map('intval', DB::table('t_paiement_echeance')->distinct()->pluck('session_id')->all()) : [];

        return array_map(fn ($s) => [
            'id' => (int) $s->session_id, 'label' => $s->session_name.' '.$s->session_year, 'year' => (string) $s->session_year, 'semester' => (int) $s->session_semester,
            'start' => self::validDay($s->date_entry), 'end' => self::validDay($s->graduation_date), 'configured' => in_array((int) $s->session_id, $configured, true),
        ], $rows);
    }

    /**
     * La session affichée : celle demandée, sinon celle où l'on inscrit en ce moment (la dernière ligne de frais créée), sinon la session en cours, sinon la plus récente.
     *
     * @param  list<array{id: int}>  $sessions
     */
    public function chooseSession(array $sessions, int $requested): ?int
    {
        $ids = array_column($sessions, 'id');
        if (in_array($requested, $ids, true)) {
            return $requested;
        }
        $latest = (int) (DB::selectOne('SELECT session_id FROM t_2024_etudiant_finace WHERE `remove` != 1 AND session_id > 0 ORDER BY id DESC LIMIT 1')->session_id ?? 0);
        if (in_array($latest, $ids, true)) {
            return $latest;
        }
        $active = (int) (DB::selectOne('SELECT session_id FROM t_2023_session WHERE selected = 1 LIMIT 1')->session_id ?? 0);
        if (in_array($active, $ids, true)) {
            return $active;
        }

        return $ids === [] ? null : $ids[array_key_last($ids)];
    }

    /** @return array<string, mixed> */
    public function show(User $user, int $requested): array
    {
        $this->require($user);
        $sessions = $this->sessions();
        $id = $this->chooseSession($sessions, $requested);
        $types = self::types();
        $base = ['types_all' => $types, 'sessions' => $sessions, 'table_exists' => $this->tableExists()];
        if ($id === null) {
            return $base + ['session' => null];
        }

        $index = array_search($id, array_column($sessions, 'id'), true);
        $session = $sessions[$index];
        $dates = $this->datesOf($id);

        $counts = [];
        $without = 0;
        foreach (DB::select('SELECT COALESCE(mode_payement, "") AS type, COUNT(DISTINCT student_id) AS n FROM t_2024_etudiant_finace WHERE session_id = ? AND `remove` != 1 GROUP BY type', [$id]) as $l) {
            if (isset($types[$l->type])) {
                $counts[$l->type] = (int) $l->n;
            } else {
                $without += (int) $l->n;
            }
        }
        $enrolled = array_sum($counts) + $without;

        // Les types montrés : ceux du guichet, et les anciens encore portés par un dossier de la session ou déjà datés.
        $shown = array_filter($types, fn ($t, $c) => $t['proposed'] || ! empty($counts[$c]) || ! empty($dates[$c]), ARRAY_FILTER_USE_BOTH);

        // « Reprendre » : le même semestre l'année précédente, s'il a des dates pour un des types montrés (elles reviennent 52 semaines plus tard, même jour).
        $copy = null;
        if (preg_match('~^(\d{4}) - (\d{4})$~', $session['year'], $m) === 1) {
            $before = ((int) $m[1] - 1).' - '.((int) $m[2] - 1);
            foreach ($sessions as $s) {
                if ($s['year'] === $before && $s['semester'] === $session['semester']) {
                    $previous = array_intersect_key($this->datesOf($s['id']), $shown);
                    if ($previous) {
                        $copy = ['session_id' => $s['id'], 'label' => $s['label'], 'dates' => $previous];
                    }
                }
            }
        }

        $shownDates = array_intersect_key($dates, $shown);

        return $base + [
            'session' => $session,
            'previous' => $index > 0 ? $sessions[$index - 1]['id'] : null,
            'next' => $index < count($sessions) - 1 ? $sessions[$index + 1]['id'] : null,
            'dates' => $dates,
            'types' => $shown,
            'counts' => $counts, 'without_type' => $without, 'enrolled' => $enrolled,
            'filled' => array_sum(array_map('count', $shownDates)),
            'expected' => array_sum(array_map(fn ($t) => count($t['parts']), $shown)),
            'copy' => $copy,
        ];
    }

    // ------------------------------------------------------------------ écriture

    /**
     * Remplace les dates d'une session. `$dates` : ['A' => [1 => 'AAAA-MM-JJ'], 'B' => [1 => '…', 2 => '']] ; une date vide (ou absente d'un type présent) retire la
     * tranche, un type absent n'est pas touché ; chaque tranche vient après la précédente du même type.
     *
     * @param  array<string, mixed>  $dates
     * @return array{changed: int, dates: array<string, array<int, string>>, message: string}
     */
    public function save(User $user, int $sessionId, array $dates): array
    {
        $this->require($user);
        $types = self::types();
        $clean = [];
        foreach ($dates as $type => $slots) {
            if (! isset($types[$type]) || ! is_array($slots)) {
                throw new ApiProblem(422, 'validation.deadlines', 'Échéances invalides', 'Type de paiement inconnu : '.(is_scalar($type) ? $type : '?').'.');
            }
            $previous = null;
            foreach ($types[$type]['parts'] as $i => $part) {
                $rank = $i + 1;
                $date = trim((string) ($slots[$rank] ?? ''));
                if ($date === '') {
                    $clean[$type][$rank] = null;

                    continue;
                }
                if (self::validDay($date) === null) {
                    throw new ApiProblem(422, 'validation.deadlines', 'Échéances invalides', 'Type '.$type.', tranche '.$rank.' : date illisible.', ['field' => "dates.$type.$rank"]);
                }
                if ($previous !== null && $date <= $previous) {
                    throw new ApiProblem(422, 'validation.deadlines', 'Échéances invalides', 'Type '.$type.' : chaque tranche doit venir après la précédente.', ['field' => "dates.$type.$rank"]);
                }
                $previous = $date;
                $clean[$type][$rank] = $date;
            }
        }
        if (! DB::table('t_2023_session')->where('session_id', $sessionId)->exists()) {
            throw new ApiProblem(404, 'not_found.session', 'Session introuvable', 'Cette session n’existe pas : rechargez la page.');
        }
        if (! $this->tableExists()) {
            throw new ApiProblem(409, 'conflict.deadlines_table', 'Enregistrement indisponible', 'La table des échéances n’existe pas encore sur cette base.');
        }

        $changed = DB::transaction(function () use ($user, $sessionId, $clean) {
            $changed = 0;
            $now = Carbon::now()->format('Y-m-d H:i:s');
            $existing = DB::table('t_paiement_echeance')->where('session_id', $sessionId)->get()->keyBy(fn ($r) => $r->type_paiement.':'.$r->tranche);
            foreach ($clean as $type => $slots) {
                foreach ($slots as $rank => $date) {
                    $row = $existing->get($type.':'.$rank);
                    if ($date === null) {
                        if ($row) {
                            DB::table('t_paiement_echeance')->where('id', $row->id)->delete();
                            $this->audit->delete('t_paiement_echeance', 'id', [(array) $row]);
                            $changed++;
                        }
                    } elseif (! $row) {
                        $new = ['session_id' => $sessionId, 'type_paiement' => $type, 'tranche' => $rank, 'date_limite' => $date, 'modifie_par' => $user->id, 'modifie_le' => $now];
                        $id = DB::table('t_paiement_echeance')->insertGetId($new);
                        $this->audit->insert('t_paiement_echeance', 'id', ['id' => $id] + $new);
                        $changed++;
                    } elseif ((string) $row->date_limite !== $date) {
                        DB::table('t_paiement_echeance')->where('id', $row->id)->update(['date_limite' => $date, 'modifie_par' => $user->id, 'modifie_le' => $now]);
                        $this->audit->update('t_paiement_echeance', 'id', (array) $row, ['id' => $row->id, 'session_id' => $sessionId, 'type_paiement' => $type, 'tranche' => $rank, 'date_limite' => $date, 'modifie_par' => $user->id, 'modifie_le' => $now]);
                        $changed++;
                    }
                }
            }

            return $changed;
        });

        return ['changed' => $changed, 'dates' => $this->datesOf($sessionId), 'message' => $changed > 0 ? 'Échéances enregistrées.' : 'Rien n’avait changé.'];
    }
}
