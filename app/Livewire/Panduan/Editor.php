<?php

namespace App\Livewire\Panduan;

use App\Models\GuideChange;
use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Services\GuideOverrideService;
use App\Services\GuideScanner;
use App\Services\GuideService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Editor panduan (/panduan/sunting?panduan=kunci, pengelola panduan): menyunting judul, ringkasan, langkah (teks +
 * tautan halaman, urutan bisa diubah) dan tips tiap panduan. Suntingan disimpan per bagian di lapisan override
 * (GuideOverrideService) sehingga tidak hilang saat panduan disusun ulang; isi otomatis ditampilkan berdampingan,
 * dan tiap bagian bisa dikembalikan ke hasil otomatis. Setiap simpan/kembalikan menulis versi & jejak audit.
 */
#[Layout('components.layouts.app')]
#[Title('Sunting Panduan')]
class Editor extends Component
{
    #[Url(as: 'panduan', except: '')]
    public $key = '';

    public string $title = '';

    public string $summary = '';

    /** @var list<array{text: string, route: string}> */
    public array $steps = [];

    /** @var list<string> */
    public array $tips = [];

    public string $note = '';

    /** Sidik jari isi efektif tiap bagian saat formulir dimuat — untuk menolak simpan bila diubah orang lain. */
    #[Locked]
    public array $bases = [];

    public ?string $saved = null;

    /** Bagian yang sedang dikonfirmasi sebelum disimpan ('all' = semua yang berubah); null = tidak ada. */
    public ?string $confirming = null;

    public function mount(): void
    {
        Gate::authorize('kelola-panduan');
        $this->key = is_string($this->key) && GuideEntry::where('key', $this->key)->exists() ? $this->key : (string) GuideEntry::for('petugas')->value('key');
        $this->load();
    }

    public function updatedKey(): void
    {
        $this->key = is_string($this->key) && GuideEntry::where('key', $this->key)->exists() ? $this->key : (string) GuideEntry::for('petugas')->value('key');
        $this->saved = null;
        $this->resetValidation();
        unset($this->entry, $this->effective, $this->pageOptions, $this->versions, $this->changes);
        $this->confirming = null;
        $this->load();
    }

    /** Isi formulir dari isi efektif (otomatis + suntingan). */
    private function load(): void
    {
        $e = $this->effective;
        if (! $e) {
            return;
        }
        $this->title = $e['title'];
        $this->summary = $e['summary'];
        $this->steps = array_map(fn ($s) => ['text' => $s['text'] ?? '', 'route' => $s['route'] ?? ''], $e['steps']);
        $this->tips = array_values($e['tips']);
        $this->note = '';
        $this->bases = array_map(fn ($f) => GuideOverride::hashOf($e[$f] ?? null), array_combine(array_keys(GuideOverride::FIELDS), array_keys(GuideOverride::FIELDS)));
    }

    #[Computed]
    public function entry(): ?GuideEntry
    {
        return GuideEntry::with('overrides.editor:id,name')->where('key', $this->key)->first();
    }

    #[Computed]
    public function effective(): ?array
    {
        return $this->entry ? app(GuideService::class)->effective($this->entry) : null;
    }

    /** Pilihan panduan: kunci → judul (dikelompokkan petugas/anggota). @return array<string, array<string, string>> */
    #[Computed]
    public function options(): array
    {
        return GuideEntry::orderBy('audience')->orderBy('position')->get(['key', 'title', 'audience'])
            ->groupBy(fn ($e) => GuideEntry::AUDIENCES[$e->audience] ?? $e->audience)
            ->map(fn ($g) => $g->pluck('title', 'key')->all())->all();
    }

    /** Halaman yang bisa ditautkan dari langkah: halaman menu panduan ini (terbaca pemindai) + tautan yang sudah dipakai. */
    #[Computed]
    public function pageOptions(): array
    {
        $menu = $this->entry?->menu_key;
        $pages = $menu ? collect(app(GuideScanner::class)->scan()[$menu]['pages'] ?? [])->pluck('title', 'name')->all() : [];
        foreach ($this->effective['steps'] ?? [] as $s) {
            if (! empty($s['route']) && ! isset($pages[$s['route']])) {
                $pages[$s['route']] = $s['route'];
            }
        }

        return $pages;
    }

