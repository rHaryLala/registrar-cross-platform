<?php

namespace App\Domain\Admissions;

/**
 * Messages adressés au candidat pour l'informer de la décision prise sur sa candidature de pré-admission (port de `data/admissions.mail.php`
 * et `data/admissions.sms.php`). Les textes sont ceux de l'ancienne application, mot pour mot.
 *
 * Master : l'acceptation part avec l'adresse universitaire et son mot de passe, à la CRÉATION de l'étudiant (pas à l'acceptation).
 */
final class Messages
{
    /** @return array{email: string, phone: string, address: string} */
    public static function contact(): array
    {
        return config('registrar.admissions.contact');
    }

    /** La candidature vise-t-elle le Master (`candidat.diplome_vise`) ? */
    public static function isMaster(array $candidate): bool
    {
        return strtolower(trim((string) ($candidate['diplome_vise'] ?? ''))) === 'master';
    }

    private static function mention(array $c): string
    {
        return trim((string) ($c['mention_libelle'] ?? $c['mention'] ?? ''));
    }

    private static function h(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return array{subject: string, html: string, text: string}|null null : cette décision ne donne pas lieu à notification
     */
    public static function email(array $c, string $decision): ?array
    {
        $name = trim((string) ($c['nom_complet'] ?? ''));
        $mention = self::mention($c);
        $contact = self::contact();
        $nameH = self::h($name);
        $mentionH = self::h($mention);

        if ($decision === 'accepte') {
            $subject = 'Votre candidature a été acceptée — Université Adventiste Zurcher';
            $title = 'Votre candidature a été acceptée';
            $text = "Bonjour {$name},\n\n"
                .'Nous avons le plaisir de vous informer que votre demande de pré-inscription'
                .($mention !== '' ? " en {$mention}" : '')." a été acceptée.\n\n"
                ."Pour finaliser votre inscription, veuillez vous présenter au bureau du registrariat "
                ."avec vos pièces justificatives (diplôme, relevés de notes, pièce d'identité, photos d'identité).\n\n"
                ."Bureau du registrariat\n{$contact['email']}\n{$contact['phone']}\n{$contact['address']}\n\n"
                ."Nous nous réjouissons de vous accueillir.\n\nUniversité Adventiste Zurcher";
            $body = '<p>Bonjour <strong>'.$nameH.'</strong>,</p>'
                .'<p>Nous avons le plaisir de vous informer que votre demande de pré-inscription'
                .($mentionH !== '' ? ' en <strong>'.$mentionH.'</strong>' : '').' a été <strong>acceptée</strong>.</p>'
                .'<p>Pour finaliser votre inscription, veuillez vous présenter au bureau du registrariat '
                ."avec vos pièces justificatives : diplôme, relevés de notes, pièce d'identité et photos d'identité.</p>";
        } elseif ($decision === 'refuse') {
            $subject = 'Suite donnée à votre candidature — Université Adventiste Zurcher';
            $title = 'Suite donnée à votre candidature';
            $text = "Bonjour {$name},\n\n"
                ."Nous vous remercions de l'intérêt que vous portez à l'Université Adventiste Zurcher "
                .'et du temps consacré à votre demande de pré-inscription'
                .($mention !== '' ? " en {$mention}" : '').".\n\n"
                ."Après examen de votre dossier, nous ne sommes pas en mesure d'y donner une suite favorable.\n\n"
                .'Cette décision ne remet pas en cause vos qualités ; vous pouvez présenter une nouvelle '
                ."demande lors d'une prochaine session. Le bureau du registrariat reste à votre disposition "
                ."pour tout renseignement.\n\n"
                ."Bureau du registrariat\n{$contact['email']}\n{$contact['phone']}\n\n"
                ."Nous vous souhaitons pleine réussite dans vos projets.\n\nUniversité Adventiste Zurcher";
            $body = '<p>Bonjour <strong>'.$nameH.'</strong>,</p>'
                ."<p>Nous vous remercions de l'intérêt que vous portez à l'Université Adventiste Zurcher "
                .'et du temps consacré à votre demande de pré-inscription'
                .($mentionH !== '' ? ' en <strong>'.$mentionH.'</strong>' : '').'.</p>'
                ."<p>Après examen de votre dossier, nous ne sommes pas en mesure d'y donner une suite favorable.</p>"
                .'<p>Cette décision ne remet pas en cause vos qualités : vous pouvez présenter une nouvelle '
                ."demande lors d'une prochaine session. Le bureau du registrariat reste à votre disposition "
                .'pour tout renseignement.</p>';
        } else {
            return null;
        }

        return ['subject' => $subject, 'html' => self::envelope($title, $body), 'text' => $text];
    }

    /** Habillage commun des messages au candidat : bandeau, corps, coordonnées du registrariat. */
    public static function envelope(string $title, string $bodyHtml): string
    {
        $contact = self::contact();

        return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>'
            .'<body style="margin:0;padding:0;background:#f4f5f7;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">'
            .'<tr><td align="center">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            .'style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
            .'<tr><td style="background:#1a365d;padding:22px 28px;color:#ffffff;">'
            .'<div style="font-size:13px;letter-spacing:.06em;text-transform:uppercase;opacity:.85;">Université Adventiste Zurcher</div>'
            .'<div style="font-size:19px;font-weight:bold;margin-top:6px;">'.self::h($title).'</div>'
            .'</td></tr>'
            .'<tr><td style="padding:26px 28px;font-size:15px;line-height:1.65;">'.$bodyHtml.'</td></tr>'
            .'<tr><td style="padding:0 28px 24px;font-size:13px;line-height:1.6;color:#4b5563;">'
            .'<div style="border-top:1px solid #e5e7eb;padding-top:16px;">'
            .'<strong>Bureau du registrariat</strong><br>'
            .self::h($contact['email']).'<br>'.self::h($contact['phone']).'<br>'.self::h($contact['address'])
            .'</div></td></tr>'
            .'<tr><td style="background:#f9fafb;padding:14px 28px;font-size:11px;color:#9ca3af;">'
            .'Message automatique — merci de ne pas y répondre directement.'
            .'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    /**
     * Le SMS de la décision : un seul SMS (160 caractères en alphabet GSM), sans accents. Une mention trop longue est retirée plutôt que de couper le texte.
     */
    public static function sms(array $c, string $decision): ?string
    {
        $mention = self::mention($c);
        $inMention = $mention !== '' ? ' en '.$mention : '';
        $phone = 'Tel +261 34 38 180 81';

        if ($decision === 'accepte') {
            $text = 'UAZ : votre candidature'.$inMention.' est acceptee. Presentez-vous au registrariat avec vos pieces justificatives. '.$phone;
        } elseif ($decision === 'refuse') {
            $text = 'UAZ : apres examen, votre candidature'.$inMention." n'a pas ete retenue. Vous pouvez postuler a une prochaine session. ".$phone;
        } else {
            return null;
        }
        $text = Sms::simplify($text);

        if (strlen($text) > 160 && $mention !== '') {
            return self::sms(['mention_libelle' => '', 'mention' => ''], $decision);
        }

        return $text;
    }

    /**
     * Master : l'acceptation avec l'adresse universitaire et le mot de passe.
     *
     * @param  bool|null  $accountCreated  le compte Google vient d'être créé (true), n'a pas pu l'être (false), ou l'API n'est pas branchée (null)
     * @return array{subject: string, html: string, text: string}
     */
    public static function identifiers(array $c, string $address, string $password, ?bool $accountCreated = null, ?string $today = null): array
    {
        $name = trim((string) ($c['nom_complet'] ?? ''));
        $mention = self::mention($c);
        $contact = self::contact();
        $h = fn ($v) => self::h((string) $v);

        if ($accountCreated === true) {
            $whenText = 'Cette adresse est active dès maintenant : connectez-vous sur https://mail.google.com avec cette adresse et ce mot de passe.';
            $whenHtml = 'Cette adresse est <strong>active dès maintenant</strong> : connectez-vous sur <a href="https://mail.google.com" style="color:#4658c2;">mail.google.com</a> avec cette adresse et ce mot de passe.';
        } elseif ($accountCreated === false) {
            $whenText = 'Cette adresse sera activée sous peu.';
            $whenHtml = 'Cette adresse sera <strong>activée sous peu</strong>.';
        } else {
            $active = ($today ?? \Illuminate\Support\Carbon::now()->format('Y-m-d')) >= '2026-10-11';
            $whenText = $active ? 'Cette adresse est active.' : 'Cette adresse sera active à partir du dimanche 11 octobre 2026.';
            $whenHtml = $active ? 'Cette adresse est <strong>active</strong>.' : 'Cette adresse sera active à partir du <strong>dimanche 11 octobre 2026</strong>.';
        }

        $text = "Bonjour {$name},\n\n"
            .'Nous avons le plaisir de vous informer que votre candidature en Master'
            .($mention !== '' ? ", mention {$mention}," : '')." a été acceptée. Bienvenue à l'Université Adventiste Zurcher.\n\n"
            ."Votre adresse électronique universitaire a été créée :\n  Adresse : {$address}\n  Mot de passe : {$password}\n\n"
            .$whenText." C'est par elle que l'université communiquera avec vous.\n\n"
            ."Conservez ce message et ne communiquez votre mot de passe à personne.\n\n"
            ."Le bureau du registrariat reste à votre disposition pour toute question et pour la suite de votre inscription.\n\n"
            ."Bureau du registrariat\n{$contact['email']}\n{$contact['phone']}\n{$contact['address']}\n\nUniversité Adventiste Zurcher";

        $body = '<p>Bonjour <strong>'.$h($name).'</strong>,</p>'
            .'<p>Nous avons le plaisir de vous informer que votre candidature en <strong>Master</strong>'
            .($mention !== '' ? ', mention <strong>'.$h($mention).'</strong>,' : '')." a été <strong>acceptée</strong>. Bienvenue à l'Université Adventiste Zurcher.</p>"
            .'<p>Votre adresse électronique universitaire a été créée :</p>'
            .'<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:4px 0 16px;background:#f3f6fb;border:1px solid #dbe3ef;border-radius:10px;">'
            .'<tr><td style="padding:14px 18px;font-size:15px;line-height:1.9;">'
            .'Adresse : <strong style="font-family:Consolas,Menlo,monospace;">'.$h($address).'</strong><br>'
            .'Mot de passe : <strong style="font-family:Consolas,Menlo,monospace;">'.$h($password).'</strong>'
            .'</td></tr></table>'
            .'<p>'.$whenHtml." C'est par elle que l'université communiquera avec vous.</p>"
            .'<p>Conservez ce message et ne communiquez votre mot de passe à personne.</p>'
            ."<p>Le bureau du registrariat reste à votre disposition pour toute question et pour la suite de votre inscription.</p>";

        return [
            'subject' => 'Votre candidature en Master a été acceptée — Université Adventiste Zurcher',
            'html' => self::envelope('Votre candidature a été acceptée', $body),
            'text' => $text,
        ];
    }
}
