<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\PublicPushEndpoint;
use Illuminate\Foundation\Http\FormRequest;

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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // `url` alone accepts any scheme and any host, so the stored endpoint
            // could point the server at its own network — the WebPush channel
            // POSTs to it on every notification. See App\Rules\PublicPushEndpoint.
            'endpoint' => ['required', 'url', new PublicPushEndpoint()],
            'keys.auth' => 'required',
            'keys.p256dh' => 'required',
        ];
    }
}
