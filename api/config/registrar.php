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
     | Durée de la session (minutes). Dix heures, comme l'ancienne application.
     */
    'session_minutes' => 600,
];
