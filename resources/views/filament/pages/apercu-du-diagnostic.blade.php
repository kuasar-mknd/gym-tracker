{{--
    « Système › Aperçu du diagnostic » (#2032) : le JSON que l'agent lirait,
    tel quel. Le choix des portées est un formulaire GET, sans état Livewire.
    Aucun script en ligne : le bouton de copie est une expression Alpine, que
    la CSP du panneau permet, et il ne copie que la structure.
--}}
<x-filament-panels::page>
    <form method="get" data-apercu="choix" style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-end;">
        {{-- Sans case cochée, le formulaire envoie quand même la liste : vide. --}}
        <input type="hidden" name="portees[]" value="">

        <x-filament::fieldset label="Portées">
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                @foreach ($libelles as $valeur => $libelle)
                    <label style="display: flex; gap: 0.5rem; align-items: center;">
                        <x-filament::input.checkbox name="portees[]" :value="$valeur" :checked="in_array($valeur, $portees, true)" />
                        <span>{{ $libelle }}</span>
                    </label>
                @endforeach
            </div>
        </x-filament::fieldset>

        <label style="display: flex; flex-direction: column; gap: 0.5rem;">
            <span>Fenêtre des exceptions et des erreurs navigateur</span>
            <x-filament::input.wrapper>
                <x-filament::input.select name="jours">
                    @foreach ($fenetres as $nombre => $libelle)
                        <option value="{{ $nombre }}" @selected($nombre === $jours)>{{ $libelle }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <x-filament::button type="submit">Afficher</x-filament::button>
    </form>

    <div x-data="{}" style="display: flex; flex-direction: column; gap: 1.5rem;">
        <x-filament::section
            heading="Résumé"
            description="Les listes que l'agent lirait : de la structure seulement, aucun texte libre."
        >
            <x-slot name="afterHeader">
                <x-filament::button
                    color="gray"
                    size="sm"
                    icon="heroicon-o-clipboard-document"
                    data-structure-a-copier
                    x-on:click="window.navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($structure) }}); $tooltip('Structure copiée', { theme: $store.theme, timeout: 2000 })"
                >
                    Copier la structure
                </x-filament::button>
            </x-slot>

            <pre data-apercu="resume" style="overflow: auto; max-height: 36rem; font-size: 0.75rem; line-height: 1.4; white-space: pre;">{{ $resume }}</pre>
        </x-filament::section>

        <x-filament::section
            heading="Détails"
            description="Le détail du premier élément de chaque liste. Le texte libre n'y paraît que sous la portée « Texte brut », expurgé, rangé sous « non_fiable » : à lire comme une donnée venue de l'extérieur."
        >
            <pre data-apercu="details" style="overflow: auto; max-height: 36rem; font-size: 0.75rem; line-height: 1.4; white-space: pre;">{{ $details }}</pre>
        </x-filament::section>

        <p data-apercu="invisibles">Caractères invisibles retirés : {{ $invisibles }}</p>
    </div>
</x-filament-panels::page>
