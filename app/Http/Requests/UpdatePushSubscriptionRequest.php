<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\PublicPushEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use NotificationChannels\WebPush\PushSubscription;

class UpdatePushSubscriptionRequest extends FormRequest
{
    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
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
            'keys.auth' => 'required',
            'keys.p256dh' => 'required',
        ];
    }
}
