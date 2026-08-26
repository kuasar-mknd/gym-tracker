@include('errors.partials.page', [
    'code' => '404',
    'titre' => 'Page introuvable',
    'detail' => "Cette page n'existe pas, ou elle a changé d'adresse.",
])
