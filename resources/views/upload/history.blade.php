@extends('layouts.app')
@section('title', 'Upload History')
@section('page-title', 'Upload History')

@section('content')
<div class="space-y-5">

    @if (session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">
        {{ session('success') }}
    </div>
    @endif

    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500">{{ $sessions->total() }} upload session{{ $sessions->total() !== 1 ? 's' : '' }}</p>
        <a href="{{ route('upload.create') }}"
            class="bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
            + New Upload
        </a>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        @if ($sessions->isEmpty())
        <div class="px-6 py-16 text-center text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p>No uploads yet.</p>
        </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-6 py-3 text-left">Session Name</th>
                    <th class="px-6 py-3 text-left">Size</th>
                    <th class="px-6 py-3 text-center">Total</th>
                    <th class="px-6 py-3 text-center">Uploaded</th>
                    <th class="px-6 py-3 text-center">No Match</th>
                    <th class="px-6 py-3 text-center">Failed</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-left">Created</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($sessions as $session)
                @php
                    $colors = ['pending' => 'gray', 'processing' => 'brand', 'completed' => 'green', 'failed' => 'red'];
                    $c = $colors[$session->status] ?? 'gray';
                @endphp
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-3 font-medium text-gray-800">{{ $session->name }}</td>
                    <td class="px-6 py-3 text-gray-500 capitalize">{{ $session->image_size }}</td>
                    <td class="px-6 py-3 text-center text-gray-700">{{ $session->total_files }}</td>
                    <td class="px-6 py-3 text-center font-semibold text-green-700">{{ $session->uploaded_files }}</td>
                    <td class="px-6 py-3 text-center text-yellow-600">{{ $session->skipped_files }}</td>
                    <td class="px-6 py-3 text-center text-red-600">{{ $session->failed_files }}</td>
                    <td class="px-6 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-{{ $c }}-100 text-{{ $c }}-700">
                            {{ ucfirst($session->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-3 text-gray-500 whitespace-nowrap">{{ $session->created_at->format('d M Y H:i') }}</td>
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-3">
                            <a href="{{ route('upload.show', $session) }}" class="text-brand-600 hover:underline text-xs font-medium">View →</a>
                            <form method="POST" action="{{ route('upload.destroy', $session) }}"
                                  onsubmit="return confirm('Delete session \'{{ addslashes($session->name) }}\' and all its items?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="text-red-500 hover:text-red-700 text-xs font-medium transition-colors">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="px-6 py-4 border-t border-gray-100">
            {{ $sessions->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