    #[Computed]
    public function versions()
    {
        return $this->entry ? GuideVersion::where('guide_entry_id', $this->entry->id)->with('author:id,name')->orderByDesc('version')->limit(8)->get() : collect();
    }

    /** Nilai formulir satu bagian (bentuk yang disimpan). */
    private function value(string $field): mixed
    {
        return match ($field) {
            'title' => $this->title,
            'summary' => $this->summary,
            'steps' => array_map(fn ($s) => array_filter(['text' => (string) ($s['text'] ?? ''), 'route' => ($s['route'] ?? '') ?: null]), $this->steps),
            'tips' => $this->tips,
        };
    }

    /** Bagian ini berbeda dari isi efektif tersimpan (belum disimpan)? */
    public function dirty(string $field): bool
    {
        $e = $this->effective;
        if (! $e) {
            return false;
        }

        return match ($field) {
            'steps' => array_map(fn ($s) => array_filter(['text' => trim($s['text'] ?? ''), 'route' => ($s['route'] ?? '') ?: null]), $this->steps)
                != array_map(fn ($s) => array_filter(['text' => $s['text'] ?? '', 'route' => $s['route'] ?? null]), $e['steps']),
            'tips' => array_map('trim', $this->tips) != $e['tips'],
            default => trim($this->{$field}) !== $e[$field],
        };
    }

    /** Tampilkan konfirmasi (sebelum → sesudah) sebelum menyimpan satu bagian atau semua yang berubah. */
    public function confirmSave(string $field): void
    {
        Gate::authorize('kelola-panduan');
        $this->confirming = ($field === 'all' && $this->dirtyFields) || (isset(GuideOverride::FIELDS[$field]) && $this->dirty($field)) ? $field : null;
    }

    public function cancelSave(): void
    {
        $this->confirming = null;
    }

    /** Lanjutkan penyimpanan yang sudah dikonfirmasi. */
    public function confirmed(GuideOverrideService $overrides): void
    {
        $field = $this->confirming;
        $this->confirming = null;
        if ($field === 'all') {
            $this->saveAll($overrides);
        } elseif ($field !== null) {
            $this->save($field, $overrides);
        }
    }

    /** Ringkas isi satu bagian untuk tampilan sebelum → sesudah. @return list<string> */
    public function lines(string $field, bool $form): array
    {
        $v = $form ? $this->value($field) : ($this->effective[$field] ?? null);

        return match ($field) {
            'steps' => array_values(array_map(fn ($s) => trim($s['text'] ?? '').(! empty($s['route']) ? ' → '.($this->pageOptions[$s['route']] ?? $s['route']) : ''), array_filter((array) $v, fn ($s) => trim($s['text'] ?? '') !== ''))),
            'tips' => array_values(array_filter(array_map('trim', (array) $v), 'strlen')),
            default => [trim((string) $v)],
        };
    }

    /**
     * Riwayat perubahan panduan ini per bagian (guide_changes): jenis, bagian, sebelum → sesudah utuh, catatan,
     * siapa & kapan, nomor versi yang lahir.
     */
    #[Computed]
    public function changes()
    {
        if (! $this->entry) {
            return collect();
        }

        return GuideChange::where('guide_entry_id', $this->entry->id)->with(['author:id,name', 'version:id,version'])->latest('id')->limit(20)->get();
    }

    /** Bagian yang berubah tetapi belum disimpan. @return list<string> */
    #[Computed]
    public function dirtyFields(): array
    {
        return array_values(array_filter(array_keys(GuideOverride::FIELDS), fn ($f) => $this->dirty($f)));
    }

    public function save(string $field, GuideOverrideService $overrides): void
    {
        if (isset(GuideOverride::FIELDS[$field])) {
            $this->store([$field], $overrides);
        }
    }

    /** Simpan semua bagian yang berubah sekaligus — satu versi; bila ada yang tidak sah, tidak ada yang disimpan. */
    public function saveAll(GuideOverrideService $overrides): void
    {
        $this->store($this->dirtyFields, $overrides);
    }

