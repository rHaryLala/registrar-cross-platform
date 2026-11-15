<?php

namespace App\Domain\Student;

use App\Support\SafeImage;
use Illuminate\Support\Facades\DB;

/**
 * Photo d'un étudiant (tbl_2024_etudiant.image_student), lue dans le dossier de l'ancienne application, en lecture seule.
 *
 * `image_student` est une donnée SAISIE (téléversement, import) : jamais une confiance aveugle. Le chemin est validé
 * (pas de « .. », pas de chemin absolu ni de lecteur, pas d'antislash), résolu puis CONFINÉ dans le dossier des photos
 * (realpath : un lien symbolique qui sortirait du dossier est refusé) ; le type est lu dans l'en-tête du fichier, pas
 * déduit de l'extension ; taille et nombre de pixels sont plafonnés (une image géante épuiserait la mémoire de GD).
 *
 * Relevé du 15/11/2026 : 2 263 fiches actives, 1 136 sans photo, 790 noms simples + 125 chemins avec sous-dossier
 * valides, 111 valeurs sans extension (inutilisables), 101 fichiers manquants.
 */
final class StudentPhoto
{
    /** Cadre d'une photo d'identité 35 × 45 mm à 300 dpi : on ne garde pas plus de pixels que le papier n'en montre. */
    public const FIT_WIDTH = 420;

    public const FIT_HEIGHT = 540;

    /** Chemin absolu validé de la photo la plus récente utilisable de l'étudiant, ou null. */
    public function path(string $studentId): ?string
    {
        $values = DB::table('tbl_2024_etudiant')->where('student_id', $studentId)
            ->where(fn ($q) => $q->whereNull('remove')->orWhere('remove', 0))
            ->orderByDesc('annee_scolaire')->orderByDesc('id')->limit(10)->pluck('image_student');

        foreach ($values as $value) {
            $path = $this->resolve((string) $value);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /** Chemin absolu validé d'une valeur `image_student`, ou null si elle n'est pas une image sûre, présente et raisonnable. */
    public function resolve(string $value): ?string
    {
        return SafeImage::resolve((string) config('registrar.student_photos_path'), $value);
    }

    public function exists(string $studentId): bool
    {
        return $this->path($studentId) !== null;
    }

    /** La photo réduite (JPEG, redressée selon l'EXIF), ou null si elle est absente ou illisible. */
    public function jpeg(string $studentId): ?string
    {
        $path = $this->path($studentId);

        return $path === null ? null : $this->shrink($path);
    }

    public function dataUri(string $studentId): ?string
    {
        $jpeg = $this->jpeg($studentId);

        return $jpeg === null ? null : 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    private function shrink(string $path): ?string
    {
        return SafeImage::jpeg($path, self::FIT_WIDTH, self::FIT_HEIGHT);
    }
}
