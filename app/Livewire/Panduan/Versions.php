<?php

namespace App\Livewire\Panduan;

use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Services\AuditIntegrity;
use App\Services\GuideOverrideService;
use App\Services\GuideService;
use App\Support\GuideVersionDiff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /** Perbandingan dua versi panduan terpilih: nomor versi lama (dari) & baru (ke). */
    #[Url(as: 'dari', except: 0)]
    public $from = 0;

    #[Url(as: 'ke', except: 0)]
    public $to = 0;

    public function mount(): void
    {
        Gate::authorize('lihat-riwayat-panduan');
        $this->normalize();
    }

    public function updated(string $property): void
    {
        $this->normalize();
        if ($property === 'key') {
            $this->from = $this->to = 0;
        }
        if (in_array($property, ['key', 'type'], true)) {
            $this->resetPage();
        }
    }

    private function normalize(): void
    {
        $this->key = is_string($this->key) && GuideEntry::where('key', $this->key)->exists() ? $this->key : '';
        $this->type = is_string($this->type) && isset(GuideVersion::TYPES[$this->type]) ? $this->type : '';
        $this->from = filter_var($this->from, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $this->to = filter_var($this->to, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($this->key === '') {
            $this->from = $this->to = 0;
        }
    }

    public const AUDIT_EVENTS = ['guide.overridden' => 'Disunting', 'guide.reverted' => 'Dikembalikan ke otomatis', 'guide.restored' => 'Dipulihkan', 'guide.draft_applied' => 'Draf diterapkan'];

    /**
     * Jejak audit (activity_logs ber-HMAC) tiap versi di halaman ini, dalam satu kueri: catatan suntingan/pengembalian
     * (properti version), pemulihan (properti new_version) dan penerapan draf (catatan versi "Draf #n"), beserta status
     * keutuhan checksum. Versi hasil sinkronisasi menunjuk ke proses sinkronisasinya.
     *
     * @return array<string, list<array{event: string, label: string, by: ?string, at: ?string, reason: ?string, valid: bool}>> "entry_id:version" → catatan
     */
    #[Computed]
    public function audits(): array
    {
        $items = $this->versions->getCollection();
        if ($items->isEmpty()) {
            return [];
        }
        // baris mentah sekali ambil: checksum dihitung ulang dari isinya sendiri (tanpa kueri per catatan)
        $logs = DB::table('activity_logs')->where('subject_type', 'guide')->whereIn('subject_id', $items->pluck('guide_entry_id')->unique())
            ->whereIn('event', array_keys(self::AUDIT_EVENTS))->orderBy('id')->get();
        $out = [];
        foreach ($logs as $log) {
            $p = (array) json_decode((string) $log->properties, true);
            $version = $p['new_version'] ?? $p['version'] ?? null;
            if ($log->event === 'guide.draft_applied') {
                $version = $items->first(fn ($v) => $v->guide_entry_id === (int) $log->subject_id && str_contains((string) $v->change_note, 'Draf #'.($p['draft_id'] ?? '?')))?->version;
            }
            if ($version === null) {
                continue;
            }
            $out[$log->subject_id.':'.$version][] = [
                'event' => $log->event, 'label' => self::AUDIT_EVENTS[$log->event], 'by' => $log->actor_name,
                'at' => Carbon::parse($log->created_at)->translatedFormat('j M Y H:i'), 'reason' => $log->reason, 'field' => $p['field'] ?? null,
                'valid' => $log->checksum !== null && hash_equals($log->checksum, AuditIntegrity::checksum($log)),
            ];
        }

        return $out;
    }

    /** Versi yang sedang dikonfirmasi untuk dipulihkan (null = dialog tertutup) & alasannya. */
    public ?int $restoring = null;

    public string $reason = '';

    public function startRestore(int $version): void
    {
        Gate::authorize('kelola-panduan');
        $this->restoring = $this->entry && GuideVersion::where('guide_entry_id', $this->entry->id)->where('version', $version)->exists() ? $version : null;
        $this->reason = '';
        $this->resetValidation();
    }

    public function cancelRestore(): void
    {
        $this->restoring = null;
        $this->reason = '';
        $this->resetValidation();
    }

    /** Pratinjau pemulihan: isi sekarang dibanding isi versi yang akan dipulihkan. @return ?array */
    #[Computed]
    public function restorePreview(): ?array
    {
        if (! $this->restoring || ! $this->entry) {
            return null;
        }
        $target = GuideVersion::where('guide_entry_id', $this->entry->id)->where('version', $this->restoring)->first();

        return $target ? ['target' => $target, 'diff' => GuideVersionDiff::compare(app(GuideService::class)->snapshot($this->entry->load('overrides')), $target->snapshot)] : null;
    }

    public function confirmRestore(GuideOverrideService $overrides): void
    {
        Gate::authorize('kelola-panduan');
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], ['reason.required' => 'Tulis alasan pemulihan.', 'reason.min' => 'Alasan minimal 5 karakter.']);
        $target = $this->entry ? GuideVersion::where('guide_entry_id', $this->entry->id)->where('version', $this->restoring)->first() : null;
        if (! $target) {
            $this->cancelRestore();

            return;
        }
        $r = $overrides->restore($this->entry, $target, Auth::guard('web')->user(), $this->reason);
        $this->dispatch('toast', type: 'success', message: $r['version'] ? "Panduan dipulihkan ke isi versi {$target->version} (tercatat sebagai versi {$r['version']->version})." : 'Isi panduan sudah sama dengan versi itu.');
        $this->cancelRestore();
        unset($this->versions, $this->active, $this->typeCounts, $this->previous, $this->entry, $this->audits);
    }

    /** Bandingkan versi ini dengan versi sebelumnya. */
    public function compareWithPrevious(int $version): void
    {
        if ($version > 1) {
            [$this->from, $this->to] = [$version - 1, $version];
        }
    }

    /** Pilih versi sebagai sisi lama (A) atau baru (B) perbandingan. */
    public function pick(string $side, int $version): void
    {
        $side === 'dari' ? $this->from = $version : $this->to = $version;
        if ($this->from && $this->to && $this->from > $this->to) {
            [$this->from, $this->to] = [$this->to, $this->from];
        }
    }

    public function closeCompare(): void
    {
        $this->from = $this->to = 0;
    }

    /**
     * Perbandingan dua versi terpilih (null bila belum lengkap/tidak valid).
     *
     * @return ?array{a: GuideVersion, b: GuideVersion, diff: array}
     */
    #[Computed]
    public function comparison(): ?array
    {
        if (! $this->entry || ! $this->from || ! $this->to || $this->from === $this->to) {
            return null;
        }
        $vs = GuideVersion::where('guide_entry_id', $this->entry->id)->whereIn('version', [$this->from, $this->to])->with('author:id,name')->get()->keyBy('version');
        if (! isset($vs[$this->from], $vs[$this->to])) {
            return null;
        }

        return ['a' => $vs[$this->from], 'b' => $vs[$this->to], 'diff' => GuideVersionDiff::compare($vs[$this->from]->snapshot, $vs[$this->to]->snapshot)];
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

    /**
     * Versi aktif tiap panduan di halaman ini: versi yang cuplikannya sama dengan isi yang sedang tampil (biasanya
     * versi terbaru). null bila isi sekarang tidak sama dengan versi mana pun (mis. diubah di luar alur versi).
     *
     * @return array<int, ?int> entry_id → nomor versi aktif
     */
    #[Computed]
    public function active(): array
    {
        $ids = $this->entry ? [$this->entry->id] : $this->versions->getCollection()->pluck('guide_entry_id')->unique()->values()->all();
        if ($ids === []) {
            return [];
        }
        $guides = app(GuideService::class);
        $out = [];
        foreach (GuideEntry::with('overrides')->whereKey($ids)->get() as $e) {
            $current = $guides->snapshot($e);
            $out[$e->id] = null;
            foreach (GuideVersion::where('guide_entry_id', $e->id)->orderByDesc('version')->limit(50)->get(['version', 'snapshot']) as $v) {
                if ($v->snapshot == $current) {
                    $out[$e->id] = $v->version;
                    break;
                }
            }
        }

        return $out;
    }

    public function render()
    {
        return view('livewire.panduan.versions');
    }
}
