<?php

namespace App\Domain\Documents;

use App\Domain\Directory\Directory;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Facades\DB;

/**
 * Qui peut imprimer un document d'un étudiant, et lequel : commun aux documents du personnel (lot 6).
 *
 * Deux familles de documents, deux droits :
 *  - les documents FINANCIERS ou de NOTES (fiche d'inscription, relevé) : superadmin, administration, registrariat et comptabilité
 *    (niveaux 1 à 4) ; le média, le chef de mention, l'enseignant et l'étudiant n'y ont pas accès ;
 *  - les documents d'IDENTITÉ (certificat de scolarité, badge) : niveaux 1 à 3, et le chef de mention (l'ancienne application les
 *    lui laissait imprimer, à raison de 170 appels en deux mois), mais pour les étudiants de SA mention seulement.
 *
 * Dans les deux cas : le droit est vérifié AVANT de chercher l'étudiant (un compte sans droit ne peut pas distinguer un étudiant qui
 * existe d'un autre), le périmètre de cycle du compte s'applique (une fiche hors cycle est « introuvable », comme dans les listes) et
 * l'étudiant est désigné par l'identifiant de sa ligne (`tbl_2024_etudiant.id`), jamais par un matricule saisi.
 */
final class StudentDocumentAccess
{
    /** Documents financiers ou de notes. */
    public static function allows(User $user): bool
    {
        return $user->isActive() && (int) $user->level >= 1 && (int) $user->level <= 4;
    }

    /** Documents d'identité (certificat, badge). */
    public static function allowsIdentity(User $user): bool
    {
        return $user->isActive() && ((int) $user->level >= 1 && (int) $user->level <= 3 || $user->role() === Role::ChefMention);
    }

    /** La ligne de l'étudiant pour un document financier ou de notes. */
    public function student(User $user, int $id): object
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.documents', 'Accès refusé', 'Les documents d’un étudiant portent des montants ou des notes : ils sont réservés au superadmin, à l’administration, au registrariat et à la comptabilité.');
        }

        return $this->find($user, $id);
    }

    /** La ligne de l'étudiant pour un document d'identité. */
    public function identityStudent(User $user, int $id): object
    {
        if (! self::allowsIdentity($user)) {
            throw new ApiProblem(403, 'forbidden.documents', 'Accès refusé', 'Le certificat de scolarité et le badge sont réservés au superadmin, à l’administration, au registrariat et aux chefs de mention.');
        }

        return $this->find($user, $id, true);
    }

    private function find(User $user, int $id, bool $mentionScope = false): object
    {
        $student = DB::table('tbl_2024_etudiant')->where('id', $id)->where('remove', '!=', 1)->first();
        $levels = Directory::levels($user);
        $mention = $mentionScope ? Directory::mentionScope($user) : false;
        if (! $student
            || ($levels !== null && ($student->annee_etude === null || $student->annee_etude === '' || ! in_array((int) $student->annee_etude, $levels, true)))
            // Un chef de mention sans mention attribuée ne voit rien ; sinon, seulement sa mention.
            || ($mention === null || ($mention !== false && (string) $student->etude_envisage !== $mention))) {
            throw new ApiProblem(404, 'document.not_found', 'Fiche introuvable', 'Cet étudiant n’existe pas ou n’est pas dans votre périmètre.');
        }

        return $student;
    }
}
