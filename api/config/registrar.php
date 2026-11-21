<?php

return [

    /*
     | Sel de l'ancien format de mot de passe (sha256(mot de passe . sel)).
     | Valeur historique de l'ancien code ; n'est lue que pour convertir en bcrypt
     | les derniers comptes concernés (voir App\Auth\PasswordVerifier).
     */
    'legacy_password_salt' => env('LEGACY_PASSWORD_SALT', 'fixing_password'),

    /*
     | Identifiant client OAuth Google (public). Le jeton ID doit lui être destiné.
     */
    'google_client_id' => env('GOOGLE_CLIENT_ID', ''),

    /*
     | Un 0/20 compte-t-il dans la moyenne ? DÉCISION DU REGISTRAIRE ATTENDUE.
     | Faux = ce que la page « Mes notes » actuelle affiche (le 0 s'affiche mais n'entre pas dans la moyenne).
     | data/notes.statut.php dit au contraire qu'il compte : l'ancien code se contredit (8 notes, 7 étudiants au 12/11/2026).
     */
    'zero_counts_in_average' => (bool) env('REGISTRAR_ZERO_COUNTS', false),

    /*
     | Un étudiant peut-il entrer avec le mot de passe de sa fiche (tbl_2024_etudiant.password), par matricule ou e-mail ?
     | Utile quand la connexion Google est indisponible. INTERRUPTEUR : REGISTRAR_STUDENT_PASSWORD_LOGIN=false le coupe
     | immédiatement, sans toucher au code, si ces mots de passe (stockés en clair, de forme prévisible) étaient attaqués.
     */
    'student_password_login' => (bool) env('REGISTRAR_STUDENT_PASSWORD_LOGIN', true),

    /*
     | Combien d'étudiants peuvent porter LE MÊME mot de passe de fiche avant qu'il cesse d'ouvrir un compte ?
     | Relevé du 15/11/2026 : 2 263 fiches actives, 672 mots de passe distincts, et UN mot de passe commun à 1 584 étudiants
     | (le mot de passe initial). Avec le matricule (qui n'a rien de secret), ce mot de passe ouvrirait 70 % des comptes
     | à quiconque le connaît : il est donc REFUSÉ par défaut. 1 = un mot de passe utilisé par 2 étudiants ou plus est refusé ;
     | 0 = aucune limite (à ne choisir qu'en connaissance de cause). Les étudiants concernés entrent par Google, ou reçoivent
     | un mot de passe personnel du registrariat.
     */
    'student_password_max_sharing' => (int) env('REGISTRAR_STUDENT_PASSWORD_MAX_SHARING', 1),

    /*
     | Dossier des photos d'étudiants (tbl_2024_etudiant.image_student) : celui de l'ancienne application, en lecture seule.
     | En production, pointer vers le dossier app/photosetudiants du dépôt de l'ancienne application.
     */
    'student_photos_path' => env('STUDENT_PHOTOS_PATH') ?: base_path('../../registrar/app/photosetudiants'),

    /*
     | Bas des documents officiels (relevé de notes...) : lieu, nom de la registraire et image de sa signature.
     | Réglages du serveur, jamais dans le code : REGISTRAR_NAME, REGISTRAR_PLACE, REGISTRAR_SIGNATURE_FILE (chemin absolu d'une image).
     | Sans fichier de signature valide, le document porte une ligne de signature vide.
     */
    'place' => env('REGISTRAR_PLACE', 'Sambaina'),
    'registrar_name' => env('REGISTRAR_NAME', ''),
    'signature_file' => env('REGISTRAR_SIGNATURE_FILE') ?: null,

    /*
     | Dossier `file/` de l'ancienne application, en lecture seule : logo, fonds de badge. Les documents officiels y lisent leurs images ;
     | un fichier absent n'est jamais une erreur (le document s'imprime sans l'image). En production, pointer vers le dossier file/ de l'ancienne application.
     */
    'legacy_files_path' => env('LEGACY_FILES_PATH') ?: base_path('../../registrar/file'),

    /*
     | Dossier des images d'annonces (t_annonces.image) : celui de l'ancienne application, en lecture seule.
     | En production, pointer vers le dossier app/uploads/annonces du dépôt de l'ancienne application.
     */
    'announcement_images_path' => env('ANNOUNCEMENT_IMAGES_PATH') ?: base_path('../../registrar/app/uploads/annonces'),

    /*
     | Synchronisation des actualités avec le site public de l'université (php artisan news:sync).
     | `base` VERROUILLE l'hôte : rien d'autre n'est jamais téléchargé. Les adresses d'images du contenu sont acceptées
     | seulement si elles sont en https sur ce même hôte.
     */
    'site_news' => [
        'base' => env('SITE_NEWS_BASE', 'https://zurcher.edu.mg'),
        'timeout' => (int) env('SITE_NEWS_TIMEOUT', 15),
        'delay_ms' => (int) env('SITE_NEWS_DELAY_MS', 400),            // une requête toutes les 0,4 s : on ne martèle pas le site
        'max_bytes' => 3 * 1024 * 1024,
        'user_agent' => 'RegistrarSync/1.0 (synchronisation des actualites de l\'universite)',
    ],

    /*
     | Notifications aux candidats (courriel et SMS) : envoyées par la commande planifiée applications:notify.
     | SMS_PROVIDER : « journal » (par défaut : n'envoie RIEN, écrit le message dans storage/logs/sms.log, comme l'ancienne application) ou « twilio ».
     | Un mauvais réglage ne doit jamais envoyer de SMS involontaire : sans configuration explicite, rien ne part.
     */
    'sms' => [
        'enabled' => (bool) env('SMS_ENABLED', true),
        'provider' => env('SMS_PROVIDER', 'journal'),
        'log_path' => env('SMS_LOG_PATH', ''),       // journal des SMS (vide : storage/logs/sms.log)
        'twilio_sid' => env('TWILIO_SID', ''),
        'twilio_token' => env('TWILIO_TOKEN', ''),     // secret : jamais dans le dépôt
        'twilio_from' => env('TWILIO_FROM', ''),
    ],
    /*
     | Comptes Google Workspace des étudiants (API Google Admin, Directory API) : un compte de service signe son jeton (JWT RS256) et agit au nom d'un
     | administrateur du domaine (délégation à l'échelle du domaine). SANS la clé (GOOGLE_ADMIN_KEY vide ou illisible), aucun compte n'est créé :
     | la fiche de l'étudiant dit « compte Google non créé : l'API n'est pas configurée ». La clé n'est JAMAIS dans le dépôt.
     */
    'google_admin' => [
        'key_path' => env('GOOGLE_ADMIN_KEY', ''),
        'subject' => env('GOOGLE_ADMIN_SUBJECT', 'it@zurcher.edu.mg'),
        'domain' => env('GOOGLE_ADMIN_DOMAIN', 'zurcher.edu.mg'),
        'timeout' => 25,
        'max_attempts' => 3,
        'retry_minutes' => [1, 5, 30],
    ],
    'admissions' => [
        'contact' => ['email' => 'registrar@zurcher.edu.mg', 'phone' => '+261 34 38 180 81', 'address' => 'Antsampanimahazo, Antsirabe, Madagascar'],
        'max_attempts' => 3,
        'retry_minutes' => [1, 5, 30],     // attente avant le 2e, le 3e... essai
    ],

    /*
     | Durée de la session (minutes). Dix heures, comme l'ancienne application.
     */
    'session_minutes' => 600,
];
