@include('errors.partials.page', [
    'code' => '429',
    'titre' => 'Trop de tentatives',
    'detail' => 'Patiente une minute, puis réessaie.',
])
