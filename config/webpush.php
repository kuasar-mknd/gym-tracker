<?php

return [

    /**
     * These are the keys for authentication (VAPID).
     * These keys must be safely stored and should not change.
     */
    'vapid' => [
        // web-push throws when the subject is missing (VAPID.php:49), so fall
        // back to the app URL rather than let every push notification fail.
        'subject' => env('VAPID_SUBJECT') ?: env('APP_URL'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /**
     * This is model that will be used to for push subscriptions.
     */
    'model' => \NotificationChannels\WebPush\PushSubscription::class,

    /**
     * This is the name of the table that will be created by the migration and
     * used by the PushSubscription model shipped with this package.
     */
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    /**
     * This is the database connection that will be used by the migration and
     * the PushSubscription model shipped with this package.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /**
     * The automatic padding in bytes used by Minishlink\WebPush.
     * Set to false to support Firefox Android with v1 endpoint.
     */
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

    /**
     * Le temps, en secondes, qu'une notification passe à joindre les appareils
     * d'un compte : passé ce budget, les appareils restants ne la reçoivent
     * pas (`App\Support\WebPushBorne`). Sous les 60 s d'Horizon.
     */
    'budget_secondes' => 25.0,

];
