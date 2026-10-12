<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
    'password' => 'Le mot de passe fourni est incorrect.',
    'throttle' => 'Trop de tentatives de connexion. Veuillez essayer de nouveau dans :seconds secondes.',

    // Écrits pour les deux cas : l'adresse a déjà un compte, ou non.
    'inscription_recue' => 'Un courriel vient de partir vers cette adresse : suis son lien, puis connecte-toi ici pour ouvrir ton compte. Si elle a déjà un compte, le courriel te dit comment t’y connecter.',
    'compte_en_attente' => 'Ton compte n’est pas encore ouvert : suis le lien envoyé à ton adresse, puis connecte-toi sur cet appareil. Un nouveau lien y part, au plus un par quart d’heure.',
    'lien_d_ouverture_suivi' => 'Lien reçu : connecte-toi pour ouvrir ton compte.',

];
