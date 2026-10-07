{{--
    Tout code 4xx sans page à lui (405, 413, 422…) tombe ici, plutôt que sur
    la page d'exception générique du framework. Un code dont le framework
    fournit la vue (401, 402…) passe avant ce repli : il a donc sa propre page
    dans ce dossier, et `PagesDErreurTest` le vérifie.
--}}
@include('errors.partials.page', [
    'code' => (string) $exception->getStatusCode(),
    'titre' => 'Demande refusée',
    'detail' => "Cette demande n'a pas pu aboutir.",
])
