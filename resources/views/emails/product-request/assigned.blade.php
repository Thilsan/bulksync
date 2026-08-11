@extends('emails.layout')

@section('subject', $mentionSubject)
@section('preheader', $preheader)

@section('content')
    <p style="margin:0 0 14px 0; font-size:16px; color:#111827;">Hello {{ $recipientName }},</p>

    @if($assigneeName ?? null)
        {{-- An information copy: this reader has no task, so nothing here asks
             them to do one. --}}
        <p style="margin:0 0 4px 0;">
            <strong>{{ $actorName }}</strong>
            @if($handedOverFrom)
                has handed the <strong style="color:#1d5a74;">{{ $roleLabel }}</strong> task on this request
                to <strong>{{ $assigneeName }}</strong>, previously with {{ $handedOverFrom }}.
            @else
                has made <strong>{{ $assigneeName }}</strong> the
                <strong style="color:#1d5a74;">{{ $roleLabel }}</strong> on this request.
            @endif
            You are copied for information.
        </p>
    @elseif($handedOverFrom)
        <p style="margin:0 0 4px 0;">
            <strong>{{ $actorName }}</strong> has handed this task over to you as
            <strong style="color:#1d5a74;">{{ $roleLabel }}</strong>, previously with {{ $handedOverFrom }}.
        </p>
    @else
        <p style="margin:0 0 4px 0;">
            <strong>{{ $actorName }}</strong> has assigned you to a product creation request as
            <strong style="color:#1d5a74;">{{ $roleLabel }}</strong>.
        </p>
    @endif

    @include('emails.partials.callout', [
        'tone'    => $dueTone,
        'heading' => ($assigneeName ?? null) ? $assigneeName . '’s task' : 'Your task',
        'body'    => $taskHtml,
    ])

    <p style="margin:18px 0 0 0; font-weight:600; color:#111827;">What this stage needs</p>
    <p style="margin:4px 0 0 0; color:#4b5563;">{{ $stageGuide }}</p>

    @include('emails.partials.summary', ['rows' => $rows])

    @include('emails.partials.button', ['url' => $url, 'label' => 'Open this request'])

    @if($assigneeName ?? null)
        <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">
            Nothing is needed from you — this is sent so you can follow the request.
            Open it any time to see where it has got to.
        </p>
    @else
        <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">
            When you have finished, open the request and move it to the next stage so the
            following team knows they can start. If something is stopping you, use
            <strong>Report a blocker</strong> — everyone involved is told straight away.
        </p>
    @endif
@endsection
