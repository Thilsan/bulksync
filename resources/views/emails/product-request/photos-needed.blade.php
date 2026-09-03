@extends('emails.layout')

@section('subject', $subject)
@section('preheader', $preheader)

@section('content')
    <p style="margin:0 0 14px 0; font-size:16px; color:#111827;">Hello {{ $recipientName }},</p>

    <p style="margin:0;">
        <strong>{{ $brand }}</strong> on <strong>{{ $website }}</strong> is going to a photoshoot,
        so the products are needed in the building.
    </p>

    @include('emails.partials.callout', [
        'tone'    => 'brand',
        'heading' => 'What is needed from you',
        'body'    => '<div style="font-weight:700; font-size:15px; margin-bottom:2px;">'
                   . 'Samples for ' . $skus . ' ' . ($skus === 1 ? 'SKU' : 'SKUs') . '</div>'
                   . '<div style="font-size:13px;">Please arrange them for the studio — or send the images '
                   . 'instead if the brand has already supplied them.</div>',
    ])

    @include('emails.partials.summary', ['rows' => $rows])
    @include('emails.partials.button', ['url' => $url, 'label' => 'Open this request'])

    <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">
        Asked by {{ $askedBy }}. The shoot is booked from the Photoshoot Schedule once the samples arrive.
    </p>
@endsection
