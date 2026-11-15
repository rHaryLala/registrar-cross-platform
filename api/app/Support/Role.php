<?php

namespace App\Support;

/**
 * Les neuf rôles de l'application.
 *
 * Valeurs identiques à l'enum `compt_utilisateur.user_type` ; `level` (1 à 9)
 * est la hiérarchie historique : plus il est petit, plus les droits sont larges.
 */
enum Role: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Registrar = 'registrar';
    case Comptabilite = 'comptabilite';
    case Media = 'media';
    case ChefMention = 'chef_mention';
    case Teacher = 'teacher';
    case Student = 'student';
    case WorkEducation = 'work_education';

    public static function fromLevel(int $level): ?self
    {
        return match ($level) {
            1 => self::Superadmin,
            2 => self::Admin,
            3 => self::Registrar,
            4 => self::Comptabilite,
            5 => self::Media,
            6 => self::ChefMention,
            7 => self::Teacher,
            8 => self::Student,
            9 => self::WorkEducation,
            default => null,
        };
    }

    /** Niveaux 1 à 4 : droits étendus (notes, finances, comptes). */
    public function isPrivileged(): bool
    {
        return in_array($this, [self::Superadmin, self::Admin, self::Registrar, self::Comptabilite], true);
    }
}
