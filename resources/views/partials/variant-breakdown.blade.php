{{--
    One SKU's colours and sizes from Shopify: the product, then a card per
    colour with a row per variant. Shared by the SKU Checker and a request's
    SKUs tab. Expects the surrounding Alpine scope to expose breakdown,
    breakdownLoading and breakdownError.
--}}
                        <template x-if="breakdownLoading">
                            <p class="text-sm text-gray-500 py-3">Reading variants from Shopify…</p>
                        </template>

                        <template x-if="!breakdownLoading && breakdownError">
                            <p class="text-sm text-red-600 py-3" x-text="breakdownError"></p>
                        </template>

                        <template x-if="!breakdownLoading && breakdown">
                            <div class="space-y-3 pt-3">
                                {{--
                                    The same facts the CSV carries, in the same
                                    order: the product, then a row per variant.
                                    The panel used to show only what a colour
                                    covered, so anyone who wanted the variant's
                                    own SKU or id had to build the export.
                                --}}
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span class="rounded-md bg-white px-2 py-0.5 font-mono text-[11px] text-gray-500 ring-1 ring-gray-200"
                                          x-text="'ID ' + breakdown.product_id"></span>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                          :class="breakdown.published ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'"
                                          x-text="breakdown.published ? 'Published' : 'Draft'"></span>
                                    <span class="text-gray-400">·</span>
                                    <span class="text-gray-500">
                                        <span class="figure text-gray-800" x-text="breakdown.with_image_count"></span> of
                                        <span class="figure text-gray-800" x-text="breakdown.variant_count"></span> variants have their own photo
                                    </span>
                                    <span class="text-gray-400">·</span>
                                    <span class="text-gray-500">
                                        <span class="figure text-gray-800" x-text="breakdown.gallery_count"></span> in the gallery
                                    </span>
                                    <template x-if="breakdown.stock !== null">
                                        <span class="contents">
                                            <span class="text-gray-400">·</span>
                                            <span class="text-gray-500">
                                                <span class="figure text-gray-800" x-text="breakdown.stock"></span> in stock
                                            </span>
                                        </span>
                                    </template>
                                </div>

                                <template x-for="colour in breakdown.colours" :key="colour.colour">
                                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                                        <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-3">
                                            <template x-if="colour.preview">
                                                <img :src="colour.preview" alt=""
                                                     class="h-10 w-10 rounded border border-gray-200 object-cover">
                                            </template>
                                            <template x-if="!colour.preview">
                                                <div class="h-10 w-10 rounded border border-dashed border-gray-300"></div>
                                            </template>

                                            <div class="flex-1">
                                                <p class="text-sm font-medium text-gray-800" x-text="colour.colour"></p>
                                                <p class="text-xs text-gray-500">
                                                    <span x-text="colour.with_image_count"></span> of
                                                    <span x-text="colour.variant_count"></span> size(s) have a photo
                                                    <template x-if="colour.stock !== null">
                                                        <span>
                                                            · <span class="figure" :class="colour.stock > 0 ? 'text-gray-800' : 'text-red-500'"
                                                                    x-text="colour.stock"></span> in stock
                                                        </span>
                                                    </template>
                                                </p>
                                            </div>

                                            <span class="rounded-full px-2 py-1 text-xs font-medium"
                                                  :class="colour.with_image_count === 0
                                                      ? 'bg-red-50 text-red-600'
                                                      : (colour.with_image_count === colour.variant_count
                                                          ? 'bg-green-50 text-green-700'
                                                          : 'bg-amber-50 text-amber-700')"
                                                  x-text="colour.with_image_count === 0
                                                      ? 'No image'
                                                      : (colour.with_image_count === colour.variant_count ? 'All sizes covered' : 'Partly covered')"></span>
                                        </div>

                                        {{-- One row per variant, the shape the export uses. --}}
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-xs">
                                                <thead>
                                                    <tr class="bg-gray-50/70 text-left text-[10px] text-gray-400">
                                                        <th class="px-4 py-2 font-semibold">Size</th>
                                                        <th class="px-4 py-2 font-semibold">Variant SKU</th>
                                                        <th class="px-4 py-2 text-right font-semibold">Price</th>
                                                        <th class="px-4 py-2 text-right font-semibold">Stock</th>
                                                        <th class="px-4 py-2 text-right font-semibold">Photos</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-50">
                                                    <template x-for="size in colour.sizes" :key="size.variant_id">
                                                        <tr :class="size.is_match && 'bg-brand-50/40'">
                                                            <td class="px-4 py-2">
                                                                <span class="inline-flex items-center gap-1.5 font-medium text-gray-800">
                                                                    <svg x-show="size.has_image" class="h-3 w-3 text-emerald-500"
                                                                         fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                                    </svg>
                                                                    <span x-text="size.size || '—'"></span>
                                                                </span>
                                                                <span x-show="size.is_match"
                                                                      class="ml-1.5 text-[9px] font-semibold uppercase tracking-wide text-brand-600">searched</span>
                                                            </td>
                                                            <td class="px-4 py-2 font-mono text-gray-600" x-text="size.sku || '—'"></td>
                                                            {{-- The variant ID is on hover; the price is what people check. --}}
                                                            <td class="px-4 py-2 text-right whitespace-nowrap" :title="'Variant ID ' + size.variant_id">
                                                                <template x-if="size.price !== null && size.price !== undefined">
                                                                    <span>
                                                                        <span x-show="size.compare_at_price && Number(size.compare_at_price) > Number(size.price)"
                                                                              class="mr-1 text-gray-400 line-through"
                                                                              x-text="Number(size.compare_at_price).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                                                                        <span class="font-medium text-gray-800"
                                                                              x-text="(breakdown.currency ? breakdown.currency + ' ' : '') + Number(size.price).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                                                                    </span>
                                                                </template>
                                                                <template x-if="size.price === null || size.price === undefined">
                                                                    <span class="text-gray-400">—</span>
                                                                </template>
                                                            </td>
                                                            {{-- A dash, not 0, when the store would not report stock. --}}
                                                            <td class="px-4 py-2 text-right">
                                                                <span class="figure"
                                                                      :class="size.stock === null ? 'text-gray-400' : (size.stock > 0 ? 'text-gray-800' : 'text-red-500')"
                                                                      x-text="size.stock === null ? '—' : size.stock"></span>
                                                            </td>
                                                            <td class="px-4 py-2 text-right">
                                                                <span class="figure"
                                                                      :class="size.has_image ? 'text-gray-800' : 'text-red-500'"
                                                                      x-text="size.has_image ? size.image_count : 'none'"></span>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
