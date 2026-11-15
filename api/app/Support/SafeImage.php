<?php

namespace App\Support;

/**
 * Lecture sûre d'images dont le NOM vient de la base (photos d'étudiants, images d'annonces) : ces valeurs sont saisies
 * (téléversement, import), jamais de confiance aveugle.
 *
 * Le chemin est validé (pas de « .. », pas de chemin absolu ni de lecteur, pas d'antislash, pas d'octet nul), résolu puis
 * CONFINÉ dans le dossier voulu (realpath : un lien symbolique qui sortirait du dossier est refusé) ; le type est lu dans
 * l'en-tête du fichier, pas déduit de l'extension ; taille et nombre de pixels sont plafonnés (une image géante épuiserait
 * la mémoire de GD).
 */
final class SafeImage
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Au-delà, le décodage par GD dépasserait la mémoire d'un processus PHP ordinaire (4 octets par pixel). */
    public const MAX_PIXELS = 12_000_000;

    /** Chemin absolu validé de `$value` dans le dossier `$folder`, ou null si ce n'est pas une image sûre, présente et raisonnable. */
    public static function resolve(string $folder, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0") || str_contains($value, chr(92)) || preg_match('/[\x00-\x1f]/', $value)) {
            return null;
        }
        if (str_starts_with($value, '/') || preg_match('/^[A-Za-z]:/', $value) || ! preg_match('/\.(jpe?g|png|webp|gif)$/i', $value)) {
            return null;
        }
        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        // Chemin vide : realpath('') désignerait le dossier COURANT de l'application. Pas de dossier configuré = pas d'image.
        $folder = trim($folder);
        $base = $folder === '' ? false : realpath($folder);
        if ($base === false) {
            return null;
        }
        $base = str_replace(chr(92), '/', $base);

        $full = realpath($base.'/'.$value);
        if ($full === false) {
            return null;
        }
        $full = str_replace(chr(92), '/', $full);
        if (! str_starts_with($full, rtrim($base, '/').'/') || ! is_file($full)) {
            return null;
        }

        $size = @filesize($full);
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            return null;
        }
        $info = @getimagesize($full);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        return $full;
    }

    /** L'image réduite pour tenir dans `$fitW` × `$fitH` (jamais agrandie), en JPEG redressé selon l'EXIF ; null si illisible. */
    public static function jpeg(string $path, int $fitW, int $fitH): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $raw = @file_get_contents($path);
        $img = $raw === false ? false : @imagecreatefromstring($raw);
        if ($img === false) {
            return null;
        }

        // Les photos de téléphone portent leur sens dans l'EXIF : GD l'ignore, un navigateur non.
        if (function_exists('exif_read_data') && preg_match('/\.jpe?g$/i', $path) === 1) {
            $exif = @exif_read_data($path);
            $angle = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => 180, 6 => -90, 8 => 90, default => 0,
            };
            if ($angle !== 0 && ($rotated = imagerotate($img, $angle, 0)) !== false) {
                $img = $rotated;
            }
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, $fitW / $w, $fitH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // fond blanc pour les PNG transparents
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($out, null, 85);
        $bytes = (string) ob_get_clean();

        return $bytes === '' ? null : $bytes;
    }
}
