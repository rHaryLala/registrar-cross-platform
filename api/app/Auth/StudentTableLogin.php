<?php

namespace App\Auth;

use App\Models\User;
use App\Support\Role;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Connexion d'un étudiant avec le mot de passe de SA fiche (tbl_2024_etudiant.password), par matricule ou par e-mail.
 *
 * Pourquoi : la connexion Google peut être indisponible (configuration, panne, compte Google absent) ; l'étudiant doit
 * pouvoir entrer quand même avec le mot de passe que le registrariat lui a remis.
 *
 * Particularités de la base, relevées sur la copie locale le 15/11/2026 :
 *   - les mots de passe de cette table sont stockés EN CLAIR (2 263 fiches actives, aucune empreinte) : on les compare
 *     en temps constant et on ne les modifie JAMAIS (l'ancien code lit ce champ tel quel) ; un format bcrypt serait
 *     aussi accepté le jour où la table sera migrée ;
 *   - 147 adresses e-mail sont partagées : par e-mail, seul le mot de passe désigne l'étudiant ; si plusieurs
 *     étudiants ont à la fois la même adresse ET le même mot de passe, la connexion est refusée (ambiguïté) ;
 *   - un étudiant n'a pas forcément de compte `compt_utilisateur` : comme l'ancien code à la première connexion Google,
 *     on le crée (niveau 8, rôle étudiant, mot de passe aléatoire : seule la fiche fait foi).
 *
 * Garde-fou : ce mot de passe n'ouvre JAMAIS qu'un compte de rôle étudiant. Un compte du personnel rattaché par
 * erreur à un matricule est refusé, sinon le mot de passe d'un étudiant donnerait accès à un compte du personnel.
 */
final class StudentTableLogin
{
    public function __construct(private readonly PasswordVerifier $passwords) {}

    public function enabled(): bool
    {
        return (bool) config('registrar.student_password_login');
    }

    /** @param string|null $studentId restreint la recherche à ce matricule (compte déjà identifié par son pseudo) */
    public function attempt(string $identifier, string $password, ?string $studentId = null): LoginResult
    {
        if (! $this->enabled()) {
            return LoginResult::failed('student_login_disabled');
        }

        $plain = trim($password);
        $rows = $this->candidates($identifier, $studentId);

        if ($rows->isEmpty()) {
            $this->passwords->burn($plain);

            return LoginResult::failed('unknown_account');
        }

        // Tous les candidats sont comparés, sans sortie anticipée : le temps ne révèle pas lequel a répondu.
        $matched = [];
        foreach ($rows as $row) {
            if ($this->matches($plain, (string) $row->password)) {
                $matched[] = $row;
            }
        }

        if ($matched === []) {
            return LoginResult::failed('bad_password');
        }
        // Un mot de passe commun à plusieurs étudiants n'est pas un secret : il ne prouve pas qui est au clavier.
        if ($this->isShared($matched[0])) {
            return LoginResult::failed('shared_password');
        }
        if (count($matched) > 1) {
            return LoginResult::failed('ambiguous_password');
        }

        return $this->account($matched[0]);
    }

    /**
     * Une fiche par étudiant (la plus récente) : un étudiant réinscrit plusieurs années a plusieurs lignes, seule la
     * dernière porte son mot de passe actuel.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function candidates(string $identifier, ?string $studentId)
    {
        $query = DB::table('tbl_2024_etudiant')->where(fn ($q) => $q->whereNull('remove')->orWhere('remove', 0));

        if ($studentId !== null) {
            $query->where('student_id', $studentId);
        } elseif (str_contains($identifier, '@')) {
            $query->whereRaw('LOWER(TRIM(student_email)) = ?', [mb_strtolower($identifier)]);
        } else {
            $query->where('student_id', $identifier);
        }

        return $query->orderByDesc('annee_scolaire')->orderByDesc('id')->limit(50)
            ->get(['id', 'student_id', 'student_nom', 'student_prenom', 'student_email', 'password'])
            ->unique('student_id')
            ->values();
    }

    /** Ce mot de passe de fiche est-il porté par plus d'étudiants que la limite ne le permet ? (bcrypt : jamais partagé, sel unique) */
    private function isShared(object $student): bool
    {
        $limit = (int) config('registrar.student_password_max_sharing');
        $stored = trim((string) $student->password);
        if ($limit <= 0 || str_starts_with($stored, '$2y$')) {
            return false;
        }

        // BINARY : « abc » et « ABC » sont deux mots de passe différents. Un étudiant = un matricule, quel que soit son nombre de lignes.
        $holders = DB::table('tbl_2024_etudiant')
            ->where(fn ($q) => $q->whereNull('remove')->orWhere('remove', 0))
            ->whereRaw('BINARY TRIM(password) = ?', [$stored])
            ->distinct()->count('student_id');

        return $holders > $limit;
    }

    private function matches(string $plain, string $stored): bool
    {
        $stored = trim($stored);
        if ($stored === '' || $plain === '') {
            return false;
        }
        if (str_starts_with($stored, '$2y$')) {
            return password_verify($plain, $stored);
        }

        // Comparaison en temps constant ; espaces de début/fin ignorés des deux côtés (clavier de téléphone).
        return hash_equals($stored, $plain);
    }

    private function account(object $student): LoginResult
    {
        $existing = User::query()->where('student_id', $student->student_id)->orderByDesc('etat')->orderByDesc('id')->first();

        if ($existing !== null) {
            if ($existing->role() !== Role::Student) {
                return LoginResult::failed('account_conflict', $existing);
            }
            if ((int) $existing->etat !== 1) {
                return LoginResult::failed('inactive_account', $existing);
            }

            return LoginResult::success($existing, 'student_table');
        }

        return $this->create($student);
    }

    /** Crée le compte, comme l'ancien code (createStudentUserFromGoogle) : niveau 8, rôle étudiant, mot de passe aléatoire. */
    private function create(object $student): LoginResult
    {
        $matricule = (string) $student->student_id;

        // Aucun index unique sur pseudo : on ne prend jamais le pseudo d'un autre compte.
        if (DB::table('compt_utilisateur')->where('pseudo', $matricule)->exists()) {
            return LoginResult::failed('account_conflict');
        }

        $email = mb_strtolower(trim((string) $student->student_email));
        // Une adresse déjà portée par un autre compte actif, ou partagée par plusieurs fiches (frères et sœurs : 147 cas),
        // resterait ambiguë à la connexion par e-mail : on ne la copie pas. Sans e-mail sur le compte, l'étudiant entre
        // quand même par sa fiche, et Google retrouve sa fiche par l'adresse (étape 5 de l'ancien code).
        if ($email !== '' && (
            DB::table('compt_utilisateur')->whereRaw('LOWER(TRIM(mail)) = ?', [$email])->where('etat', 1)->exists()
            || DB::table('tbl_2024_etudiant')->whereRaw('LOWER(TRIM(student_email)) = ?', [$email])
                ->where(fn ($q) => $q->whereNull('remove')->orWhere('remove', 0))->where('student_id', '<>', $matricule)->exists()
        )) {
            $email = '';
        }

        try {
            $id = DB::table('compt_utilisateur')->insertGetId([
                'pseudo' => $matricule,
                'password' => Hash::make(bin2hex(random_bytes(16))),
                'nom' => (string) $student->student_nom,
                'prenom' => (string) $student->student_prenom,
                'mail' => $email,
                'level' => 8,
                'privilege' => 'student',
                'user_type' => 'student',
                'student_id' => $matricule,
                'etat' => 1,
                'photos' => '',
                'post' => '',
                'theme' => '',
                'update_user' => 0,
                'date_entry' => now()->toDateString(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return LoginResult::failed('account_create_error');
        }

        SecurityLog::record('student_account_created', $id, ['via' => 'student_table']);

        return LoginResult::success(User::query()->findOrFail($id), 'student_table', created: true);
    }
}
