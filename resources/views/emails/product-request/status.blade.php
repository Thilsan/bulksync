@extends('emails.layout')

@section('subject', $subject)
@section('preheader', $preheader)

@section('content')
    <p style="margin:0 0 14px 0; font-size:16px; color:#111827;">Hello {{ $recipientName }},</p>

    <p style="margin:0;">
        <strong>{{ $requestName }}</strong> has moved to
        <strong style="color:#1d5a74;">{{ $statusLabel }}</strong>.
    </p>

    @if($isMine)
        @include('emails.partials.callout', [
            'tone'    => 'brand',
            'heading' => 'This stage is yours',
            'body'    => '<div style="font-weight:600; margin-bottom:4px;">' . e($stageGuide) . '</div>'
                        . ($dueText ? '<div style="font-size:13px;">' . e($dueText) . '</div>' : ''),
        ])
    @else
        <p style="margin:14px 0 0 0; font-weight:600; color:#111827;">What happens next</p>
        <p style="margin:4px 0 0 0; color:#4b5563;">{{ $stageGuide }}</p>
        <p style="margin:8px 0 0 0; font-size:13px; color:#6b7280;">Waiting on: {{ $ownerText }}</p>
    @endif

    @if($remarks)
        @include('emails.partials.callout', [
            'tone'    => 'amber',
            'heading' => 'Remarks',
            'body'    => e($remarks),
        ])
    @endif

    @include('emails.partials.summary', ['rows' => $rows])
    @include('emails.partials.button', ['url' => $url, 'label' => 'Open this request'])

    <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">Updated by {{ $actorName }}.</p>
@endsection
