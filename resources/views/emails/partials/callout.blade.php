{{-- Coloured box for the one thing that matters most in the message. --}}
@php
    $tones = [
        'brand' => ['#e9f7fc', '#b0e0f2', '#1c4961'],
        'amber' => ['#fffbeb', '#fde68a', '#78350f'],
        'red'   => ['#fef2f2', '#fecaca', '#7f1d1d'],
        'green' => ['#ecfdf5', '#a7f3d0', '#064e3b'],
    ];
    [$bg, $border, $text] = $tones[$tone ?? 'brand'] ?? $tones['brand'];
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;">
    <tr>
        <td style="padding:14px 16px; background-color:{{ $bg }}; border:1px solid {{ $border }}; border-radius:10px;
                   font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:1.6; color:{{ $text }};">
            @if(!empty($heading))
                <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.6px; font-weight:700; opacity:0.75; margin-bottom:5px;">{{ $heading }}</div>
            @endif
            {!! $body !!}
        </td>
    </tr>
</table>
