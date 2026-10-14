@props(['method'])
<span {{ $attributes->class(['rounded px-1.5 py-0.5 text-[11px] font-medium',
    'bg-stone-100 text-stone-700' => $method === 'tunai',
    'bg-sky-100 text-sky-800' => $method === 'qris',
    'bg-emerald-100 text-emerald-800' => $method === 'tabungan',
    'bg-teal-50 text-teal-800' => $method === 'simpanan',
    'bg-amber-100 text-amber-900' => $method === 'bon',
    'bg-stone-100 text-stone-600' => ! in_array($method, ['tunai', 'qris', 'tabungan', 'simpanan', 'bon'], true)]) }}>{{ \App\Services\CheckoutService::PAYMENT_LABELS[$method] ?? $method }}</span>
