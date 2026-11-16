<?php

namespace App\Domain\Finance;

use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frais supplémentaires du barème (port de `data/finance.frais.php` et `app/finance-frais.save.php`) : des frais ajoutés par le registrariat (« Alliance
 * française »…) qui deviennent une LIGNE du tableau du barème, saisissable par filière, niveau et statut, et sont facturés à l'étudiant que le barème désigne.
 *
 * Trois tables : `t_frais_perso` (le frais : libellé, ordre, actif, à qui, quels semestres), `t_frais_perso_montant` (son montant, avec la même clé que le barème :
 * session, filière, niveau, statut, semestre) et `t_2024_etudiant_frais_perso` (ce qui a été facturé à un étudiant : libellé et montant figés).
 *
 * Repli sur la base comme le barème : le montant de la session l'emporte, sinon celui de la session 0. Une case VIDE efface la ligne (retour à la base) ;
 * 0 est une valeur (« rien à payer ici »), qui empêche le repli.
 *
 * Ce module n'écrit que les DÉFINITIONS et les MONTANTS du barème ; l'application aux étudiants (inscription au guichet) viendra avec le lot du guichet.
 * L'ancien code créait les tables à la première ouverture de la page ; ici une migration le fait (rejouable) et l'écran dit si elles manquent.
 */
final class ExtraFees
{
    /** À qui un frais s'applique, au-delà du couple filière / niveau / statut. @var array<string, string> */
    public const CONDITIONS = [
        'aucune' => 'Tous ceux que le barème désigne',
        'diplomant' => 'Étudiants diplômants',
        'nouvel_etudiant' => 'Nouveaux étudiants',
        'abonne' => 'Abonnés à la cafétéria',
        'interne' => 'Étudiants internes',
    ];

    public function __construct(private readonly AuditTrail $audit) {}

    public function installed(): bool
    {
        return Schema::hasTable('t_frais_perso') && Schema::hasTable('t_frais_perso_montant');
    }

    private function requireInstalled(): void
    {
        if (! $this->installed()) {
            throw new ApiProblem(409, 'conflict.extra_fees_tables', 'Frais supplémentaires indisponibles', 'Les tables des frais supplémentaires n’existent pas encore sur cette base.');
        }
    }

    /** Semestres où le frais s'applique : chaîne vide = tous, sinon une liste (« 2 », « 1,2 »). */
    public static function appliesToSemester(string $semesters, int $semester): bool
    {
        $semesters = trim($semesters);

        return $semesters === '' || in_array((string) $semester, array_map('trim', explode(',', $semesters)), true);
    }

    /** Liste de semestres propre : chiffres 1 à 4, sans doublon, ordonnés. */
    public static function validSemesters(mixed $semesters): string
    {
        $seen = [];
        foreach (is_array($semesters) ? $semesters : explode(',', (string) (is_scalar($semesters) ? $semesters : '')) as $s) {
            $n = (int) trim((string) (is_scalar($s) ? $s : ''));
            if ($n >= 1 && $n <= 4) {
                $seen[$n] = true;
            }
        }
        ksort($seen);

        return implode(',', array_keys($seen));
    }

    /** @return list<array{id: int, label: string, active: bool, order: int, condition: string, semesters: string}> */
    public function definitions(bool $onlyActive = true): array
    {
        if (! $this->installed()) {
            return [];
        }
        $rows = DB::select('SELECT id, libelle, actif, ordre, condition_frais, semestres FROM t_frais_perso'.($onlyActive ? ' WHERE actif = 1' : '').' ORDER BY ordre, libelle');

        return array_map(fn ($l) => ['id' => (int) $l->id, 'label' => (string) $l->libelle, 'active' => (int) $l->actif === 1, 'order' => (int) $l->ordre, 'condition' => (string) $l->condition_frais, 'semesters' => (string) $l->semestres], $rows);
    }

    private static function label(mixed $label): string
    {
        $label = trim((string) preg_replace('~\s+~u', ' ', is_scalar($label) ? (string) $label : ''));
        if ($label === '' || mb_strlen($label) > 120) {
            throw new ApiProblem(422, 'validation.label', 'Libellé invalide', 'Le libellé doit faire de 1 à 120 caractères.');
        }

        return $label;
    }

    private static function condition(mixed $condition): string
    {
        $condition = is_scalar($condition) ? (string) $condition : '';
        if (! array_key_exists($condition, self::CONDITIONS)) {
            throw new ApiProblem(422, 'validation.condition', 'Condition inconnue', 'Condition inconnue : '.$condition);
        }

        return $condition;
    }

