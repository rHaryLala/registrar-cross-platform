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
     | Durée de la session (minutes). Dix heures, comme l'ancienne application.
     */
    'session_minutes' => 600,
];
