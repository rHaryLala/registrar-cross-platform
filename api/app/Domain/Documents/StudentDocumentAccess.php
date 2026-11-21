<?php

namespace App\Domain\Documents;

use App\Domain\Directory\Directory;
use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Qui peut imprimer un document d'un étudiant, et lequel : commun aux documents du personnel (lot 6).
 *
 *  - le droit : superadmin, administration, registrariat et comptabilité (niveaux 1 à 4). Un document d'étudiant porte des montants
 *    ou des notes : le média, le chef de mention, l'enseignant et l'étudiant n'y ont pas accès ;
 *  - il est vérifié AVANT de chercher l'étudiant : un compte sans droit ne peut pas distinguer un étudiant qui existe d'un autre ;
 *  - le périmètre de cycle du compte s'applique (une fiche hors cycle est « introuvable », comme dans les listes) ;
 *  - l'étudiant est désigné par l'identifiant de sa ligne (`tbl_2024_etudiant.id`), jamais par un matricule saisi.
 */
final class StudentDocumentAccess
{
    public static function allows(User $user): bool
    {
        return $user->isActive() && (int) $user->level >= 1 && (int) $user->level <= 4;
    }

    /** La ligne de l'étudiant, après le droit et le périmètre. */
    public function student(User $user, int $id): object
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.documents', 'Accès refusé', 'Les documents d’un étudiant portent des montants ou des notes : ils sont réservés au superadmin, à l’administration, au registrariat et à la comptabilité.');
        }

        $student = DB::table('tbl_2024_etudiant')->where('id', $id)->where('remove', '!=', 1)->first();
        $levels = Directory::levels($user);
        if (! $student || ($levels !== null && ($student->annee_etude === null || $student->annee_etude === '' || ! in_array((int) $student->annee_etude, $levels, true)))) {
            throw new ApiProblem(404, 'document.not_found', 'Fiche introuvable', 'Cet étudiant n’existe pas ou n’est pas dans votre périmètre.');
        }

        return $student;
    }
}