    // ------------------------------------------------------------------ grille

    /**
     * Les montants des frais actifs d'une filière et d'un semestre : une case par niveau et statut, avec le repli sur la base.
     *
     * @param  list<array{id: int}>  $fees
     * @return array<int, array<string, array{amount: float|null, inherited: bool}>>
     */
    public function grid(int $sessionId, string $mention, int $semester, array $fees): array
    {
        $read = function (int $sid) use ($mention, $semester): array {
            $byFee = [];
            foreach (DB::select('SELECT frais_id, level, std_status, montant FROM t_frais_perso_montant WHERE session_id = ? AND std_mention = ? AND semester = ?', [$sid, $mention, $semester]) as $l) {
                $byFee[(int) $l->frais_id][$l->level.'|'.$l->std_status] = (float) $l->montant;
            }

            return $byFee;
        };
        $base = $read(Tariff::BASE);
        $over = $sessionId !== Tariff::BASE ? $read($sessionId) : [];

        $grid = [];
        foreach ($fees as $f) {
            foreach (Tariff::levels() as $n) {
                foreach (Tariff::statuses() as $status) {
                    $key = $n['level'].'|'.$status;
                    $grid[$f['id']][$key] = isset($over[$f['id']][$key]) ? ['amount' => $over[$f['id']][$key], 'inherited' => false]
                        : (isset($base[$f['id']][$key]) ? ['amount' => $base[$f['id']][$key], 'inherited' => $sessionId !== Tariff::BASE] : ['amount' => null, 'inherited' => false]);
                }
            }
        }

        return $grid;
    }

    /**
     * Enregistre les montants des frais d'une filière : une case vide efface la ligne (retour à la base), 0 est une valeur.
     *
     * @param  array<int|string, mixed>  $values  [frais_id => ['niveau|statut' => montant]]
     * @return array{written: int, cleared: int}
     */
    public function saveAmounts(int $sessionId, string $mention, int $semester, array $values): array
    {
        $this->requireInstalled();
        $known = array_column($this->definitions(false), 'id');
        $levels = array_column(Tariff::levels(), 'level');
        $statuses = Tariff::statuses();

        $written = 0;
        $cleared = 0;
        foreach ($values as $feeId => $cells) {
            $feeId = (int) $feeId;
            if (! in_array($feeId, $known, true) || ! is_array($cells)) {
                continue;   // frais inconnu : rien ne vient du client sans vérification
            }
            foreach ($cells as $key => $amount) {
                [$level, $status] = array_pad(explode('|', (string) $key, 2), 2, '');
                if (! in_array((int) $level, $levels, true) || ! in_array($status, $statuses, true)) {
                    continue;
                }
                $where = ['frais_id' => $feeId, 'session_id' => $sessionId, 'std_mention' => $mention, 'level' => (int) $level, 'std_status' => $status, 'semester' => $semester];
                $raw = is_string($amount) ? trim($amount) : $amount;
                $existing = DB::table('t_frais_perso_montant')->where($where)->first();
                if ($raw === '' || $raw === null) {
                    if ($existing) {
                        DB::table('t_frais_perso_montant')->where('id', $existing->id)->delete();
                        $this->audit->delete('t_frais_perso_montant', 'id', [(array) $existing]);
                        $cleared++;
                    }

                    continue;
                }
                $value = Tariff::amount($raw);
                if ($value === null) {
                    continue;
                }
                if ($existing) {
                    if (abs((float) $existing->montant - $value) > 0.000001) {
                        DB::table('t_frais_perso_montant')->where('id', $existing->id)->update(['montant' => $value]);
                        $this->audit->update('t_frais_perso_montant', 'id', (array) $existing, ['id' => $existing->id, 'montant' => $value] + (array) $existing);
                    }
                } else {
                    $new = $where + ['montant' => $value];
                    $this->audit->insert('t_frais_perso_montant', 'id', ['id' => DB::table('t_frais_perso_montant')->insertGetId($new)] + $new);
                }
                $written++;
            }
        }

        return ['written' => $written, 'cleared' => $cleared];
    }

    // ------------------------------------------------------------------ définitions

