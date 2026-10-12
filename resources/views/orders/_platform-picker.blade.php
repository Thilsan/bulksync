{{-- Platform picker, shared by the Ecom Delivery and Ecom Order Analytics
     filter bars. Twenty-three storefronts is too many for a row of
     checkboxes and too few to need a search. Expects $platforms and
     $filters['platforms']. --}}
<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button type="button" @click="open = !open"
            class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs text-gray-700 hover:bg-gray-50 flex items-center gap-1.5">
        {{ $filters['platforms'] ? count($filters['platforms']) . ' platforms' : 'All platforms' }}
        <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="absolute right-0 mt-1 w-56 max-h-72 overflow-y-auto bg-white rounded-lg border border-gray-200 shadow-lg z-20 p-2">
        <div class="flex gap-2 px-1 pb-2 border-b border-gray-100 mb-1">
            <button type="button" class="text-xs text-brand-600 hover:underline"
                    @click="$el.closest('div').parentElement.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = true)">All</button>
            <button type="button" class="text-xs text-gray-500 hover:underline"
                    @click="$el.closest('div').parentElement.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = false)">None</button>
        </div>
        @foreach($platforms as $slug)
            <label class="flex items-center gap-2 px-1 py-1 rounded hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" name="platforms[]" value="{{ $slug }}"
                       @checked(in_array($slug, $filters['platforms'], true))
                       class="w-3.5 h-3.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                <span class="text-xs text-gray-700">{{ $slug }}</span>
            </label>
        @endforeach
    </div>
</div>
