@extends('emails.layout')

@section('subject', $subject)
@section('preheader', $preheader)

@section('content')
    {{-- The sender's own words, as they wrote them in the request page. --}}
    <p style="margin:0; font-size:15px; color:#111827;">{!! nl2br(e($note)) !!}</p>

    @php
        // Printed for a glance; the attachment is the full list either way.
        $shown = array_slice($skus, 0, 25);
        $more  = $count - count($shown);
    @endphp
    @include('emails.partials.callout', [
        'tone'    => 'amber',
        'heading' => 'Not mapped in Cegid',
        'body'    => '<div style="font-weight:700; font-size:15px; margin-bottom:6px;">'
                   . $count . ' ' . ($count === 1 ? 'SKU is' : 'SKUs are') . ' not showing on ' . e($website) . '</div>'
                   . '<div style="font-family:Menlo,Consolas,monospace; font-size:13px;">' . implode('<br>', array_map('e', $shown)) . '</div>'
                   . ($more > 0 ? '<div style="font-size:13px; margin-top:6px;">… and ' . $more . ' more.</div>' : '')
                   . '<div style="font-size:13px; margin-top:6px;">Please find the attached CSV above — it lists every one.</div>',
    ])

    @include('emails.partials.summary', ['rows' => $rows])

    {{-- No link into the app: IT support works from the attached CSV, not from a login. --}}
    <p style="margin:14px 0 0 0; font-size:13px; color:#6b7280;">
        Sent by {{ $senderName }} from {{ $source ?? 'the product creation request' }}. Reply to this email to reach them.
    </p>
@endsection

@section('footer')
    You are receiving this because {{ $senderName }} asked IT support to check these SKUs. The full list is in the attached CSV.
@endsection
