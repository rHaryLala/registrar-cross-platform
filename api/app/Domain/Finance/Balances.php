<?php

namespace App\Domain\Finance;

use App\Domain\Directory\Directory;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solde de compte par session (port de `src/finance/account_balance.php` et `account_balance_update.php`).
 *
 * Accès : niveaux 1 à 4 (superadmin, administration, registrariat, comptabilité), pour la lecture comme pour la modification. L'ancienne page s'arrêtait
 * au niveau 3 : la comptabilité, qui tient les comptes, y a accès ici (décision du 16/11/2026).
 *
 * Les étudiants d'une session sont ceux qui y sont inscrits (`t_2024_inscription_session`), hors suspendus, retirés de l'université et supprimés ; chaque
 * matricule est rapproché de sa ligne `finance` par le matricule tel quel, à défaut par sa partie numérique (`20481MG` → 20481). Le solde est le solde
 * enregistré (`balance_amount`) ; à défaut, total à payer moins les cinq versements. Positif = DÉBITAIRE (l'étudiant doit), négatif = CRÉDITAIRE.
 *
 * Ce qui change : une valeur enregistrée identique à l'actuelle n'est plus une erreur « ligne introuvable » (l'ancien code se fiait au nombre de lignes
 * MODIFIÉES) ; l'écriture laisse l'auteur et l'heure dans la ligne finance et une entrée dans le journal d'activité ; les étudiants sans ligne finance sont
 * annoncés comme tels (l'ancienne page les montrait « Solde 0 ») ; filtre par état, tri par solde, totaux.
 */
final class Balances
{
    public const PER_PAGE = 200;

    public const MAX_ABS = 9999999999999.99;   // decimal(15,2)

    public const STATES = ['debitaire' => 'Débitaire', 'creditaire' => 'Créditaire', 'solde' => 'Soldé', 'sans_ligne' => 'Sans ligne finance'];

    public function __construct(private readonly AuditTrail $audit) {}

    public static function allows(User $user): bool
    {
        return $user->isActive() && (int) $user->level >= 1 && (int) $user->level <= 4;
    }

    public function require(User $user): void
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.balances', 'Accès refusé', 'Le solde de compte est réservé au superadmin, à l’administration, au registrariat et à la comptabilité.');
        }
    }

    // ------------------------------------------------------------------ sessions

    /** @return list<array{id: int, label: string, year: string, semester: string, selected: bool}> */
    public function sessions(): array
    {
        return array_map(function ($s) {
            $year = trim((string) $s->session_year);
            $semester = trim((string) $s->session_semester);
            $extra = trim($year.($semester !== '' ? ' - Semestre '.$semester : ''));
            $name = trim((string) ($s->session_name ?? ''));

            return [
                'id' => (int) $s->session_id, 'label' => ($name !== '' ? $name : 'Session '.(int) $s->session_id).($extra !== '' ? ' ('.$extra.')' : ''),
                'year' => $year, 'semester' => $semester, 'selected' => (int) $s->selected === 1,
            ];
        }, DB::select('SELECT session_id, session_name, session_year, session_semester, selected FROM t_2023_session ORDER BY session_year DESC, session_semester DESC, session_id DESC'));
    }

    /**
     * La session demandée ; sans demande, la session active, à défaut la plus récente. Une session demandée mais inconnue retombe sur la plus récente
     * (et non sur la session active) : comme l'ancienne page.
     *
     * @param  list<array{id: int, selected: bool}>  $sessions
     */
    public function chooseSession(array $sessions, int $requested): ?int
    {
        $ids = array_column($sessions, 'id');
        if ($requested > 0) {
            return in_array($requested, $ids, true) ? $requested : ($ids[0] ?? null);
        }
        $active = (int) (DB::selectOne('SELECT session_id FROM t_2023_session WHERE selected = 1 ORDER BY session_id DESC LIMIT 1')->session_id ?? 0);

        return $active > 0 && in_array($active, $ids, true) ? $active : ($ids[0] ?? null);
    }

    // ------------------------------------------------------------------ calcul

    /** Un montant lu dans la base : espaces (dont l'insécable) retirés, virgule décimale admise ; illisible = 0 (`$toNumber` de l'ancienne page). */
    public static function number(mixed $value): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }
        $raw = str_replace(',', '.', str_replace([' ', "\xC2\xA0"], '', $raw));

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    public static function stateOf(bool $hasFinance, float $balance): string
    {
        return ! $hasFinance ? 'sans_ligne' : ($balance > 0 ? 'debitaire' : ($balance < 0 ? 'creditaire' : 'solde'));
    }

    public function hasBalanceColumn(): bool
    {
        return Schema::hasColumn('finance', 'balance_amount');
    }

    /**
     * Tous les étudiants de la session avec leur solde, dans l'ordre de l'ancienne page (matricule décroissant).
     *
     * @return list<array{student_id: string, account_code: string, name: string, finance_id: string|null, balance: float, state: string}>
     */
    public function rowsFor(int $sessionId): array
    {
        $students = DB::select(
            'SELECT DISTINCT std.student_id, std.account_code, std.student_nom, std.student_prenom
               FROM t_2024_inscription_session ins
               INNER JOIN tbl_2024_etudiant std ON std.id = (SELECT e2.id FROM tbl_2024_etudiant e2 WHERE e2.student_id = ins.student_id ORDER BY e2.remove ASC, e2.id ASC LIMIT 1)
              WHERE ins.session_id = ?
                AND (std.suspended IS NULL OR std.suspended != 1)
                AND (std.retrait_universite IS NULL OR std.retrait_universite = 0)
                AND std.remove != 1
              ORDER BY std.student_id DESC',
            [$sessionId],
        );

        $columns = $this->hasBalanceColumn() ? 'student_id, balance_amount, total_payment, amount_pay_1, amount_pay_2, amount_pay_3, amount_pay_4, amount_pay_5'
            : 'student_id, total_payment, amount_pay_1, amount_pay_2, amount_pay_3, amount_pay_4, amount_pay_5';
        $balances = [];
        $actual = [];
        foreach (DB::select("SELECT $columns FROM finance WHERE session_id = ?", [$sessionId]) as $f) {
            $raw = trim((string) $f->student_id);
            if ($raw === '') {
                continue;
            }
            $stored = $f->balance_amount ?? null;
            if ($stored !== null && trim((string) $stored) !== '') {
                $value = self::number($stored);
            } else {
                $paid = self::number($f->amount_pay_1) + self::number($f->amount_pay_2) + self::number($f->amount_pay_3) + self::number($f->amount_pay_4) + self::number($f->amount_pay_5);
                $value = self::number($f->total_payment) - $paid;
            }
            $balances[$raw] = $value;
            $actual[$raw] = $raw;
            $digits = preg_replace('/\D+/', '', $raw);
            if ($digits !== '' && ctype_digit($digits)) {
                $balances[(string) (int) $digits] = $value;
                $actual[(string) (int) $digits] = $raw;
            }
        }

        $rows = [];
        foreach ($students as $s) {
            $matricule = trim((string) $s->student_id);
            $candidates = $matricule !== '' ? [$matricule] : [];
            $digits = preg_replace('/\D+/', '', $matricule);
            if ($digits !== '' && ctype_digit($digits)) {
                $candidates[] = (string) (int) $digits;
            }
            $financeId = null;
            $balance = 0.0;
            foreach ($candidates as $c) {
                if (isset($balances[$c])) {
                    $financeId = (string) ($actual[$c] ?? $c);
                    $balance = (float) $balances[$c];
                    break;
                }
            }
            $rows[] = [
                'student_id' => $matricule, 'account_code' => trim((string) ($s->account_code ?? '')),
                'name' => trim(strtoupper((string) ($s->student_nom ?? '')).' '.(string) ($s->student_prenom ?? '')),
                'finance_id' => $financeId, 'balance' => $balance, 'state' => self::stateOf($financeId !== null, $balance),
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ liste

    private const SORTS = ['matricule' => ['Matricule', 'desc'], 'nom' => ['Nom', 'asc'], 'solde' => ['Solde (dette en premier)', 'desc'], 'code' => ['Account code', 'asc']];

    /**
     * @param  array<string, mixed>  $query  session, q, etat (un ou plusieurs états), tri, sens, page
     * @return array<string, mixed>
     */
    public function listing(User $user, array $query): array
    {
        $this->require($user);
        $sessions = $this->sessions();
        $sessionId = $this->chooseSession($sessions, is_scalar($query['session'] ?? null) ? (int) $query['session'] : 0);
        $all = $sessionId === null ? [] : $this->rowsFor($sessionId);

        $q = trim((string) preg_replace('~\s+~u', ' ', mb_substr(is_scalar($query['q'] ?? null) ? (string) $query['q'] : '', 0, 120)));
        $words = $q === '' ? [] : array_slice(array_map([Directory::class, 'alphaKey'], explode(' ', $q)), 0, 6);
        $matching = $words === [] ? $all : array_values(array_filter($all, function ($r) use ($words) {
            $hay = Directory::alphaKey($r['student_id'].' '.$r['account_code'].' '.$r['name']);
            foreach ($words as $w) {
                if (! str_contains($hay, $w)) {
                    return false;
                }
            }

            return true;
        }));

        $wanted = $query['etat'] ?? [];
        $wanted = array_values(array_intersect(array_keys(self::STATES), array_map('strval', is_array($wanted) ? $wanted : [$wanted])));
        $counts = array_fill_keys(array_keys(self::STATES), 0);
        foreach ($matching as $r) {
            $counts[$r['state']]++;
        }
        $kept = $wanted === [] ? $matching : array_values(array_filter($matching, fn ($r) => in_array($r['state'], $wanted, true)));

        $sort = is_scalar($query['tri'] ?? null) && isset(self::SORTS[(string) $query['tri']]) ? (string) $query['tri'] : 'matricule';
        $dir = strtolower(is_scalar($query['sens'] ?? null) ? (string) $query['sens'] : '');
        $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : self::SORTS[$sort][1];
        $kept = $this->sorted($kept, $sort, $dir);

        $page = max(0, min(200, is_scalar($query['page'] ?? null) ? (int) $query['page'] : 0));
        $total = count($kept);
        $slice = array_slice($kept, $page * self::PER_PAGE, self::PER_PAGE);
        $shown = min($total, ($page + 1) * self::PER_PAGE);

        $debit = 0.0;
        $credit = 0.0;
        foreach ($kept as $r) {
            if ($r['balance'] > 0) {
                $debit += $r['balance'];
            } else {
                $credit += -$r['balance'];
            }
        }

        return [
            'sessions' => $sessions, 'session_id' => $sessionId,
            'total' => $total, 'shown' => $shown, 'remaining' => max(0, $total - $shown), 'per_page' => self::PER_PAGE, 'students_in_session' => count($all),
            'rows' => array_map(fn ($r) => $r + ['editable' => $r['finance_id'] !== null], $slice),
            'counts' => $counts, 'states' => self::STATES,
            'totals' => ['debit' => round($debit, 2), 'credit' => round($credit, 2), 'net' => round($debit - $credit, 2)],
            'sorts' => array_map(fn ($s) => ['label' => $s[0], 'direction' => $s[1]], self::SORTS),
            'state' => ['q' => $q, 'etat' => $wanted, 'sort' => $sort, 'direction' => $dir, 'page' => $page],
            'balance_column' => $this->hasBalanceColumn(),
        ];
    }

    /** @param list<array<string, mixed>> $rows déjà dans l'ordre matricule décroissant (celui de l'ancienne page) */
    private function sorted(array $rows, string $sort, string $dir): array
    {
        if ($sort === 'matricule') {
            return $dir === 'desc' ? $rows : array_reverse($rows);
        }
        $sign = $dir === 'asc' ? 1 : -1;
        usort($rows, function ($a, $b) use ($sort, $sign) {
            return $sign * match ($sort) {
                'nom' => strcmp(Directory::alphaKey($a['name']), Directory::alphaKey($b['name'])),
                'code' => ($a['account_code'] === '') <=> ($b['account_code'] === '') ?: strcmp($a['account_code'], $b['account_code']),
                default => $a['balance'] <=> $b['balance'],
            };
        });

        return $rows;
    }

    // ------------------------------------------------------------------ écriture

    /** Un montant saisi : même lecture que l'ancien code (espaces et insécables retirés, virgule admise), mais refuse ce qui n'est pas un nombre fini de la plage de la colonne. */
    public static function parseInput(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = str_replace(',', '.', str_replace([' ', "\xC2\xA0"], '', trim($value)));
        } else {
            return null;
        }
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }
        $n = (float) $raw;

        return is_finite($n) && abs($n) <= self::MAX_ABS ? round($n, 2) : null;
    }

    /**
     * Corrige le solde enregistré d'un étudiant pour une session.
     *
     * @return array{changed: bool, row: array<string, mixed>}
     */
    public function update(User $user, int $sessionId, string $financeId, mixed $value): array
    {
        $this->require($user);
        $amount = self::parseInput($value);
        if ($amount === null) {
            throw new ApiProblem(422, 'validation.balance', 'Solde invalide', 'Saisissez un montant (par exemple 150000 ou -25 000,50).');
        }
        if (! $this->hasBalanceColumn()) {
            throw new ApiProblem(409, 'conflict.balance_column', 'Modification indisponible', 'La colonne du solde n’existe pas encore sur cette base.');
        }
        if (preg_match('/^\d{1,10}$/', $financeId) !== 1) {
            throw new ApiProblem(404, 'not_found.finance_row', 'Ligne introuvable', 'Aucune ligne finance pour cet étudiant dans cette session.');
        }

        $changed = DB::transaction(function () use ($user, $sessionId, $financeId, $amount) {
            $r = DB::selectOne('SELECT student_id, balance_amount, last_change_user_id, last_change_datetime FROM finance WHERE session_id = ? AND student_id = ? FOR UPDATE', [$sessionId, (int) $financeId]);
            if (! $r) {
                throw new ApiProblem(404, 'not_found.finance_row', 'Ligne introuvable', 'Aucune ligne finance pour cet étudiant dans cette session.');
            }
            if ($r->balance_amount !== null && abs((float) $r->balance_amount - $amount) < 0.005) {
                return false;
            }
            $now = Carbon::now()->format('Y-m-d H:i:s');
            DB::update('UPDATE finance SET balance_amount = ?, last_change_user_id = ?, last_change_datetime = ? WHERE session_id = ? AND student_id = ?', [number_format($amount, 2, '.', ''), (string) $user->id, $now, $sessionId, (int) $financeId]);   // lié en texte : un flottant énorme serait écrit « 1.0E+13 »
            $this->audit->update('finance', 'student_id',
                ['student_id' => $r->student_id, 'balance_amount' => $r->balance_amount, 'last_change_user_id' => $r->last_change_user_id, 'last_change_datetime' => $r->last_change_datetime],
                ['student_id' => $r->student_id, 'balance_amount' => number_format($amount, 2, '.', ''), 'last_change_user_id' => (string) $user->id, 'last_change_datetime' => $now]);

            return true;
        });

        $row = collect($this->rowsFor($sessionId))->first(fn ($x) => $x['finance_id'] === (string) (int) $financeId);

        return ['changed' => $changed, 'row' => $row ? $row + ['editable' => true] : ['finance_id' => $financeId, 'balance' => $amount]];
    }
}
