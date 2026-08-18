@extends('layouts.app')

@section('title', 'Configure — ' . $session->name)

@section('content')
{{--
    The step between finding the photos and paying for them.

    A run routinely mixes product types that want opposite treatment — a watch
    face wants no padding, a dress wants plenty — and the previous flow spent
    the whole folder on one answer chosen before anybody had seen a photo.
    Every SKU folder is configured here instead, and nothing is sent to
    Photoroom until this form is submitted.
--}}
<div class="space-y-5" x-data="configureRun()">

    <div>
        <p class="text-xs text-gray-500">Media &rsaquo; Photo Editor</p>
        <h1 class="text-2xl font-semibold text-gray-900">{{ $session->name }}</h1>
        <p class="mt-1 text-sm text-gray-500">
            Set what each SKU should get. Nothing is sent to Photoroom until you start the run.
        </p>
    </div>

    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    @if ($session->scan_status !== 'scanned')
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center"
             x-init="setTimeout(() => window.location.reload(), 4000)">
            <p class="text-sm font-medium text-gray-800">Still reading the folder…</p>
            <p class="mt-1 text-xs text-gray-500">
                {{ number_format($session->scanned_files) }} found so far. This page refreshes itself.
            </p>
        </div>
    @elseif ($groups->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-700">{{ $session->error_message ?: 'No images were found in that folder.' }}</p>
            <a href="{{ route('photo-editor.index') }}" class="mt-3 inline-block text-sm text-brand-600 hover:underline">Start again</a>
        </div>
    @else
    <form method="POST" action="{{ route('photo-editor.start', $session) }}" @submit="starting = true">
        @csrf

        {{-- Running total, so the bill is visible before it is committed to
             rather than discovered afterwards. --}}
        <div class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white/95 px-5 py-4 backdrop-blur">
            <div class="text-sm text-gray-700">
                <span class="font-semibold" x-text="totalCredits"></span> Photoroom credits
                <span class="text-gray-400">·</span>
                <span x-text="photoCount"></span> photos
                <span class="text-gray-400">+</span>
                <span x-text="lifestyleTotal"></span> on-model
                <span class="ml-2 text-xs text-gray-500">of {{ number_format($monthlyQuota) }} this month</span>
            </div>
            <button type="submit" :disabled="starting"
                    class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
                <span x-text="starting ? 'Starting…' : 'Start editing'"></span>
            </button>
        </div>

        <div class="space-y-4">
            @foreach ($groups as $group)
                @php
                    $groupPhotos = $photos[$group->sku] ?? collect();
                    $edits       = $group->edits ?? [];
                @endphp

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white"
                     x-data="{ open: false, lifestyle: {{ (int) $group->lifestyle_count }} }"
                     x-init="$watch('lifestyle', () => recount())">

                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                        <div>
                            <h2 class="font-mono text-sm font-semibold text-gray-900">{{ $group->sku }}</h2>
                            <p class="text-xs text-gray-500">{{ $groupPhotos->count() }} {{ Str::plural('photo', $groupPhotos->count()) }}</p>
                        </div>
                        <button type="button" @click="open = !open" class="text-xs font-medium text-brand-600 hover:underline">
                            <span x-text="open ? 'Hide settings' : 'Settings for this SKU'"></span>
                        </button>
                    </div>

                    {{-- Thumbnails come from OneDrive's own preview renderer.
                         The originals are ~9 MB each and none of them has been
                         edited yet, so pulling the real files to show a grid
                         nobody has decided anything on would cost minutes. --}}
                    <div class="grid grid-cols-3 gap-3 px-5 py-4 sm:grid-cols-6 lg:grid-cols-8 xl:grid-cols-10">
                        @foreach ($groupPhotos as $photo)
                            <label class="group relative cursor-pointer">
                                <input type="radio" class="peer sr-only"
                                       name="groups[{{ $group->id }}][lifestyle_source_item_id]"
                                       value="{{ $photo->id }}"
                                       @checked($group->lifestyle_source_item_id === $photo->id)>
                                <img src="{{ route('photo-editor.onedrive-thumb', [$session, $photo]) }}"
                                     alt="{{ $photo->filename }}" loading="lazy"
                                     class="aspect-square w-full rounded-lg border-2 border-transparent bg-gray-100 object-cover peer-checked:border-brand-500">
                                <span class="mt-1 block truncate text-[11px] text-gray-500">{{ $photo->filename }}</span>
                                <span x-show="lifestyle > 0" x-cloak
                                      class="pointer-events-none absolute left-1 top-1 rounded bg-white/90 px-1.5 py-0.5 text-[10px] font-semibold text-gray-700 peer-checked:bg-brand-600 peer-checked:text-white">
                                    Model wears this
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 bg-gray-50 px-5 py-3">
                        <label for="lifestyle-{{ $group->id }}" class="text-xs font-medium text-gray-700">On-model images</label>
                        <select id="lifestyle-{{ $group->id }}" name="groups[{{ $group->id }}][lifestyle_count]"
                                x-model.number="lifestyle"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                            @for ($n = 0; $n <= $maxLifestyle; $n++)
                                <option value="{{ $n }}">{{ $n === 0 ? 'None' : $n }}</option>
                            @endfor
                        </select>
                        <p x-show="lifestyle > 0" x-cloak class="text-xs text-amber-700">
                            Generated, not photographed — tick which photo the model wears above, and check every result.
                            Costs <span x-text="lifestyle"></span> extra {{ Str::plural('credit', 2) }}.
                        </p>
                    </div>

                    {{-- Full settings per SKU. Seeded from what was chosen on
                         the first screen, so a run where every product does
                         want the same thing needs no edits here at all. --}}
                    <div x-show="open" x-cloak class="space-y-5 border-t border-gray-100 px-5 py-5">
                        @include('photo-editor.partials.group-settings', [
                            'group'         => $group,
                            'edits'         => $edits,
                            'beautifyModes' => $beautifyModes,
                        ])
                    </div>
                </div>
            @endforeach
        </div>
    </form>
    @endif
</div>

<script>
    function configureRun() {
        return {
            starting: false,
            photoCount: {{ $photos->flatten()->count() }},
            lifestyleTotal: {{ $groups->sum('lifestyle_count') }},

            get totalCredits() {
                return this.photoCount + this.lifestyleTotal;
            },

            recount() {
                // Read the selects rather than tracking each card's state
                // separately — one source of truth for the number the operator
                // is being asked to commit to.
                this.lifestyleTotal = Array.from(
                    document.querySelectorAll('select[name$="[lifestyle_count]"]')
                ).reduce((sum, el) => sum + Number(el.value || 0), 0);
            },
        };
    }
</script>
@endsection
