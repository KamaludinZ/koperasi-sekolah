<?php

namespace App\Livewire\Audit;

use App\Models\ActivityLog;
use App\Services\AuditLog;
use App\Services\AuditQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Audit & Riwayat (admin & pengurus): jejak login, sirkulasi uang & barang (penjualan,
 * tabungan, stok), dan perubahan data oleh semua petugas. Filter tampilan, periode,
 * kategori, petugas, dan kata kunci; ringkasan & aktivitas per petugas; rincian satu
 * aktivitas (perubahan sebelum/sesudah, IP, perangkat); unduh CSV.
 */
#[Layout('components.layouts.app')]
#[Title('Audit & Riwayat')]
class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    #[Url(as: 'tampil', except: 'semua')]
    public string $view = 'semua';

    #[Url(as: 'periode', except: '7-hari')]
    public string $period = '7-hari';

    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    #[Url(as: 'kategori', except: '')]
    public string $category = '';

    #[Url(as: 'petugas', except: '')]
    public string $actor = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $selectedId = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->validate([
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            ], ['to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
        }
        if (in_array($property, ['view', 'from', 'to', 'category', 'actor', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, AuditQuery::PERIODS)) {
            $this->period = $period;
            $this->reset('from', 'to');
            $this->resetValidation();
            $this->resetPage();
        }
    }

    public function setView(string $view): void
    {
        if (array_key_exists($view, AuditQuery::VIEWS)) {
            $this->view = $view;
            $this->category = '';
            $this->resetPage();
        }
    }

    public function filterActor(string $name): void
    {
        $this->actor = $name;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('view', 'period', 'from', 'to', 'category', 'actor', 'search');
        $this->resetValidation();
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->selectedId = $id;
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    /** @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon} */
    #[Computed]
    public function range(): array
    {
        $valid = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        if ($this->getErrorBag()->has('to')) {
            return AuditQuery::range($this->period);
        }

        return AuditQuery::range($this->period, $valid($this->from), $valid($this->to));
    }

    private function filters(): array
    {
        [$from, $to] = $this->range;

        return [
            'view' => $this->view, 'from' => $from, 'to' => $to,
            'category' => $this->category, 'actor' => $this->actor, 'search' => $this->search,
        ];
    }

    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return app(AuditQuery::class)->query($this->filters())->paginate(self::PER_PAGE, ['*'], 'page', $this->getPage());
    }

    /** Aktivitas halaman ini dikelompokkan per tanggal. */
    #[Computed]
    public function days(): array
    {
        return collect($this->logs->items())
            ->groupBy(fn (ActivityLog $l) => $l->created_at->toDateString())
            ->all();
    }

    #[Computed]
    public function summary(): array
    {
        return app(AuditQuery::class)->summary(...$this->range);
    }

    #[Computed]
    public function actors(): array
    {
        return app(AuditQuery::class)->perActor(...$this->range);
    }

    #[Computed]
    public function actorOptions(): array
    {
        return app(AuditQuery::class)->actors();
    }

    #[Computed]
    public function selected(): ?ActivityLog
    {
        return $this->selectedId ? ActivityLog::find($this->selectedId) : null;
    }

    /** Unduh aktivitas sesuai filter (maks. 20.000 baris) sebagai CSV. */
    public function export()
    {
        $filters = $this->filters();
        AuditLog::record('audit.exported', 'laporan', 'Mengunduh riwayat audit ('.AuditQuery::VIEWS[$this->view].')', null,
            ['view' => $this->view, 'category' => $this->category, 'actor' => $this->actor, 'search' => $this->search,
                'from' => $filters['from']?->toDateString(), 'to' => $filters['to']?->toDateString()]);

        $query = app(AuditQuery::class)->query($filters)->limit(20000);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Waktu', 'Petugas', 'Peran', 'Kategori', 'Aktivitas', 'Objek', 'Nominal', 'IP'], ';');
            foreach ($query->lazy(500) as $l) {
                fputcsv($out, [$l->created_at->toDateTimeString(), $l->actor_name, $l->actor_role, $l->category_label,
                    $l->description, $l->subject_label, $l->amount, $l->ip_address], ';');
            }
            fclose($out);
        }, 'riwayat-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        return view('livewire.audit.index');
    }
}
