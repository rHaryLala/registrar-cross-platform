<?php

namespace App\Domain\Teacher;

use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Facades\DB;

/**
 * Qui a le droit d'ouvrir l'espace enseignant, et quels cours sont les siens.
 *
 * Porté de data/middleware.php (aEspaceEnseignant, getTeacherUid) et data/remise.notes.php (remiseEnseignantPossedeCours).
 *
 * Règles :
 *  - l'espace s'ouvre aux ENSEIGNANTS et CHEFS DE MENTION (leurs cours), et aux niveaux 1 à 3 (superadmin, admin, registrariat :
 *    ils consultent ou corrigent n'importe quel cours) ;
 *  - un enseignant n'a que les cours dont il est le titulaire (`t_2023_cours.id_teacher` = son `uid`). Les identifiants de cours
 *    sont séquentiels et se devinent dans une adresse : la propriété est donc VÉRIFIÉE À CHAQUE ÉCRITURE, jamais présumée ;
 *  - le lien compte → fiche enseignant est `teacher_uid`. À défaut, repli par le nom (dans les deux ordres) puis par l'e-mail.
 *    L'ancien code prenait la PREMIÈRE fiche trouvée ; ici le repli n'est accepté que s'il désigne UNE SEULE fiche
 *    (deux homonymes ne doivent jamais se partager les cours de l'un).
 */
final class TeacherAccess
{
    /** Niveaux 1 à 3 : voient et corrigent tous les cours. */
    public function isStaff(User $user): bool
    {
        return in_array($user->role(), [Role::Superadmin, Role::Admin, Role::Registrar], true);
    }

    public function canEnter(User $user): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $this->isStaff($user) || in_array($user->role(), [Role::Teacher, Role::ChefMention], true);
    }

    /**
     * Ce compte a-t-il un espace enseignant ? Les enseignants et chefs de mention : toujours (un compte non relié voit un message qui l'explique).
     * Le personnel (niveaux 1 à 3) seulement s'il EST aussi enseignant, c'est-à-dire si sa fiche est retrouvée : il enseigne alors ses cours.
     */
    public function hasSpace(User $user): bool
    {
        if (! $this->canEnter($user)) {
            return false;
        }

        return ! $this->isStaff($user) || $this->teacherUid($user) !== null;
    }

    /** uid de la fiche `teacher` du compte, ou null (compte non relié, ou repli ambigu). */
    public function teacherUid(User $user): ?int
    {
        if (! $this->canEnter($user)) {
            return null;
        }
        if (! empty($user->teacher_uid)) {
            return (int) $user->teacher_uid;
        }

        $nom = trim((string) $user->nom);
        $prenom = trim((string) $user->prenom);
        if ($nom !== '' && $prenom !== '') {
            $rows = DB::table('teacher')->where('remove', 0)
                ->where(fn ($q) => $q
                    ->where(fn ($a) => $a->whereRaw('TRIM(lastName) = ?', [$nom])->whereRaw('TRIM(name) = ?', [$prenom]))
                    ->orWhere(fn ($b) => $b->whereRaw('TRIM(name) = ?', [$nom])->whereRaw('TRIM(lastName) = ?', [$prenom])))
                ->limit(2)->pluck('uid');
            if ($rows->count() === 1) {
                return (int) $rows->first();
            }
            if ($rows->count() > 1) {
                return null;   // homonymes : on ne devine pas
            }
        }

        $mail = mb_strtolower(trim((string) $user->mail));
        if ($mail !== '') {
            $rows = DB::table('teacher')->where('remove', 0)->whereRaw('LOWER(TRIM(email)) = ?', [$mail])->limit(2)->pluck('uid');
            if ($rows->count() === 1) {
                return (int) $rows->first();
            }
        }

        return null;
    }

    /** Ce cours est-il l'un des siens ? (Le personnel de niveau 1 à 3 passe pour corriger.) */
    public function ownsCourse(User $user, int $courseId): bool
    {
        if (! $this->canEnter($user)) {
            return false;
        }
        if ($this->isStaff($user)) {
            return DB::table('t_2023_cours')->where('id', $courseId)->exists();
        }
        $uid = $this->teacherUid($user);

        return $uid !== null
            && DB::table('t_2023_cours')->where('id', $courseId)->where('id_teacher', $uid)->where('remove', '!=', 1)->exists();
    }
}
