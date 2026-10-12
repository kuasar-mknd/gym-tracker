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

    // Écrit aussi pour un compte qui attend son lien d’ouverture : son refus ne se distingue pas.
    'failed' => 'These credentials do not match our records. If you have just signed up, first follow the link sent to your address, then sign in on this device: a new link is sent there, at most one every quarter of an hour.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // Écrits pour les deux cas : l'adresse a déjà un compte, ou non.
    'inscription_recue' => 'An email is on its way to this address: follow its link, then sign in here to open your account. If the address already has an account, the email tells you how to sign in.',
    'lien_d_ouverture_suivi' => 'Link received: sign in to open your account.',

];
