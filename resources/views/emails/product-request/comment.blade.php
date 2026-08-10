@extends('emails.layout')

@section('subject', $subject)
@section('preheader', $preheader)

@section('content')
    <p style="margin:0 0 14px 0; font-size:16px; color:#111827;">Hello {{ $recipientName }},</p>

    <p style="margin:0;">
        @if($mentioned)
            <strong>{{ $actorName }}</strong> mentioned you on <strong>{{ $requestName }}</strong>.
        @else
            <strong>{{ $actorName }}</strong> commented on <strong>{{ $requestName }}</strong>.
        @endif
    </p>

    @include('emails.partials.callout', [
        'tone'    => $mentioned ? 'amber' : 'brand',
        'heading' => $mentioned ? 'You were mentioned' : 'Comment',
        'body'    => '<div style="font-style:italic;">&ldquo;' . nl2br(e($body)) . '&rdquo;</div>',
    ])

    @include('emails.partials.summary', ['rows' => $rows])
    @include('emails.partials.button', ['url' => $url, 'label' => 'Reply on the request'])

    <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">
        Replies belong on the request itself so the whole thread stays in one place.
    </p>
@endsection
