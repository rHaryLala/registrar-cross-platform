<?php

namespace App\Support;

/**
 * Une image du dossier `file/` de l'ancienne application (logo, fond de badge), lue en lecture seule et fournie en URI de données.
 *
 * Le nom est fixé par le code, jamais par la requête ; il passe tout de même par SafeImage (confinement, type lu dans le fichier, taille et
 * pixels plafonnés). Une image absente ou illisible n'est jamais une erreur : le document s'imprime sans elle.
 * Une image PNG garde sa transparence ; les autres sont réduites en JPEG.
 */
final class LegacyAsset
{
    public static function dataUri(string $file, int $fitW, int $fitH): ?string
    {
        $path = SafeImage::resolve((string) config('registrar.legacy_files_path', ''), $file);
        if ($path === null) {
            return null;
        }
        $info = @getimagesize($path);
        if ($info !== false && $info[2] === IMAGETYPE_PNG) {
            $png = self::png($path, $fitW, $fitH);

            return $png === null ? null : 'data:image/png;base64,'.base64_encode($png);
        }
        $jpeg = SafeImage::jpeg($path, $fitW, $fitH);

        return $jpeg === null ? null : 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    private static function png(string $path, int $fitW, int $fitH): ?string
    {
        if (! function_exists('imagecreatefrompng')) {
            return null;
        }
        $img = @imagecreatefrompng($path);
        if ($img === false) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, $fitW / $w, $fitH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($out, null, 6);

        return (string) ob_get_clean();
    }
}
