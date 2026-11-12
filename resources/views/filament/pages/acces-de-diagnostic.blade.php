{{--
    « Système › Accès de diagnostic » (#2032) : la liste des jetons, et deux
    actions en tête (création, « Tout révoquer »). Le jeton en clair ne paraît
    que dans la fenêtre de création, jamais dans cette page.
--}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
