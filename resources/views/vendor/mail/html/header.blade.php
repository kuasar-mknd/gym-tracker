@props(['url'])
<tr>
    <td class="header">
        <a href="{{ $url }}" style="display: inline-block;">
            @if (trim($slot) === 'Laravel' || trim($slot) === 'GymTracker')
                <span style="font-size: 24px; font-weight: 800; color: #4f46e5; letter-spacing: -1px;">GymTracker</span>
            @else
                {!! $slot !!}
            @endif
        </a>
    </td>
</tr>
