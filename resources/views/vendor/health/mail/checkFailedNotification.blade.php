{{--
    La vue du courriel d'alerte de laravel-health, publiée pour deux écarts
    seulement (#2034) : le titre, que le paquet écrivait en dur (« Laravel
    Health »), passe par sa traduction, et une espace insécable précède le
    deux-points de chaque ligne. SanteTest la compare à celle du paquet : une
    mise à jour qui change l'originale se voit.
--}}
@component('mail::message')
# {{ __('health::notifications.laravel_health') }}

{{ __('health::notifications.check_failed_mail_body') }}

@foreach($results as $result)
- {{ $result->check->getLabel() }}&nbsp;: {{ $result->getNotificationMessage() }}
@endforeach

@endcomponent
