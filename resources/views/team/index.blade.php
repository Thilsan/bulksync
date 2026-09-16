@extends('layouts.app')
@section('title', 'Team Chart')
@section('page-title', 'Team Chart')

@php
    /*
     * One chart per department. Ecommerce is the only one anybody has written
     * down so far; the rest are declared here anyway, so the tab bar shows the
     * whole department and filling one in is a partial beside this file rather
     * than a new page and a new route.
     */
    $tabs = [
        'ecommerce'    => 'Ecommerce',
        'social-media' => 'Social Media',
        'marketing'    => 'Marketing',
        'crm'          => 'CRM',
    ];

    /*
     * ?tab[]=crm arrives as an array, and this page is one anyone can reach by
     * editing the address bar — so anything that is not a name we know falls
     * back to the one chart that has something on it.
     */
    $requested = request()->query('tab');
    $tab = is_string($requested) && array_key_exists($requested, $tabs) ? $requested : 'ecommerce';
@endphp

@section('content')
<div class="space-y-5">

    {{-- ── Tabs ─────────────────────────────────────────────────────────────
         Plain links, so each department's chart is its own shareable URL. --}}
    <div class="border-b border-gray-200 flex items-center gap-1" role="tablist">
        @foreach($tabs as $key => $label)
            <a href="{{ route('team.index', ['tab' => $key]) }}"
               role="tab" @if($tab === $key) aria-selected="true" @endif
               class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors
                      {{ $tab === $key
                          ? 'border-brand-600 text-brand-700'
                          : 'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if($tab === 'ecommerce')
        @include('team.ecommerce')
    @else
        {{-- Not left blank: an empty card says the chart is still to be
             written, where a white screen reads as a tab that failed. --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-10 text-center">
            <p class="text-sm font-medium text-gray-800">{{ $tabs[$tab] }} chart not added yet</p>
            <p class="text-sm text-gray-500 mt-1">
                Who owns what in {{ $tabs[$tab] }} has not been written down here yet.
            </p>
        </div>
    @endif
</div>
@endsection
