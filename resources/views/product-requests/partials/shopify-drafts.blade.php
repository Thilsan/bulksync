{{--
    Review screen for products staged from the tracking sheet.

    Everything here is a proposal: the rows come off the sheet, the team corrects
    whatever it left blank, and only "Create in Shopify" reaches a storefront —
    always as a draft, never published.
--}}
<div class="space-y-4">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-800">Shopify Drafts</h3>
            <p class="text-xs text-gray-500 mt-0.5">
                Built from the tracking sheet for the SKUs that are not in Shopify yet.
                Products are created as <span class="font-medium">drafts</span> — nothing goes live from here.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('product-requests.drafts.build', $request) }}">
                @csrf
                <button type="submit" class="px-3 py-1.5 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
                    {{ $drafts->isEmpty() ? 'Build from sheet' : 'Rebuild from sheet' }}
                </button>
            </form>

            @if($drafts->isNotEmpty())
                <a href="{{ route('product-requests.drafts.download', $request) }}"
                   class="px-3 py-1.5 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
                    Download Shopify CSV
                </a>
            @endif
        </div>
    </div>

    @if($drafts->isEmpty())
        <div class="border border-dashed border-gray-300 rounded-lg px-5 py-8 text-center">
            <p class="text-sm text-gray-600">
                @if($missingSkus === 0)
                    Every SKU on this request is already in Shopify — there is nothing to create.
                @else
                    {{ $missingSkus }} SKU(s) are not in Shopify yet.
                    Build from the sheet to stage them as draft products.
                @endif
            </p>
        </div>
    @else
        @php
            $pending = $drafts->reject->isPushed();
            $ready   = $pending->filter->isReadyToPush();
        @endphp

        {{-- Push bar --}}
        @if($pending->isNotEmpty())
            <form method="POST" action="{{ route('product-requests.drafts.push', $request) }}"
                  class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 flex flex-wrap items-end gap-3"
                  onsubmit="return confirm('Create {{ $ready->count() }} product(s) in the selected website as drafts?');">
                @csrf
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Create in website</label>
                    <select name="store_id" required
                            class="text-sm rounded-lg border-gray-300 focus:border-brand-500 focus:ring-brand-500">
                        @foreach($pushStores as $store)
                            <option value="{{ $store->id }}" @selected($store->id === $request->store_id)>{{ $store->name }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit"
                        @disabled($ready->isEmpty())
                        class="px-4 py-2 text-sm font-medium rounded-lg bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    Create {{ $ready->count() }} product(s) as drafts
                </button>

                @if($ready->count() < $pending->count())
                    <p class="text-xs text-amber-700">
                        {{ $pending->count() - $ready->count() }} not ready — fill in the missing title or price below.
                    </p>
                @endif
            </form>
        @endif

        {{-- One block per product --}}
        @foreach($drafts as $draft)
            <div class="border border-gray-200 rounded-lg" x-data="{ open: false }">
                <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 truncate">{{ $draft->title }}</p>
                        <p class="text-xs text-gray-400">
                            {{ $draft->handle }} &middot; {{ $draft->variants->count() }} variant(s)
                            @if($draft->style_code) &middot; style {{ $draft->style_code }} @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        @if($draft->isPushed())
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border bg-green-50 text-green-700 border-green-200">
                                Draft in {{ $draft->pushedToStore?->name ?? 'Shopify' }}
                            </span>
                        @elseif($draft->push_status === \App\Models\ProductRequestDraftProduct::FAILED)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border bg-red-50 text-red-700 border-red-200"
                                  title="{{ $draft->push_error }}">Failed</span>
                        @elseif($gaps = $draft->gaps())
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border bg-amber-50 text-amber-700 border-amber-200">
                                Needs {{ implode(' + ', $gaps) }}
                            </span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border bg-gray-50 text-gray-600 border-gray-200">Ready</span>
                        @endif

                        <button type="button" @click="open = !open"
                                class="text-xs text-brand-600 hover:text-brand-700 font-medium"
                                x-text="open ? 'Close' : 'Review'"></button>
                    </div>
                </div>

                @if($draft->push_error && !$draft->isPushed())
                    <p class="px-4 pb-3 text-xs text-red-600">{{ $draft->push_error }}</p>
                @endif

                <div x-show="open" x-cloak class="border-t border-gray-100 px-4 py-4">
                    @if($draft->isPushed())
                        <p class="text-sm text-gray-600">
                            Created in Shopify on {{ $draft->pushed_at?->format('d M Y, h:i A') }}
                            (product {{ $draft->shopify_product_id }}). Edit it in Shopify from here on.
                        </p>
                    @else
                        <form method="POST" action="{{ route('product-requests.drafts.update', [$request, $draft]) }}" class="space-y-4">
                            @csrf
                            @method('PUT')

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach(['title' => 'Title', 'vendor' => 'Vendor', 'product_type' => 'Type', 'tags' => 'Tags'] as $field => $label)
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">{{ $label }}</label>
                                        <input type="text" name="{{ $field }}" value="{{ old($field, $draft->$field) }}"
                                               @required($field === 'title')
                                               class="w-full text-sm rounded-lg border-gray-300 focus:border-brand-500 focus:ring-brand-500">
                                    </div>
                                @endforeach
                            </div>

                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Description (HTML)</label>
                                <textarea name="body_html" rows="3"
                                          class="w-full text-sm rounded-lg border-gray-300 focus:border-brand-500 focus:ring-brand-500">{{ old('body_html', $draft->body_html) }}</textarea>
                            </div>

                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Main image URL</label>
                                <input type="url" name="image_src" value="{{ old('image_src', $draft->image_src) }}"
                                       class="w-full text-sm rounded-lg border-gray-300 focus:border-brand-500 focus:ring-brand-500">
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="text-xs text-gray-500 border-b border-gray-100">
                                        <tr>
                                            <th class="py-2 text-left font-medium">SKU</th>
                                            @foreach($draft->optionNames() as $i => $name)
                                                <th class="py-2 text-left font-medium">{{ $name }}</th>
                                            @endforeach
                                            <th class="py-2 text-left font-medium">Price</th>
                                            <th class="py-2 text-left font-medium">Compare at</th>
                                            <th class="py-2 text-left font-medium">Barcode</th>
                                            <th class="py-2 text-left font-medium">Qty</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-50">
                                        @foreach($draft->variants as $i => $variant)
                                            <tr>
                                                <td class="py-2 pr-3 font-mono text-xs text-gray-700 whitespace-nowrap">
                                                    {{ $variant->sku }}
                                                    <input type="hidden" name="variants[{{ $i }}][id]" value="{{ $variant->id }}">
                                                </td>
                                                @foreach($draft->optionNames() as $position => $name)
                                                    @php $field = 'option' . ($position + 1) . '_value'; @endphp
                                                    <td class="py-2 pr-3">
                                                        <input type="text" name="variants[{{ $i }}][{{ $field }}]" value="{{ $variant->$field }}"
                                                               class="w-24 text-sm rounded-lg border-gray-300 focus:border-brand-500 focus:ring-brand-500">
                                                    </td>
                                                @endforeach
                                                @foreach(['price' => 'w-24', 'compare_at_price' => 'w-24', 'barcode' => 'w-32', 'inventory_qty' => 'w-16'] as $field => $width)
                                                    <td class="py-2 pr-3">
                                                        <input type="{{ in_array($field, ['price', 'compare_at_price', 'inventory_qty'], true) ? 'number' : 'text' }}"
                                                               @if($field !== 'inventory_qty') step="0.01" @endif min="0"
                                                               name="variants[{{ $i }}][{{ $field }}]" value="{{ $variant->$field }}"
                                                               class="{{ $width }} text-sm rounded-lg border-gray-300 focus:border-brand-500 focus:ring-brand-500">
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex items-center gap-2 pt-1">
                                <button type="submit" class="px-3.5 py-2 text-sm font-medium rounded-lg bg-brand-600 text-white hover:bg-brand-700">
                                    Save
                                </button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('product-requests.drafts.destroy', [$request, $draft]) }}" class="mt-3"
                              onsubmit="return confirm('Remove this draft product? The SKUs stay on the request.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-red-600 hover:text-red-700">Remove this draft</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
</div>
