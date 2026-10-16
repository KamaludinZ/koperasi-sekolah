<?php

namespace App\Livewire\Panduan;

use App\Models\GuideEntry;
use App\Models\GuideSyncJob;
use App\Models\GuideVersion;
use App\Models\Role;
use App\Services\AuditLog;
use App\Services\GuideGenerator;
use App\Services\GuideService;
use App\Services\GuideSyncRecorder;
use App\Support\Menu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Panel pembaruan panduan otomatis (/panduan/pembaruan, pengelola panduan): ringkasan isi Pusat Panduan (otomatis vs
 * manual, disunting, usang, terakhir disusun), pratinjau perubahan dari konfigurasi menu/halaman/izin sebelum
 * diterapkan (GuideGenerator::plan, tanpa menyimpan), terapkan penyusunan ulang (versi & jejak audit ber-HMAC),
 * daftar panduan usang yang bisa ditandai sudah ditinjau, dan riwayat penyusunan terakhir. Tab "Hasil pemindaian"
 * (?tab=pemindaian) menampilkan bahan panduan yang terbaca dari sistem: menu → halaman (alamat, khusus peran, izin
 * aksi), matriks peran × menu, cakupan panduan per menu (halaman yang belum disebut), dan peringatan.
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

    #[Url(as: 'tab', except: 'ringkasan')]
    public $tab = 'ringkasan';

    public const TABS = ['ringkasan' => 'Ringkasan & penerapan', 'pemindaian' => 'Hasil pemindaian', 'draf' => 'Pratinjau draf', 'usang' => 'Panduan usang', 'riwayat' => 'Riwayat sinkronisasi'];

    /** Form tandai perlu ditinjau (tab usang). */
    public string $staleKey = '';

    public string $staleReason = '';

    /** Saring riwayat sinkronisasi per status (kosong = semua). */
    #[Url(as: 'status', except: '')]
    public $runStatus = '';

    /** Panduan yang dipratinjau di tab draf. */
    #[Url(as: 'panduan', except: '')]
    public $draftKey = '';

    /** Ringkasan penerapan satu draf terakhir. */
    public ?string $draftApplied = null;

    public function mount(): void
    {
        Gate::authorize('kelola-panduan');
        $this->normalize();
    }

    public function updated(string $property): void
    {
        $this->normalize();
    }

    private function normalize(): void
    {
        $this->tab = is_string($this->tab) && isset(self::TABS[$this->tab]) ? $this->tab : 'ringkasan';
        $this->runStatus = is_string($this->runStatus) && isset(GuideSyncJob::STATUSES[$this->runStatus]) ? $this->runStatus : '';
        if ($this->tab !== 'draf') { // pemindaian draf hanya saat tab dibuka
            $this->draftKey = is_string($this->draftKey) ? mb_substr($this->draftKey, 0, 60) : '';

            return;
        }
        $keys = array_keys($this->draftOptions);
        $this->draftKey = is_string($this->draftKey) && in_array($this->draftKey, $keys, true) ? $this->draftKey : ($keys[0] ?? '');
    }

    public function updatedDraftKey(): void
    {
        $this->draftApplied = null;
    }

    /** Pilihan panduan untuk pratinjau draf: kunci → judul draf. @return array<string, string> */
    #[Computed]
    public function draftOptions(): array
    {
        return array_map(fn ($d) => ($d['menu'] ? (Menu::ITEMS[$d['menu']]['label'] ?? $d['menu']).' — ' : '').$d['title'], app(GuideGenerator::class)->drafts());
    }

    #[Computed]
    public function draftPreview(): ?array
    {
        return $this->draftKey !== '' ? app(GuideGenerator::class)->preview($this->draftKey) : null;
    }

    /** Terapkan draf panduan yang sedang dipratinjau saja (panduan manual tetap dilewati). */
    public function applyDraft(GuideSyncRecorder $recorder): void
    {
        Gate::authorize('kelola-panduan');
        $key = $this->draftKey;
        if ($key === '' || ! isset($this->draftOptions[$key])) {
            return;
        }
        ['stats' => $stats] = $recorder->run('manual', Auth::guard('web')->user(), 'Draf panduan diterapkan dari pratinjau', [$key]);
        $done = $stats['created'] ? 'dibuat' : ($stats['updated'] ? 'diperbarui' : ($stats['skipped_manual'] ? 'dilewati (manual)' : 'sudah sesuai'));
        if ($stats['created'] || $stats['updated']) {
            AuditLog::record('guide.synced', 'panduan', "Draf panduan {$key} diterapkan: {$done}", null,
                ['only' => $key, 'created' => $stats['created'], 'updated' => $stats['updated']], action: 'diubah', reason: 'Diterapkan dari pratinjau draf');
        }
        $this->draftApplied = $done;
        unset($this->draftPreview, $this->stats, $this->history, $this->titles, $this->runs, $this->lastRun);
    }

    /**
     * Hasil pemindaian menu, halaman & izin beserta cakupan panduan dan peringatan (dibaca hanya saat tab dibuka).
     *
     * @return array{menus: array<string, array>, roles: list<string>, totals: array<string, int>, warnings: list<array{menu: string, text: string}>}
     */
    #[Computed]
    public function scan(): array
    {
        $facts = app(GuideGenerator::class)->scan();
        $guides = app(GuideService::class)->all('petugas');
        $warnings = [];
        $totals = ['menus' => count($facts), 'pages' => 0, 'role_pages' => 0, 'gated_pages' => 0, 'skipped' => 0, 'uncovered' => 0];
        foreach ($facts as $key => &$f) {
            $g = $guides[$key] ?? null;
            $covered = array_flip(array_filter([$f['route'], ...array_column($g['steps'] ?? [], 'route'), ...array_keys($g['related'] ?? [])]));
            $f['guide'] = $g ? $g['title'] : null;
            $f['uncovered'] = array_values(array_filter($f['pages'], fn ($p) => ! isset($covered[$p['name']])));
            $totals['pages'] += count($f['pages']);
            $totals['role_pages'] += count(array_filter($f['pages'], fn ($p) => $p['roles'] !== []));
            $totals['gated_pages'] += count(array_filter($f['pages'], fn ($p) => $p['ability'] !== null));
            $totals['skipped'] += $f['skipped_pages'];
            $totals['uncovered'] += count($f['uncovered']);
            if ($f['roles'] === []) {
                $warnings[] = ['menu' => $key, 'text' => "Menu {$f['label']} belum diberikan ke peran mana pun."];
            }
            if ($f['pages'] === []) {
                $warnings[] = ['menu' => $key, 'text' => "Menu {$f['label']} tidak punya halaman yang terbaca dari rute."];
            }
            if (! $g) {
                $warnings[] = ['menu' => $key, 'text' => "Menu {$f['label']} belum punya panduan — jalankan penyusunan ulang."];
            } elseif ($f['uncovered']) {
                $warnings[] = ['menu' => $key, 'text' => count($f['uncovered'])." halaman menu {$f['label']} belum disebut di panduannya."];
            }
        }
        unset($f);

        return ['menus' => $facts, 'roles' => Role::sortNames(array_keys(Role::LABELS)), 'totals' => $totals, 'warnings' => $warnings];
    }

    /** Periksa perubahan tanpa menyimpan apa pun. */
    public function check(GuideGenerator $generator): void
    {
        Gate::authorize('kelola-panduan');
        $this->plan = $generator->plan();
        $this->result = null;
    }

    /** Terapkan penyusunan ulang: buat/perbarui panduan otomatis, panduan manual tidak disentuh. */
    public function apply(GuideSyncRecorder $recorder): void
    {
        Gate::authorize('kelola-panduan');
        $this->validate(['note' => ['nullable', 'string', 'max:200']], [], ['note' => 'catatan']);
        $note = trim($this->note) !== '' ? trim($this->note) : 'Disusun ulang dari panel pembaruan';
        ['stats' => $stats] = $recorder->run('manual', Auth::guard('web')->user(), $note);
        $counts = array_map('count', $stats);
        AuditLog::record('guide.synced', 'panduan',
            "Panduan disusun ulang: {$counts['created']} baru, {$counts['updated']} diperbarui, {$counts['unchanged']} tetap, {$counts['skipped_manual']} manual dilewati",
            null, ['created' => $stats['created'], 'updated' => $stats['updated']] + $counts, action: 'diubah', reason: $note);
        $this->result = $counts + ['keys' => array_merge($stats['created'], $stats['updated'])];
        $this->plan = null;
        $this->note = '';
        unset($this->stats, $this->stale, $this->history, $this->titles, $this->runs, $this->lastRun);
    }

    /** Tandai panduan usang sudah ditinjau (penanda dilepas, tercatat di audit). */
    public function markReviewed(int $id): void
    {
        Gate::authorize('kelola-panduan');
        if ($entry = GuideEntry::find($id)) {
            app(GuideService::class)->markReviewed($entry, Auth::guard('web')->user());
        }
        unset($this->stats, $this->stale, $this->staleDetails);
    }

    /** Lepas semua penanda usang sekaligus. */
    public function markAllReviewed(): void
    {
        Gate::authorize('kelola-panduan');
        GuideEntry::where('is_stale', true)->get()->each(fn ($e) => app(GuideService::class)->markReviewed($e, Auth::guard('web')->user(), 'Ditinjau bersamaan'));
        unset($this->stats, $this->stale, $this->staleDetails);
    }

    /** Tandai satu panduan perlu ditinjau ulang dengan alasan. */
    public function markStale(): void
    {
        Gate::authorize('kelola-panduan');
        $this->validate(['staleKey' => ['required', 'string', 'exists:guide_entries,key'], 'staleReason' => ['required', 'string', 'min:5', 'max:200']],
            [], ['staleKey' => 'panduan', 'staleReason' => 'alasan']);
        app(GuideService::class)->markStale(GuideEntry::where('key', $this->staleKey)->firstOrFail(), $this->staleReason, Auth::guard('web')->user());
        $this->reset('staleKey', 'staleReason');
        unset($this->stats, $this->stale, $this->staleDetails);
    }

    /**
     * Panduan usang beserta apa yang akan diubah draf terbaru (dari GuideGenerator::plan) — dibaca saat tab dibuka.
     *
     * @return list<array{entry: GuideEntry, changes: ?list<string>, manual: bool}>
     */
    #[Computed]
    public function staleDetails(): array
    {
        $rows = GuideEntry::where('is_stale', true)->withCount('overrides')->orderBy('stale_at')->orderBy('position')->get();
        $plan = $rows->isNotEmpty() ? app(GuideGenerator::class)->plan() : ['updated' => [], 'skipped_manual' => []];

        return $rows->map(fn ($e) => ['entry' => $e, 'changes' => $plan['updated'][$e->key] ?? null, 'manual' => $e->source === 'manual'])->all();
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
        return GuideEntry::where('is_stale', true)->orderBy('position')->get(['id', 'key', 'menu_key', 'title', 'stale_reason', 'stale_at', 'generated_at']);
    }

    /** Proses sinkronisasi terakhir (status terkini di ringkasan). */
    #[Computed]
    public function lastRun(): ?GuideSyncJob
    {
        return GuideSyncJob::with('user:id,name')->latest('id')->first();
    }

    /** Riwayat sinkronisasi (50 terbaru, bisa disaring status) beserta jumlah per status. */
    #[Computed]
    public function runs(): array
    {
        $rows = GuideSyncJob::with('user:id,name')->when($this->runStatus !== '', fn ($q) => $q->where('status', $this->runStatus))
            ->latest('id')->limit(50)->get();
        $counts = GuideSyncJob::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return ['rows' => $rows, 'counts' => $counts];
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
