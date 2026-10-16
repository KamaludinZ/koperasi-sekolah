<?php

namespace App\Livewire\Panduan;

use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Riwayat versi panduan (/panduan/versi, pengelola panduan & pemegang menu Audit): tanpa pilihan → versi terbaru semua
 * panduan; dengan ?panduan= → daftar versi satu panduan dari waktu ke waktu (nomor, jenis perubahan, catatan, pengubah,
 * waktu, proses sinkronisasi) beserta bagian yang berubah dibanding versi sebelumnya. Saring per jenis perubahan.
 */
#[Layout('components.layouts.app')]
#[Title('Riwayat Versi Panduan')]
class Versions extends Component
{
    use WithPagination;

    #[Url(as: 'panduan', except: '')]
    public $key = '';

    #[Url(as: 'jenis', except: '')]
    public $type = '';

    public function mount(): void
    {
        Gate::authorize('lihat-riwayat-panduan');
        $this->normalize();
    }

    public function updated(string $property): void
    {
        $this->normalize();
        if (in_array($property, ['key', 'type'], true)) {
            $this->resetPage();
        }
    }

    private function normalize(): void
    {
        $this->key = is_string($this->key) && GuideEntry::where('key', $this->key)->exists() ? $this->key : '';
        $this->type = is_string($this->type) && isset(GuideVersion::TYPES[$this->type]) ? $this->type : '';
    }

    #[Computed]
    public function entry(): ?GuideEntry
    {
        return $this->key !== '' ? GuideEntry::where('key', $this->key)->first() : null;
    }

    /** @return array<string, array<string, string>> */
    #[Computed]
    public function options(): array
    {
        return GuideEntry::orderBy('audience')->orderBy('position')->get(['key', 'title', 'audience'])
            ->groupBy(fn ($e) => GuideEntry::AUDIENCES[$e->audience] ?? $e->audience)
            ->map(fn ($g) => $g->pluck('title', 'key')->all())->all();
    }

    #[Computed]
    public function versions()
    {
        return GuideVersion::query()->with(['author:id,name', 'entry:id,key,title'])
            ->when($this->entry, fn ($q) => $q->where('guide_entry_id', $this->entry->id))
            ->when($this->type !== '', fn ($q) => $q->where('change_type', $this->type))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
    }

    /** Jumlah versi per jenis (untuk saringan). @return array<string, int> */
    #[Computed]
    public function typeCounts(): array
    {
        $counts = GuideVersion::query()->when($this->entry, fn ($q) => $q->where('guide_entry_id', $this->entry->id))
            ->selectRaw('change_type, count(*) as n')->groupBy('change_type')->pluck('n', 'change_type');

        return array_map(fn ($t) => (int) ($counts[$t] ?? 0), array_combine(array_keys(GuideVersion::TYPES), array_keys(GuideVersion::TYPES)));
    }

    /**
     * Cuplikan versi sebelumnya untuk versi di halaman ini, dalam satu kueri (nomor versi berurutan per panduan).
     *
     * @return array<string, array> "entry_id:version" → cuplikan
     */
    #[Computed]
    public function previous(): array
    {
        $items = $this->versions->getCollection();
        if ($items->isEmpty()) {
            return [];
        }

        return GuideVersion::query()->whereIn('guide_entry_id', $items->pluck('guide_entry_id')->unique())
            ->whereIn('version', $items->pluck('version')->map(fn ($n) => $n - 1)->filter()->unique())
            ->get(['guide_entry_id', 'version', 'snapshot'])
            ->mapWithKeys(fn ($v) => [$v->guide_entry_id.':'.($v->version + 1) => $v->snapshot])->all();
    }

    /**
     * Bagian yang berubah dibanding versi sebelumnya panduan yang sama.
     *
     * @return ?list<string> null untuk versi pertama
     */
    public function changedFields(GuideVersion $v): ?array
    {
        $before = $this->previous[$v->guide_entry_id.':'.$v->version] ?? null;
        if ($before === null) {
            return null;
        }
        $labels = GuideOverride::FIELDS + ['overridden' => 'Penanda suntingan'];

        return array_values(array_map(fn ($f) => $labels[$f], array_filter(array_keys($labels), fn ($f) => ($before[$f] ?? null) != ($v->snapshot[$f] ?? null))));
    }

    public function render()
    {
        return view('livewire.panduan.versions');
    }
}
