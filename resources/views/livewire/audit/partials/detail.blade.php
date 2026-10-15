{{-- Panel geser: rincian satu aktivitas audit --}}
@if ($log = $this->selected)
    @php
        $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
        $p = $log->properties ?? [];
        $labels = [
            'code' => 'Kode', 'name' => 'Nama', 'category_id' => 'Kategori', 'price' => 'Harga jual', 'cost_price' => 'Harga modal',
            'min_stock' => 'Batas minimum', 'unit' => 'Satuan', 'is_active' => 'Aktif', 'member_code' => 'Kode anggota', 'type' => 'Jenis',
            'class' => 'Kelas', 'phone' => 'No. HP', 'email' => 'Email', 'role' => 'Peran',
            'opening_cash' => 'Modal awal', 'expected_cash' => 'Kas seharusnya', 'counted_cash' => 'Uang dihitung',
            'difference' => 'Selisih', 'note' => 'Catatan', 'reason' => 'Alasan', 'category' => 'Kategori',
            'flagged' => 'Selisih besar', 'ability' => 'Aksi', 'shift_owner_id' => 'Pembuka shift (id)',
        ];
        $show = fn ($v) => is_bool($v) ? ($v ? 'Ya' : 'Tidak') : ($v === null || $v === '' ? '—' : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v));
        $other = collect($p)->except(['changes', 'items']);
        $subjectUrl = match ($log->subject_type) { // hanya bila petugas boleh membuka halaman tujuannya
            'transaction' => $log->subject_id && \App\Support\Menu::canReach('transaksi.show') ? route('transaksi.show', $log->subject_id) : null,
            'member' => $log->subject_id && \App\Support\Menu::canReach('anggota.show') && \App\Models\Member::whereKey($log->subject_id)->exists() ? route('anggota.show', $log->subject_id) : null,
            'product_import' => $log->subject_id && \App\Support\Menu::canReach('barang.impor.detail') && \App\Models\ProductImport::whereKey($log->subject_id)->exists() ? route('barang.impor.detail', $log->subject_id) : null,
            default => null,
        };
    @endphp
    <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="audit-detail-title"
         x-data x-on:keydown.escape.window="$wire.closeDetail()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="closeDetail"></div>
        <div class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl">
            <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                <div>
                    <p class="text-xs text-stone-500">{{ $log->category_label }} · <span class="font-mono">{{ $log->event }}</span></p>
                    <h2 id="audit-detail-title" class="mt-0.5 font-semibold">{{ $log->description }}</h2>
                </div>
                <button type="button" wire:click="closeDetail" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 text-sm">
                <dl class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2">
                    <dt class="text-stone-500">Waktu</dt><dd class="tabular-nums">{{ $log->created_at->translatedFormat('l, d F Y · H:i:s') }}</dd>
                    <dt class="text-stone-500">Petugas</dt><dd>{{ $log->actor_name ?? 'Sistem' }}@if ($log->actor_role) <span class="text-stone-500">· {{ \App\Models\Role::LABELS[$log->actor_role] ?? $log->actor_role }}</span>@endif</dd>
                    @if ($log->subject_label)
                        <dt class="text-stone-500">Objek</dt>
                        <dd>@if ($subjectUrl)<a href="{{ $subjectUrl }}" wire:navigate class="font-medium text-emerald-800 hover:underline">{{ $log->subject_label }}</a>@else{{ $log->subject_label }}@endif</dd>
                    @endif
                    @if ($log->amount !== null)<dt class="text-stone-500">Nominal</dt><dd class="font-semibold tabular-nums">{{ $rp($log->amount) }}</dd>@endif
                    <dt class="text-stone-500">Alamat IP</dt><dd class="font-mono text-xs">{{ $log->ip_address ?? '—' }}</dd>
                    <dt class="text-stone-500">Perangkat</dt><dd class="text-xs break-words text-stone-600">{{ $log->user_agent ?: '—' }}</dd>
                </dl>

                @if (! empty($p['changes']))
                    <section>
                        <h3 class="mb-2 font-semibold">Perubahan</h3>
                        <table class="w-full overflow-hidden rounded-lg border border-stone-200 text-xs">
                            <thead class="bg-stone-50 text-left text-stone-500"><tr><th class="px-3 py-1.5">Kolom</th><th class="px-3 py-1.5">Sebelum</th><th class="px-3 py-1.5">Sesudah</th></tr></thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($p['changes'] as $field => $c)
                                    <tr>
                                        <td class="px-3 py-1.5 font-medium">{{ $labels[$field] ?? (is_numeric($field) ? 'Barang #'.$field : $field) }}</td>
                                        <td class="px-3 py-1.5 text-red-700 line-through decoration-red-300">{{ $show($c['from'] ?? null) }}</td>
                                        <td class="px-3 py-1.5 font-medium text-emerald-800">{{ $show($c['to'] ?? null) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endif

                @if (! empty($p['items']))
                    <section>
                        <h3 class="mb-2 font-semibold">Barang</h3>
                        <ul class="divide-y divide-stone-100 rounded-lg border border-stone-200">
                            @foreach ($p['items'] as $item)
                                <li class="flex justify-between gap-3 px-3 py-1.5 text-xs"><span>{{ $item['qty'] }} × {{ $item['name'] }}</span><span class="tabular-nums">{{ $rp($item['subtotal']) }}</span></li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($other->isNotEmpty())
                    <section>
                        <h3 class="mb-2 font-semibold">Keterangan</h3>
                        <dl class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1 text-xs">
                            @foreach ($other as $key => $value)
                                <dt class="text-stone-500">{{ $labels[$key] ?? str_replace('_', ' ', $key) }}</dt>
                                <dd class="break-words">{{ $show($value) }}</dd>
                            @endforeach
                        </dl>
                    </section>
                @endif

                <p class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-500">Catatan audit bersifat permanen dan tidak dapat diubah atau dihapus oleh siapa pun.</p>
            </div>
        </div>
    </div>
@endif
