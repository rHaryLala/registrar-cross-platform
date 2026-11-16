<?php

namespace App\Support;

use App\Domain\Admissions\Applications;
use App\Domain\Attendance\AttendanceReview;
use App\Domain\Dashboard\StaffDashboard;
use App\Domain\Directory\Directory;
use App\Domain\Finance\Balances;
use App\Domain\Finance\Deadlines;
use App\Domain\Finance\Reenrolment;
use App\Domain\Students\StudentCreation;
use App\Domain\Teacher\TeacherAccess;
use App\Models\User;

/** Forme publique d'un compte dans l'API : jamais d'empreinte de mot de passe ni de colonne interne. */
final class UserPayload
{
    /** @return array<string, mixed> */
    public static function from(User $user): array
    {
        $first = trim((string) $user->prenom);
        $last = trim((string) $user->nom);

        return [
            'id' => (int) $user->id,
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => trim($first.' '.$last) ?: (string) $user->pseudo,
            'email' => $user->mail ?: null,
            'role' => $user->role()?->value,
            'level' => (int) $user->level,
            'student_id' => $user->student_id ?: null,
            // Espace enseignant : les enseignants et chefs de mention, et le personnel (niveaux 1 à 3) dont la fiche enseignant est retrouvée.
            'teacher_space' => app(TeacherAccess::class)->hasSpace($user),
            // Validation des remises de notes : le registrariat (niveaux 1 à 3).
            'review_space' => $user->isActive() && app(TeacherAccess::class)->isStaff($user),
            // Assiduité du registrariat (signalements > 15 %, retards des enseignants) : superadmin et registrariat seulement, comme l'ancienne page.
            'attendance_space' => AttendanceReview::allows($user),
            // Listes du registrariat que ce compte peut ouvrir : étudiants (tout le personnel), cours et enseignants (superadmin, registrariat).
            // Tableau de bord du personnel (à traiter, chiffres clés).
            // Candidatures de pré-admission : superadmin et registrariat.
            'applications' => Applications::allows($user),
            // Créer un étudiant : niveaux 1 à 3.
            'student_creation' => StudentCreation::allows($user),
            // Finance : autorisation de réinscription (niveaux 1, 3 et 4), avec ce que le compte peut y écrire.
            'finance' => self::finance($user),
            'dashboard' => StaffDashboard::allows($user),
            'directory' => array_values(array_filter(Directory::LISTS, fn ($l) => Directory::allows($user, $l))),
        ];
    }

    /** Pages de finance que ce compte peut ouvrir (autorisation de réinscription avec ses droits d'écriture ; solde de compte) ; null : aucune. @return array<string, mixed>|null */
    private static function finance(User $user): ?array
    {
        $finance = [];
        if (Reenrolment::allows($user)) {
            $finance['reenrolment'] = Reenrolment::rights($user);
        }
        if (Balances::allows($user)) {
            $finance['balances'] = true;
        }
        if (Deadlines::allows($user)) {
            $finance['deadlines'] = true;
        }

        return $finance === [] ? null : $finance;
    }
}