    /** Crée un frais, ou réactive celui qui porte déjà ce libellé (deux frais de même nom seraient indistinguables sur la fiche de l'étudiant). @return array{id: int, created: bool, message: string} */
    public function create(User $user, mixed $label, mixed $condition = 'aucune', mixed $semesters = ''): array
    {
        $this->requireInstalled();
        $label = self::label($label);
        $condition = self::condition($condition);
        $semesters = self::validSemesters($semesters);

        $same = DB::table('t_frais_perso')->where('libelle', $label)->first();
        if ($same) {
            if ((int) $same->actif === 0) {
                DB::table('t_frais_perso')->where('id', $same->id)->update(['actif' => 1]);
                $this->audit->update('t_frais_perso', 'id', (array) $same, ['actif' => 1] + (array) $same);
            }

            return ['id' => (int) $same->id, 'created' => false, 'message' => 'Ce frais existe déjà : il est actif. Saisissez son montant dans la ligne, filière par filière.'];
        }
        $row = ['libelle' => $label, 'actif' => 1, 'ordre' => (int) DB::table('t_frais_perso')->max('ordre') + 1, 'condition_frais' => $condition, 'semestres' => $semesters, 'date_entry' => Carbon::now()->format('Y-m-d H:i:s'), 'user_id' => $user->id];
        $id = DB::table('t_frais_perso')->insertGetId($row);
        $this->audit->insert('t_frais_perso', 'id', ['id' => $id] + $row);

        return ['id' => $id, 'created' => true, 'message' => 'Frais ajouté. Saisissez son montant dans la ligne, filière par filière.'];
    }

    private function find(int $id): object
    {
        $this->requireInstalled();
        $row = DB::table('t_frais_perso')->where('id', $id)->first();
        if (! $row) {
            throw new ApiProblem(404, 'not_found.extra_fee', 'Frais introuvable', 'Ce frais n’existe pas.');
        }

        return $row;
    }

    /** Règle à qui et quand un frais s'applique ; ne touche ni aux montants ni à ce qui a déjà été facturé. @return array{changed: bool, message: string} */
    public function configure(int $id, mixed $condition, mixed $semesters): array
    {
        $row = $this->find($id);
        $condition = self::condition($condition);
        $semesters = self::validSemesters($semesters);
        $changed = $row->condition_frais !== $condition || $row->semestres !== $semesters;
        if ($changed) {
            DB::table('t_frais_perso')->where('id', $id)->update(['condition_frais' => $condition, 'semestres' => $semesters]);
            $this->audit->update('t_frais_perso', 'id', (array) $row, ['condition_frais' => $condition, 'semestres' => $semesters] + (array) $row);
        }

        return ['changed' => $changed, 'message' => ($changed ? 'Réglage enregistré : ' : 'Réglage inchangé : ').mb_strtolower(self::CONDITIONS[$condition]).', '.($semesters === '' ? 'tous les semestres' : 'semestre(s) '.$semesters).'. Les inscriptions déjà enregistrées ne changent pas.'];
    }

    /** Renomme un frais ; les lignes déjà facturées gardent l'ancien libellé. @return array{changed: bool, message: string} */
    public function rename(int $id, mixed $label): array
    {
        $row = $this->find($id);
        $label = self::label($label);
        if ($row->libelle === $label) {
            return ['changed' => false, 'message' => 'Nom inchangé.'];
        }
        if (DB::table('t_frais_perso')->where('libelle', $label)->where('id', '<>', $id)->exists()) {
            throw new ApiProblem(422, 'validation.label', 'Nom déjà utilisé', 'Un autre frais porte déjà ce nom.');
        }
        try {
            DB::table('t_frais_perso')->where('id', $id)->update(['libelle' => $label]);
        } catch (QueryException) {
            throw new ApiProblem(422, 'validation.label', 'Nom déjà utilisé', 'Un autre frais porte déjà ce nom.');
        }
        $this->audit->update('t_frais_perso', 'id', (array) $row, ['libelle' => $label] + (array) $row);

        return ['changed' => true, 'message' => 'Frais renommé. Les inscriptions déjà enregistrées gardent l’ancien nom.'];
    }

    /** Active ou désactive un frais : la désactivation ne supprime ni les montants ni l'historique, et se défait. @return array{changed: bool, message: string} */
    public function setActive(int $id, bool $active): array
    {
        $row = $this->find($id);
        $changed = (int) $row->actif !== ($active ? 1 : 0);
        if ($changed) {
            DB::table('t_frais_perso')->where('id', $id)->update(['actif' => $active ? 1 : 0]);
            $this->audit->update('t_frais_perso', 'id', (array) $row, ['actif' => $active ? 1 : 0] + (array) $row);
        }

        return ['changed' => $changed, 'message' => $active ? 'Frais réactivé, avec ses montants.' : 'Frais désactivé : plus facturé aux prochaines inscriptions. Les montants et l’historique sont conservés.'];
    }
}
