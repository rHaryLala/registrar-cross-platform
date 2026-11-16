<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Journal d'activité : écrit dans `t_audit_requete` / `t_audit_modification`, les MÊMES tables que l'ancienne application
 * (data/audit.php) et que la page « Journal d'activité » du registrariat lit : un seul journal pour les deux.
 *
 * L'ancienne app l'obtient automatiquement en observant chaque requête SQL (une enveloppe autour de PDO). Ici, chaque action
 * d'écriture DÉCLARE ce qu'elle a changé — insert, update (valeurs avant/après, champ par champ), delete — puis `commit()` écrit
 * une ligne de requête et une ligne par modification, au même format :
 *   INSERT : { cle, lignes: [{ cle, apres }], tronque }     UPDATE : { cle, lignes: [{ cle, avant, apres }], tronque }
 *   DELETE : { cle, lignes: [{ cle, avant }], tronque }
 *
 * Ce journal ne doit JAMAIS empêcher une action : toute erreur ici est signalée (report) puis ignorée. Les champs sensibles
 * (mot de passe, jeton, secret…) sont masqués partout, comme dans l'ancienne app.
 */
final class AuditTrail
{
    private const SENSIBLE = '~pass|pwd|mdp|secret|token|csrf|otp|remember|api_?key|signature_cle~i';

    private const MASQUE = '••••';

    /** @var list<array<string, mixed>> */
    private array $modifications = [];

    public function insert(string $table, string $keyColumn, array $row): void
    {
        $this->modifications[] = [
            'operation' => 'INSERT', 'table' => $table, 'lignes' => 1,
            'changements' => ['cle' => [$keyColumn], 'lignes' => [['cle' => [$keyColumn => $row[$keyColumn] ?? null], 'apres' => $this->mask($row)]], 'tronque' => false],
        ];
    }

    /**
     * Plusieurs lignes créées d'un coup (la liste d'appel d'une séance) : UNE entrée du journal, comme un INSERT multi-lignes.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertMany(string $table, string $keyColumn, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $shown = array_slice($rows, 0, 50);
        $this->modifications[] = [
            'operation' => 'INSERT', 'table' => $table, 'lignes' => count($rows),
            'changements' => ['cle' => [$keyColumn], 'lignes' => array_map(fn ($r) => ['cle' => [$keyColumn => $r[$keyColumn] ?? null], 'apres' => $this->mask($r)], $shown), 'tronque' => count($rows) > 50],
        ];
    }

    /** Une ligne modifiée : seules les colonnes qui ont VRAIMENT changé sont journalisées ; rien n'est écrit si rien n'a changé. */
    public function update(string $table, string $keyColumn, array $before, array $after): void
    {
        $a = [];
        $b = [];
        foreach ($before as $col => $old) {
            $new = $after[$col] ?? null;
            if ((string) $old !== (string) $new || ($old === null) !== ($new === null)) {
                $a[$col] = $this->maskValue($col, $old);
                $b[$col] = $this->maskValue($col, $new);
            }
        }
        if ($a === []) {
            return;
        }
        $this->modifications[] = [
            'operation' => 'UPDATE', 'table' => $table, 'lignes' => 1,
            'changements' => ['cle' => [$keyColumn], 'lignes' => [['cle' => [$keyColumn => $before[$keyColumn] ?? null], 'avant' => $a, 'apres' => $b]], 'tronque' => false],
        ];
    }

    /**
     * Plusieurs lignes modifiées d'un coup (une action groupée, une réinitialisation) : UNE entrée du journal, comme un UPDATE multi-lignes.
     * Chaque élément porte la clé, les colonnes AVANT et APRÈS ; seules les 50 premières lignes sont détaillées, le total est toujours exact
     * (`$total` : quand l'appelant ne transmet que ces premières lignes).
     *
     * @param  list<array{cle: mixed, avant: array<string, mixed>, apres: array<string, mixed>}>  $rows
     */
    public function updateMany(string $table, string $keyColumn, array $rows, ?int $total = null): void
    {
        $total ??= count($rows);
        if ($rows === [] || $total === 0) {
            return;
        }
        $shown = array_slice($rows, 0, 50);
        $this->modifications[] = [
            'operation' => 'UPDATE', 'table' => $table, 'lignes' => $total,
            'changements' => ['cle' => [$keyColumn], 'lignes' => array_map(fn ($r) => ['cle' => [$keyColumn => $r['cle']], 'avant' => $this->mask($r['avant']), 'apres' => $this->mask($r['apres'])], $shown), 'tronque' => $total > 50],
        ];
    }

