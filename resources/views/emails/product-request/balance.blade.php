@extends('emails.layout')

@section('subject', $subject)
@section('preheader', $preheader)

@section('content')
    <p style="margin:0 0 14px 0; font-size:16px; color:#111827;">Hello {{ $recipientName }},</p>

    @if($complete)
        <p style="margin:0;">
            Every SKU on <strong>{{ $requestName }}</strong> is now mapped in Cegid —
            <strong style="color:#047857;">{{ $mapped }} of {{ $total }}</strong>.
        </p>

        @include('emails.partials.callout', [
            'tone'    => 'green',
            'heading' => 'Nothing is outstanding',
            'body'    => '<div style="font-weight:600;">The balance came through — finish the remaining products and mark the request complete.</div>',
        ])
    @else
        <p style="margin:0;">
            Supply Chain has mapped <strong>{{ $justMapped }}</strong>
            {{ $justMapped === 1 ? 'more SKU' : 'more SKUs' }} on <strong>{{ $requestName }}</strong>.
        </p>

        @include('emails.partials.callout', [
            'tone'    => 'brand',
            'heading' => 'Where the SKUs stand',
            'body'    => '<div style="font-weight:700; font-size:15px; margin-bottom:2px;">'
                       . $mapped . ' of ' . $total . ' mapped (' . $percent . '%)</div>'
                       . '<div style="font-size:13px;">' . $remaining . ' still with Supply Chain — you can carry on with the rest.</div>',
        ])
    @endif

    @include('emails.partials.summary', ['rows' => $rows])
    @include('emails.partials.button', ['url' => $url, 'label' => 'Open this request'])

    <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">
        The SKU check runs every hour, so this arrives on its own — there is nothing to re-submit.
        The request is at {{ $statusLabel }}.
    </p>
@endsection
