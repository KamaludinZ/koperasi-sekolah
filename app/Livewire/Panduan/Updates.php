<?php

namespace App\Livewire\Panduan;

use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Services\AuditLog;
use App\Services\GuideGenerator;
use App\Support\Menu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Panel pembaruan panduan otomatis (/panduan/pembaruan, pengelola panduan): ringkasan isi Pusat Panduan (otomatis vs
 * manual, disunting, usang, terakhir disusun), pratinjau perubahan dari konfigurasi menu/halaman/izin sebelum
 * diterapkan (GuideGenerator::plan, tanpa menyimpan), terapkan penyusunan ulang (versi & jejak audit ber-HMAC),
 * daftar panduan usang yang bisa ditandai sudah ditinjau, dan riwayat penyusunan terakhir.
 */
#[Layout('components.layouts.app')]
#[Title('Pembaruan Panduan')]
class Updates extends Component
{
    public const FIELD_LABELS = [
        'menu_key' => 'menu', 'audience' => 'pembaca', 'title' => 'judul', 'summary' => 'ringkasan', 'steps' => 'langkah',
        'tips' => 'tips', 'related' => 'halaman terkait', 'keywords' => 'kata kunci', 'required_permissions' => 'izin', 'position' => 'urutan',
    ];

    /** Hasil pratinjau terakhir (null = belum diperiksa). */
    public ?array $plan = null;

    /** Ringkasan penerapan terakhir di sesi ini. */
    public ?array $result = null;

    public string $note = '';

    public function mount(): void
    {
        Gate::authorize('kelola-panduan');
    }

    /** Periksa perubahan tanpa menyimpan apa pun. */
    public function check(GuideGenerator $generator): void
    {
        Gate::authorize('kelola-panduan');
        $this->plan = $generator->plan();
        $this->result = null;
    }

    /** Terapkan penyusunan ulang: buat/perbarui panduan otomatis, panduan manual tidak disentuh. */
    public function apply(GuideGenerator $generator): void
    {
        Gate::authorize('kelola-panduan');
        $this->validate(['note' => ['nullable', 'string', 'max:200']], [], ['note' => 'catatan']);
        $note = trim($this->note) !== '' ? trim($this->note) : 'Disusun ulang dari panel pembaruan';
        $stats = $generator->sync(Auth::guard('web')->user(), $note);
        $counts = array_map('count', $stats);
        AuditLog::record('guide.synced', 'panduan',
            "Panduan disusun ulang: {$counts['created']} baru, {$counts['updated']} diperbarui, {$counts['unchanged']} tetap, {$counts['skipped_manual']} manual dilewati",
            null, ['created' => $stats['created'], 'updated' => $stats['updated']] + $counts, action: 'diubah', reason: $note);
        $this->result = $counts + ['keys' => array_merge($stats['created'], $stats['updated'])];
        $this->plan = null;
        $this->note = '';
        unset($this->stats, $this->stale, $this->history, $this->titles);
    }

    /** Tandai panduan usang sudah ditinjau (penanda dilepas). */
    public function markReviewed(int $id): void
    {
        Gate::authorize('kelola-panduan');
        GuideEntry::whereKey($id)->where('is_stale', true)->update(['is_stale' => false, 'stale_reason' => null]);
        unset($this->stats, $this->stale);
    }

    /** @return array{total: int, auto: int, manual: int, overridden: int, stale: int, last: ?Carbon} */
    #[Computed]
    public function stats(): array
    {
        $q = GuideEntry::query();

        return [
            'total' => (clone $q)->count(),
            'auto' => (clone $q)->where('source', 'auto')->count(),
            'manual' => (clone $q)->where('source', 'manual')->count(),
            'overridden' => (clone $q)->whereHas('overrides')->count(),
            'stale' => (clone $q)->where('is_stale', true)->count(),
            'last' => ($last = (clone $q)->max('generated_at')) ? Carbon::parse($last) : null,
        ];
    }

    #[Computed]
    public function stale()
    {
        return GuideEntry::where('is_stale', true)->orderBy('position')->get(['id', 'key', 'menu_key', 'title', 'stale_reason', 'generated_at']);
    }

    /** Versi hasil penyusunan otomatis terbaru (20). */
    #[Computed]
    public function history()
    {
        return GuideVersion::where('change_type', 'generate')->with(['entry:id,key,title', 'author:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();
    }

    /** Judul panduan tersimpan per kunci (untuk daftar pratinjau). @return array<string, string> */
    #[Computed]
    public function titles(): array
    {
        return GuideEntry::pluck('title', 'key')->all();
    }

    public function titleOf(string $key): string
    {
        return $this->titles[$key] ?? (Menu::ITEMS[$key]['label'] ?? $key);
    }

    public function render()
    {
        return view('livewire.panduan.updates');
    }
}
