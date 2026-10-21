@extends('layouts.app')

@section('title', 'Email Log')
@section('page-title', 'Email Log')

@section('content')
<div class="w-full space-y-6">

    <a href="{{ url()->previous() === url()->current() ? route('super-admin.emails.index') : url()->previous() }}"
       class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to the log</a>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">{{ $log->subject ?: '(no subject)' }}</h2>
            @include('super-admin.emails.partials.status', ['status' => $log->status])
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
            <div>
                <dt class="text-xs font-medium text-gray-500">To</dt>
                <dd class="text-gray-800">{{ $log->user?->name ? $log->user->name . ' · ' : '' }}{{ $log->to_address }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500">Type</dt>
                <dd class="text-gray-800">{{ $log->kind() }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500">Queued</dt>
                <dd class="text-gray-800">{{ $log->created_at->format('d M Y, H:i:s') }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500">Sent</dt>
                <dd class="text-gray-800">{{ $log->sent_at?->format('d M Y, H:i:s') ?? '—' }}</dd>
            </div>
            @if($log->productRequest)
            <div>
                <dt class="text-xs font-medium text-gray-500">Request</dt>
                <dd>
                    <a href="{{ route('product-requests.show', $log->productRequest) }}" class="text-brand-700 hover:underline">
                        {{ $log->productRequest->reference }}
                    </a>
                    <a href="{{ route('super-admin.emails.index', ['q' => $log->productRequest->reference]) }}"
                       class="ml-2 text-xs text-gray-500 hover:text-gray-700">every email about it</a>
                </dd>
            </div>
            @endif
        </dl>

        @if($log->error)
        <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ $log->error }}</div>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        @if($log->body !== null)
            <iframe src="{{ route('super-admin.emails.body', $log) }}" sandbox title="The email as sent"
                    class="w-full h-[70vh] border-0 bg-white"></iframe>
        @else
            <p class="px-6 py-10 text-center text-sm text-gray-400">
                The body is no longer kept — emails older than {{ \App\Models\EmailLog::KEEP_BODY_DAYS }} days keep only their details.
            </p>
        @endif
    </div>

</div>
@endsection
