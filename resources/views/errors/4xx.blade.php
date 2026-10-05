{{--
    Tout code 4xx sans page à lui (401, 405, 413…) tombe ici, plutôt que sur
    la vue anglaise du framework ou sur sa page d'exception générique.
--}}
@include('errors.partials.page', [
    'code' => (string) $exception->getStatusCode(),
    'titre' => 'Demande refusée',
    'detail' => "Cette demande n'a pas pu aboutir.",
])
