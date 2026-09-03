@extends('emails.layout')

@section('subject', $subject)
@section('preheader', $preheader)

@section('content')
    <p style="margin:0 0 14px 0; font-size:16px; color:#111827;">Hello {{ $recipientName }},</p>

    <p style="margin:0;">
        <strong>{{ $brand }}</strong> on <strong>{{ $website }}</strong> is waiting on you:
        <strong style="color:#047857;">{{ $mapped }} of {{ $total }}</strong> SKUs are mapped and live.
    </p>

    @include('emails.partials.callout', [
        'tone'    => 'amber',
        'heading' => 'Needs mapping in Cegid',
        'body'    => '<div style="font-weight:700; font-size:15px; margin-bottom:2px;">'
                   . $waiting . ' ' . ($waiting === 1 ? 'SKU' : 'SKUs') . ' still to map'
                   . ($total > 0 ? ' (' . $percent . '% done)' : '') . '</div>'
                   . '<div style="font-size:13px;">They cannot go on the website until they are mapped. '
                   . 'The attached CSV lists them, so it can be worked straight through next to Cegid.</div>',
    ])

    @include('emails.partials.summary', ['rows' => $rows])
    @include('emails.partials.button', ['url' => $url, 'label' => 'Open this request'])

    <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">
        Once they are mapped they appear on the request on their own — the check runs every hour and
        there is nothing to re-submit. The SKUs that are already mapped can be taken forward without
        waiting for these.
    </p>
@endsection
