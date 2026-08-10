@extends('emails.layout')

@section('subject', $subject)
@section('preheader', $preheader)

@section('content')
    <p style="margin:0 0 14px 0; font-size:16px; color:#111827;">Hello {{ $recipientName }},</p>

    @if($onHold)
        <p style="margin:0;">
            <strong>{{ $requestName }}</strong> has been put
            <strong style="color:#b91c1c;">on hold</strong> at {{ $statusLabel }}.
        </p>

        @include('emails.partials.callout', [
            'tone'    => 'red',
            'heading' => 'What is blocking it',
            'body'    => '<div style="font-weight:600;">' . e($reason) . '</div>',
        ])

        <p style="margin:0; color:#4b5563;">
            Nothing moves until this is resolved, and the launch date has not changed.
            If you can unblock it, please do — otherwise reply here so the team knows where it stands.
        </p>
    @else
        <p style="margin:0;">
            <strong>{{ $requestName }}</strong> is
            <strong style="color:#047857;">back in progress</strong> at {{ $statusLabel }}.
        </p>

        @include('emails.partials.callout', [
            'tone'    => 'green',
            'heading' => 'Unblocked',
            'body'    => e($stageGuide),
        ])
    @endif

    @include('emails.partials.summary', ['rows' => $rows])
    @include('emails.partials.button', ['url' => $url, 'label' => 'Open this request'])

    <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">Updated by {{ $actorName }}.</p>
@endsection