    /** @param list<array<string, mixed>> $rows les lignes supprimées, telles qu'elles étaient */
    public function delete(string $table, string $keyColumn, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $shown = array_slice($rows, 0, 50);
        $this->modifications[] = [
            'operation' => 'DELETE', 'table' => $table, 'lignes' => count($rows),
            'changements' => ['cle' => [$keyColumn], 'lignes' => array_map(fn ($r) => ['cle' => [$keyColumn => $r[$keyColumn] ?? null], 'avant' => $this->mask($r)], $shown), 'tronque' => count($rows) > 50],
        ];
    }

    /** Abandonne ce qui est en attente (début d'une action, ou action échouée dont tout a été annulé). */
    public function reset(): void
    {
        $this->modifications = [];
    }

    public function isEmpty(): bool
    {
        return $this->modifications === [];
    }

    /**
     * Écrit le journal de l'action et vide la liste. `$label` est le libellé lisible (« Présence · pointer »).
     *
     * @param  array<string, mixed>  $data  les données reçues (les champs sensibles sont masqués)
     */
    public function commit(Request $request, ?User $user, string $label, int $status = 200, array $data = []): void
    {
        $modifications = $this->modifications;
        $this->modifications = [];
        if ($modifications === []) {
            return;   // rien n'a changé : pas de ligne de journal (comme l'ancienne app)
        }

        try {
            $now = Carbon::now();
            $tables = array_values(array_unique(array_column($modifications, 'table')));
            $id = DB::table('t_audit_requete')->insertGetId([
                'date_action' => $this->ts($now), 'duree_ms' => 0, 'type_action' => 'modification', 'libelle' => mb_substr($label, 0, 250),
                'user_id' => $user?->id, 'user_pseudo' => $user ? mb_substr((string) $user->pseudo, 0, 95) : null,
                'user_nom' => $user ? mb_substr(trim($user->nom.' '.$user->prenom), 0, 195) : null, 'user_niveau' => $user ? (int) $user->level : null,
                'methode' => substr($request->method(), 0, 10), 'url' => mb_substr($request->getRequestUri(), 0, 1990),
                'chemin' => mb_substr($request->getPathInfo(), 0, 490), 'referent' => null, 'statut_http' => $status, 'redirection' => null, 'ajax' => 1,
                'ip' => substr((string) $request->ip(), 0, 45) ?: null, 'appareil' => self::device((string) $request->userAgent()),
                'user_agent' => $request->userAgent() ? mb_substr($request->userAgent(), 0, 490) : null,
                'donnees' => $data === [] ? null : json_encode(['json' => $this->mask($data)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'nb_modifications' => count($modifications), 'modifications_ignorees' => 0,
                'tables_touchees' => ','.implode(',', $tables).',',
            ]);

            foreach ($modifications as $i => $m) {
                DB::table('t_audit_modification')->insert([
                    'requete_id' => $id, 'ordre' => $i + 1, 'date_action' => $this->ts($now), 'operation' => $m['operation'], 'table_nom' => $m['table'],
                    'lignes' => $m['lignes'], 'requete_sql' => $m['operation'].' '.$m['table'].' (API v1 : '.$request->method().' '.$request->getPathInfo().')',
                    'parametres' => null, 'changements' => json_encode($m['changements'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'transaction_statut' => 'validee',
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** « Android · Chrome », comme l'ancienne app (data/audit.php, Audit::appareil). */
    public static function device(string $ua): ?string
    {
        if ($ua === '') {
            return null;
        }
        $os = match (true) {
            (bool) preg_match('~iPhone~', $ua) => 'iPhone',
            (bool) preg_match('~iPad~', $ua) => 'iPad',
            (bool) preg_match('~Android~', $ua) => 'Android',
            (bool) preg_match('~Windows~', $ua) => 'Windows',
            (bool) preg_match('~Mac OS X|Macintosh~', $ua) => 'macOS',
            (bool) preg_match('~Linux~', $ua) => 'Linux',
            default => null,
        };
        $browser = match (true) {
            (bool) preg_match('~Edg/~', $ua) => 'Edge',
            (bool) preg_match('~OPR/|Opera~', $ua) => 'Opera',
            (bool) preg_match('~Firefox/~', $ua) => 'Firefox',
            (bool) preg_match('~Chrome/|CriOS/~', $ua) => 'Chrome',
            (bool) preg_match('~Safari/~', $ua) => 'Safari',
            (bool) preg_match('~curl|bot|spider|crawl~i', $ua) => 'Robot',
            default => null,
        };

        $label = trim(implode(' · ', array_filter([$os, $browser])));

        return $label === '' ? null : $label;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mask(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = is_array($v) ? $this->mask($v) : $this->maskValue((string) $k, $v);
        }

        return $out;
    }

    private function maskValue(string $column, mixed $value): mixed
    {
        return preg_match(self::SENSIBLE, $column) === 1 ? self::MASQUE : $value;
    }

    private function ts(Carbon $c): string
    {
        return $c->format('Y-m-d H:i:s.v');
    }
}
