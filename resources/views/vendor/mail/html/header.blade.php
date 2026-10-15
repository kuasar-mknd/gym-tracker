@props(['url'])
{{--
    L'en-tête des courriels : le logo de l'application, seul (décision du
    propriétaire du 2026-10-15).

    Le logo est l'icône PNG de 64 px, affichée à 48 : un client de messagerie
    ne lit pas un SVG, et l'adresse est absolue, tirée d'APP_URL, puisque le
    courriel se lit hors de l'application. Son texte de remplacement est le
    nom de l'application : un client qui bloque les images l'affiche à sa
    place, et il nomme le lien.
--}}
<tr>
    <td class="header">
        <a href="{{ $url }}" style="display: inline-block;">
            @if (trim($slot) === 'Laravel' || trim($slot) === 'GymTracker')
                <img src="{{ url('/pwa-64x64.png') }}" width="48" height="48" alt="GymTracker" style="vertical-align: middle; border: 0;">
            @else
                {!! $slot !!}
            @endif
        </a>
    </td>
</tr>
