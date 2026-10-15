@props(['url'])
{{--
    L'en-tête des courriels : le logo de l'application et son nom.

    Le logo est l'icône PNG de 64 px, affichée à 32 : un client de messagerie
    ne lit pas un SVG, et l'adresse est absolue, tirée d'APP_URL, puisque le
    courriel se lit hors de l'application. Il est décoratif (`alt=""`) : un
    client qui bloque les images n'affiche rien à sa place, et le nom écrit à
    côté reste.
--}}
<tr>
    <td class="header">
        <a href="{{ $url }}" style="display: inline-block;">
            @if (trim($slot) === 'Laravel' || trim($slot) === 'GymTracker')
                <img src="{{ url('/pwa-64x64.png') }}" width="32" height="32" alt="" style="vertical-align: middle; margin-right: 8px; border: 0;">
                <span style="font-size: 24px; font-weight: 800; color: {{ \App\Support\Charte::jeton('accent-primary-deep') }}; letter-spacing: -1px; vertical-align: middle;">GymTracker</span>
            @else
                {!! $slot !!}
            @endif
        </a>
    </td>
</tr>
