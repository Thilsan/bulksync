{{-- Request facts, so the recipient doesn't have to open the system to know
     which request this is or when it has to be live. --}}
@if(!empty($rows))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin:18px 0 4px 0; border:1px solid #e5e7eb; border-radius:10px; border-collapse:separate; overflow:hidden;">
    @foreach($rows as $label => $value)
        @continue(blank($value))
        <tr>
            <td style="padding:9px 14px; background-color:{{ $loop->even ? '#fafafa' : '#ffffff' }}; border-bottom:{{ $loop->last ? 'none' : '1px solid #f3f4f6' }};
                       font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#6b7280; width:42%;">
                {{ $label }}
            </td>
            <td style="padding:9px 14px; background-color:{{ $loop->even ? '#fafafa' : '#ffffff' }}; border-bottom:{{ $loop->last ? 'none' : '1px solid #f3f4f6' }};
                       font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#111827; font-weight:600;">
                {{ $value }}
            </td>
        </tr>
    @endforeach
</table>
@endif