    /** @param  list<string>  $fields */
    private function store(array $fields, GuideOverrideService $overrides): void
    {
        Gate::authorize('kelola-panduan');
        if (! $this->entry || $fields === []) {
            return;
        }
        $this->validate(['note' => ['nullable', 'string', 'max:200']], [], ['note' => 'catatan']);
        $values = array_combine($fields, array_map(fn ($f) => $this->value($f), $fields));
        try {
            $overrides->saveMany($this->entry, $values, Auth::guard('web')->user(), trim($this->note) ?: null, array_intersect_key($this->bases, $values));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->saved = implode(', ', array_map(fn ($f) => GuideOverride::FIELDS[$f], $fields)).' disimpan sebagai suntingan.';
        $kept = array_diff_key(['title' => $this->title, 'summary' => $this->summary, 'steps' => $this->steps, 'tips' => $this->tips], $values);
        unset($this->entry, $this->effective, $this->versions, $this->dirtyFields, $this->changes);
        $this->load();
        foreach ($kept as $field => $value) { // bagian lain yang belum disimpan tetap di formulir
            $this->{$field} = $value;
        }
    }

    public function revert(string $field, GuideOverrideService $overrides): void
    {
        Gate::authorize('kelola-panduan');
        if (! isset(GuideOverride::FIELDS[$field]) || ! $this->entry) {
            return;
        }
        $overrides->revert($this->entry, $field, Auth::guard('web')->user(), trim($this->note) ?: null);
        $this->saved = GuideOverride::FIELDS[$field].' dikembalikan ke hasil otomatis.';
        unset($this->entry, $this->effective, $this->versions, $this->dirtyFields, $this->changes);
        $this->load();
    }

    /** Kembalikan semua bagian yang disunting ke hasil otomatis (versi & audit per bagian). */
    public function revertAll(GuideOverrideService $overrides): void
    {
        Gate::authorize('kelola-panduan');
        if (! $this->entry) {
            return;
        }
        $fields = $this->entry->overrides->pluck('field')->all();
        foreach ($fields as $field) {
            $overrides->revert($this->entry, $field, Auth::guard('web')->user(), trim($this->note) ?: 'Semua bagian dikembalikan ke hasil otomatis');
            $this->entry->unsetRelation('overrides');
        }
        $this->saved = $fields ? 'Semua bagian ('.implode(', ', array_map(fn ($f) => mb_strtolower(GuideOverride::FIELDS[$f]), $fields)).') dikembalikan ke hasil otomatis.' : null;
        unset($this->entry, $this->effective, $this->versions, $this->dirtyFields, $this->changes);
        $this->resetValidation();
        $this->load();
    }

    /** Batalkan perubahan yang belum disimpan pada satu bagian. */
    public function discard(string $field): void
    {
        $e = $this->effective;
        match ($field) {
            'title' => $this->title = $e['title'],
            'summary' => $this->summary = $e['summary'],
            'steps' => $this->steps = array_map(fn ($s) => ['text' => $s['text'] ?? '', 'route' => $s['route'] ?? ''], $e['steps']),
            'tips' => $this->tips = array_values($e['tips']),
            default => null,
        };
        $this->resetValidation($field);
    }

    public function addStep(): void
    {
        if (count($this->steps) < GuideService::MAX['steps']) {
            $this->steps[] = ['text' => '', 'route' => ''];
        }
    }

    public function removeStep(int $i): void
    {
        unset($this->steps[$i]);
        $this->steps = array_values($this->steps);
    }

    public function moveStep(int $i, int $dir): void
    {
        $j = $i + $dir;
        if (isset($this->steps[$i], $this->steps[$j])) {
            [$this->steps[$i], $this->steps[$j]] = [$this->steps[$j], $this->steps[$i]];
        }
    }

    public function addTip(): void
    {
        if (count($this->tips) < GuideService::MAX['tips']) {
            $this->tips[] = '';
        }
    }

    public function removeTip(int $i): void
    {
        unset($this->tips[$i]);
        $this->tips = array_values($this->tips);
    }

    public function render()
    {
        return view('livewire.panduan.editor', [
            'auto' => $this->entry?->toGuide(),
            'overrides' => $this->entry ? $this->entry->overrides->keyBy('field') : collect(),
        ]);
    }
}
