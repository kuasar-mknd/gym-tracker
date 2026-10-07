{{--
    Tout code 5xx sans page à lui (502, 504…) tombe ici, plutôt que sur la
    page d'exception générique du framework.
--}}
@include('errors.partials.page', [
    'code' => (string) $exception->getStatusCode(),
    'titre' => 'Service indisponible',
    'detail' => 'Le service ne répond pas pour le moment. Réessaie dans quelques instants.',
])
