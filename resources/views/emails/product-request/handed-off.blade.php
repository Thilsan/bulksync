@extends('emails.layout')

@section('subject', $subject)
@section('preheader', $preheader)

@section('content')
    <p style="margin:0 0 14px 0; font-size:16px; color:#111827;">Hello {{ $recipientName }},</p>

    <p style="margin:0;">
        <strong>{{ $newOwnerName }}</strong> has taken over as
        <strong style="color:#1d5a74;">{{ $roleLabel }}</strong> on <strong>{{ $requestName }}</strong>.
    </p>

    @include('emails.partials.callout', [
        'tone'    => 'brand',
        'heading' => 'No longer with you',
        'body'    => 'You do not need to do anything further on this stage. It is worth passing on anything '
                   . 'you have already started so ' . e($newOwnerName) . ' is not repeating it.',
    ])

    @include('emails.partials.summary', ['rows' => $rows])
    @include('emails.partials.button', ['url' => $url, 'label' => 'Open this request'])

    <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">Handed over by {{ $actorName }}.</p>
@endsection
