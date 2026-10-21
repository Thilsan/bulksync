@if($status === 'sent')
    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">Sent</span>
@elseif($status === 'failed')
    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">Failed</span>
@else
    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">Sending</span>
@endif
