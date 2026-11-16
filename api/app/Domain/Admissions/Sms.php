<?php

namespace App\Domain\Admissions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Envoi de SMS (port de `data/sms.php`). Le fournisseur est choisi par configuration (`registrar.sms.provider`) :
 *  - « journal » (par défaut) : n'envoie RIEN, consigne le message dans storage/logs/sms.log ;
 *  - « twilio » : API HTTP de Twilio.
 * Un mauvais réglage ne doit jamais entraîner d'envoi involontaire : sans configuration explicite, rien ne part.
 */
final class Sms
{
    /**
     * Met un numéro malgache au format international (+261 3X XX XXX XX). « 0325855186 », « 032 58 551 86 », « +261325855186 » et « 261325855186 »
     * sont acceptés ; un mobile malgache tient en 9 chiffres et commence par 3.
     */
    public static function normalize(?string $number): ?string
    {
        $raw = preg_replace('/[^0-9+]/', '', (string) $number);
        if ($raw === '' || $raw === null) {
            return null;
        }
        $digits = ltrim($raw, '+');
        if (str_starts_with($digits, '00261')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '261')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^3[0-9]{8}$/', $digits) ? '+261'.$digits : null;
    }

    /** Remplace les caractères accentués par leur équivalent simple : hors alphabet GSM, un SMS bascule en 70 caractères et se fractionne. */
    public static function simplify(string $text): string
    {
        return strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '—' => '-', '–' => '-', '…' => '...',
        ]);
    }

    /** @return array{ok: bool, message: string} */
    public static function send(?string $number, string $text): array
    {
        if (! config('registrar.sms.enabled')) {
            return ['ok' => false, 'message' => 'Envoi SMS désactivé par la configuration.'];
        }
        $to = self::normalize($number);
        if ($to === null) {
            return ['ok' => false, 'message' => 'Numéro de téléphone inexploitable.'];
        }
        $text = self::simplify($text);

        return match (config('registrar.sms.provider')) {
            'twilio' => self::twilio($to, $text),
            default => self::journal($to, $text),
        };
    }

    /** N'envoie rien : consigne le message (mode par défaut). */
    private static function journal(string $to, string $text): array
    {
        $line = sprintf("[%s] %s (%d caractères, %d SMS) : %s\n", date('Y-m-d H:i:s'), $to, strlen($text), (int) ceil(max(1, strlen($text)) / 160), $text);
        $path = (string) (config('registrar.sms.log_path') ?: storage_path('logs/sms.log'));
        File::ensureDirectoryExists(dirname($path));

        return @file_put_contents($path, $line, FILE_APPEND) !== false
            ? ['ok' => true, 'message' => 'Consigné dans storage/logs/sms.log (aucun envoi réel).']
            : ['ok' => false, 'message' => 'Impossible d’écrire dans storage/logs/sms.log.'];
    }

    private static function twilio(string $to, string $text): array
    {
        $sid = (string) config('registrar.sms.twilio_sid');
        $token = (string) config('registrar.sms.twilio_token');
        $from = (string) config('registrar.sms.twilio_from');
        if ($sid === '' || $token === '' || $from === '') {
            return ['ok' => false, 'message' => 'Twilio n’est pas configuré (TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM).'];
        }
        try {
            $r = Http::asForm()->withBasicAuth($sid, $token)->timeout(15)->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", ['To' => $to, 'From' => $from, 'Body' => $text]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Fournisseur injoignable.'];
        }

        return $r->successful()
            ? ['ok' => true, 'message' => 'Envoyé.']
            : ['ok' => false, 'message' => 'Refusé par le fournisseur (HTTP '.$r->status().').'];
    }
}
