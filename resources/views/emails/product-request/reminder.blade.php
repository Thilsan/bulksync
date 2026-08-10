@extends('emails.layout')

@section('subject', $subject)
@section('preheader', $preheader)

@section('content')
    <p style="margin:0 0 14px 0; font-size:16px; color:#111827;">Hello {{ $recipientName }},</p>

    <p style="margin:0 0 4px 0;">
        {{ $count === 1 ? 'One request needs' : $count . ' requests need' }} your attention.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 4px 0;">
        @foreach($items as $item)
            <tr>
                <td style="padding:12px 14px; background-color:#fffbeb; border:1px solid #fde68a; border-radius:10px;
                           font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                    <div style="font-size:14px; font-weight:700; color:#111827;">
                        <a href="{{ $item['url'] }}" style="color:#1d5a74; text-decoration:none;">{{ $item['name'] }}</a>
                    </div>
                    <div style="font-size:12px; color:#9ca3af; margin-top:2px;">{{ $item['reference'] }}</div>
                    <div style="font-size:13px; color:#78350f; margin-top:6px;">{{ $item['reason'] }}</div>
                </td>
            </tr>
            @unless($loop->last)
                <tr><td style="height:8px; line-height:8px; font-size:0;">&nbsp;</td></tr>
            @endunless
        @endforeach
    </table>

    @include('emails.partials.button', ['url' => $url, 'label' => 'Open Assigned to Me'])

    <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">
        This is a once-a-day summary of only the work sitting with you. If any of it is blocked,
        open the request and use <strong>Report a blocker</strong> so it stops being chased and the
        team knows why.
    </p>
@endsection
