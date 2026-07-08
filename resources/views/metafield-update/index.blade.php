@extends('layouts.app')

@section('title', 'Product Migration - Metafield')
@section('page-title', 'Product Migration - Metafield')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-semibold text-gray-800">Upload CSV to Update Metafields</h2>
            <p class="text-sm text-gray-500 mt-0.5">Updates <strong>Material</strong> and <strong>Features</strong> metafields in Shopify based on Variant SKU. Only SKUs in the CSV will be processed.</p>
        </div>

        <form method="POST" action="{{ route('metafield-update.upload') }}" enctype="multipart/form-data">
            @csrf
            <div class="px-6 py-5 space-y-4">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">CSV File</label>
                    <input type="file" name="csv_file" accept=".csv,.txt" required
                        class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer">
                    <p class="text-xs text-gray-400 mt-1">Required columns: <code class="bg-gray-100 px-1 rounded">Variant SKU</code>, <code class="bg-gray-100 px-1 rounded">MATERIAL</code>, <code class="bg-gray-100 px-1 rounded">FEATURES</code></p>
                </div>

                <div class="bg-gray-50 rounded-lg p-4 text-xs text-gray-500 space-y-1">
                    <p class="font-medium text-gray-600">CSV Format Example:</p>
                    <code class="block text-xs">Variant SKU,MATERIAL,FEATURES</code>
                    <code class="block text-xs">SFR207BAG00391,NYLON,</code>
                    <code class="block text-xs">PDN207LUG00070,POLYCARBONATE,360° ROTATING WHEELS</code>
                    <p class="text-gray-400 mt-2">• Leave MATERIAL or FEATURES empty to skip that field<br>• Multiple features: separate with comma</p>
                </div>

            </div>

            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                <button type="submit"
                    class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    Upload & Update
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
