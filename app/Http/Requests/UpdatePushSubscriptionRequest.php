<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\PublicPushEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use NotificationChannels\WebPush\PushSubscription;

class UpdatePushSubscriptionRequest extends FormRequest
{
    /**
     * La longueur d'une clé de l'abonnement : celle des colonnes qui les
     * reçoivent (`public_key` et `auth_token`, varchar(255) dans la migration
     * du paquet WebPush). Un navigateur en envoie de bien plus courtes (22
     * caractères pour `auth`, 87 pour `p256dh`, en base64url) ; au-delà, la
     * base refusait l'écriture, et la requête finissait en 500.
     */
    public const int CLEF_MAX = 255;

    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Les clés vont au paquet WebPush, qui les attend en chaînes
     * (`updatePushSubscription()`) : validées par `required` seul, un tableau
     * ou un nombre y levait une erreur de type, et une chaîne trop longue
     * dépassait sa colonne ; chacun répondait 500 (#2071).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // `url` seul accepte n'importe quel schéma et n'importe quel hôte :
            // l'endpoint stocké pourrait diriger le serveur vers son propre
            // réseau, puisque le canal WebPush y poste à chaque notification.
            // Voir App\Rules\PublicPushEndpoint. La longueur est celle de la
            // colonne : au-delà, la base refusait l'écriture et rendait un 500.
            'endpoint' => ['required', 'url', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH, new PublicPushEndpoint()],
            'keys' => ['required', 'array'],
            'keys.auth' => ['bail', 'required', 'string', 'max:'.self::CLEF_MAX],
            'keys.p256dh' => ['bail', 'required', 'string', 'max:'.self::CLEF_MAX],
        ];
    }
}
