@props(['method'])
<span {{ $attributes->class(['rounded px-1.5 py-0.5 text-[11px] font-medium',
    'bg-stone-100 text-stone-700' => $method === 'tunai',
    'bg-sky-100 text-sky-800' => $method !== 'tunai']) }}>{{ \App\Services\CheckoutService::PAYMENT_METHODS[$method] ?? $method }}</span>
