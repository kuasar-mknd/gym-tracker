@include('errors.partials.page', [
    'code' => '500',
    'titre' => 'Erreur serveur',
    'detail' => "Quelque chose s'est mal passé de notre côté. L'incident est enregistré.",
])
