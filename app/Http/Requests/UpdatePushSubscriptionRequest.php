<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\PublicPushEndpoint;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePushSubscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
