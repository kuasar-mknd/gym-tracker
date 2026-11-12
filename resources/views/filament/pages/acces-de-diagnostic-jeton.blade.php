{{--
    La fenêtre du jeton en clair : il est déchiffré au rendu, pour son
    créateur, et n'entre dans aucun champ de formulaire.
--}}
<div style="display: flex; flex-direction: column; gap: 1rem;">
    @if ($jeton === null)
        <p>Le jeton n'est plus affiché. Révoquez-le et créez-en un autre si vous ne l'avez pas copié.</p>
    @else
        <p>Copiez ce jeton maintenant : il disparaît à la fermeture de cette fenêtre, et seule son empreinte est gardée.</p>
        <pre data-jeton-de-diagnostic style="overflow: auto; font-size: 0.85rem; user-select: all;">{{ $jeton }}</pre>
    @endif
</div>
