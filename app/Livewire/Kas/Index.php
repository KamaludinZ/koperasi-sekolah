<?php

namespace App\Livewire\Kas;

use App\Livewire\Concerns\NotifiesSave;
use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kas Harian: rekap uang laci kasir per hari — modal awal, penjualan tunai/non-tunai,
 * setoran & penarikan kas, kas seharusnya vs hitungan akhir, dan selisih tiap shift.
 * Kasir/admin dapat membuka shift (modal awal) dan menutupnya (hitung uang laci, selisih
 * wajib diberi catatan). Tahap frontend: data tiruan MockData::cashDay/openShift/closeShift
 * (disimpan di session, belum dari database).
 */
#[Layout('components.layouts.app')]
#[Title('Kas Harian')]
class Index extends Component
{
    use NotifiesSave;

    public const SHIFT_NAMES = ['Shift pagi', 'Shift siang', 'Shift sore'];

    /** Batas wajar uang laci (modal awal / hitungan akhir). */
    public const MAX_CASH = 50_000_000;

    /** Pecahan uang untuk alat bantu hitung laci saat tutup shift. */
    public const DENOMINATIONS = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];

    /** Alasan cepat setoran (in) / penarikan (out) kas laci. */
    public const MOVEMENT_REASONS = [
        'in' => ['Tambah uang kembalian', 'Tukar pecahan dari bendahara', 'Titipan iuran kegiatan'],
        'out' => ['Setor ke bendahara', 'Beli plastik & perlengkapan', 'Bayar galon air', 'Ongkos angkut barang'],
    ];

    /** Dialog yang terbuka: 'buka' | 'tutup' | 'kas' | null. */
    public ?string $dialog = null;

    public string $shiftName = 'Shift pagi';

    public ?int $openingCash = null;

    public ?int $countedCash = null;

    public string $closeNote = '';

    /** Setoran/penarikan kas: 'in' (uang masuk laci) | 'out' (uang keluar laci). */
    public string $movementType = 'in';

    public ?int $movementAmount = null;

    public string $movementReason = '';

    /** Tanggal yang dilihat (Y-m-d); kosong = hari ini. */
    #[Url(as: 'tanggal', except: '')]
    public string $date = '';

    public function mount(): void
    {
        $this->date = $this->normalize($this->date);
    }

    public function updatedDate(): void
    {
        $this->date = $this->normalize($this->date);
    }

    /** Geser tanggal ±1 hari, tidak melewati hari ini. */
    public function shiftDay(int $days): void
    {
        $this->date = $this->normalize($this->day->copy()->addDays($days)->toDateString());
        unset($this->day);
    }

    public function goToday(): void
    {
        $this->date = '';
    }

    public function openDialog(string $dialog): void
    {
        if (! in_array($dialog, ['buka', 'tutup', 'kas'], true) || ! $this->canOperate || ! $this->cash['is_today']) {
            return;
        }
        if ($dialog !== 'buka' && ! $this->openShiftRow) {
            $this->toast('error', 'Tidak ada shift yang sedang berjalan.');

            return;
        }
        $this->resetValidation();
        $this->dialog = $dialog;
        if ($dialog === 'buka') {
            $this->shiftName = self::SHIFT_NAMES[now()->hour < 10 ? 0 : (now()->hour < 14 ? 1 : 2)];
            $this->openingCash = 200000;
        } elseif ($dialog === 'tutup') {
            $this->countedCash = null;
            $this->closeNote = '';
        } else {
            $this->movementType = 'in';
            $this->movementAmount = null;
            $this->movementReason = '';
        }
    }

    public function closeDialog(): void
    {
        $this->dialog = null;
        $this->resetValidation();
    }

    public function openShift(): void
    {
        abort_unless($this->canOperate, 403);

        $this->saveWithToast(function () {
            $this->validate([
                'shiftName' => ['required', 'in:'.implode(',', self::SHIFT_NAMES)],
                'openingCash' => ['required', 'integer', 'min:0', 'max:'.self::MAX_CASH],
            ], [
                'openingCash.required' => 'Isi modal awal uang laci (boleh Rp0).',
                'openingCash.max' => 'Modal awal terlalu besar (maks. Rp50.000.000).',
            ]);
            if (! $this->cash['is_today'] || $this->openShiftRow) {
                throw \Illuminate\Validation\ValidationException::withMessages(['shiftName' => 'Masih ada shift berjalan. Tutup dulu sebelum membuka shift baru.']);
            }
            MockData::openShift(['name' => $this->shiftName, 'cashier' => auth()->user()->name, 'opening_cash' => $this->openingCash]);
        }, $this->shiftName.' dibuka dengan modal Rp'.number_format((int) $this->openingCash, 0, ',', '.').'.');

        $this->dialog = null;
        unset($this->cash, $this->week, $this->openShiftRow);
    }

    public function closeShift(): void
    {
        abort_unless($this->canOperate, 403);
        $shift = $this->openShiftRow;

        $this->saveWithToast(function () use ($shift) {
            $this->validate([
                'countedCash' => ['required', 'integer', 'min:0', 'max:'.self::MAX_CASH],
                'closeNote' => ['nullable', 'string', 'max:200'],
            ], [
                'countedCash.required' => 'Hitung lalu isi jumlah uang di laci.',
                'countedCash.max' => 'Jumlah uang terlalu besar (maks. Rp50.000.000).',
            ]);
            if (! $shift) {
                throw \Illuminate\Validation\ValidationException::withMessages(['countedCash' => 'Tidak ada shift yang sedang berjalan.']);
            }
            if ($this->countedCash !== $shift['expected'] && trim($this->closeNote) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages(['closeNote' => 'Ada selisih kas — tulis penyebabnya, mis. salah kembalian.']);
            }
            MockData::closeShift($shift['id'], $this->countedCash, trim($this->closeNote) ?: null);
        }, ($shift['name'] ?? 'Shift').' ditutup.');

        $this->dialog = null;
        unset($this->cash, $this->week, $this->openShiftRow);
    }

    public function setMovementType(string $type): void
    {
        if (in_array($type, ['in', 'out'], true)) {
            $this->movementType = $type;
            $this->resetValidation();
        }
    }

    /** Catat uang masuk/keluar laci di luar penjualan pada shift yang sedang berjalan. */
    public function saveMovement(): void
    {
        abort_unless($this->canOperate, 403);
        $shift = $this->openShiftRow;
        $isIn = $this->movementType === 'in';

        $this->saveWithToast(function () use ($shift, $isIn) {
            $this->validate([
                'movementType' => ['required', 'in:in,out'],
                'movementAmount' => ['required', 'integer', 'min:500', 'max:'.self::MAX_CASH],
                'movementReason' => ['required', 'string', 'min:3', 'max:150'],
            ], [
                'movementAmount.required' => 'Isi jumlah uang.',
                'movementAmount.min' => 'Jumlah minimal Rp500.',
                'movementAmount.max' => 'Jumlah terlalu besar (maks. Rp50.000.000).',
                'movementReason.required' => 'Tulis alasannya agar bisa dipertanggungjawabkan.',
                'movementReason.min' => 'Alasan terlalu singkat.',
            ]);
            if (! $shift) {
                throw \Illuminate\Validation\ValidationException::withMessages(['movementAmount' => 'Buka shift dulu sebelum mencatat uang masuk/keluar laci.']);
            }
            if (! $isIn && $this->movementAmount > $shift['expected']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['movementAmount' => 'Uang di laci tidak cukup (seharusnya Rp'.number_format($shift['expected'], 0, ',', '.').').']);
            }
            MockData::recordCashMovement($this->movementType, $this->movementAmount, trim($this->movementReason), auth()->user()->name);
        }, ($isIn ? 'Setoran' : 'Penarikan').' kas Rp'.number_format((int) $this->movementAmount, 0, ',', '.').' dicatat.');

        $this->dialog = null;
        unset($this->cash, $this->week, $this->openShiftRow);
    }

    /** Hanya petugas yang boleh membuka layar kasir (kasir & admin) yang mengelola shift. */
    #[Computed]
    public function canOperate(): bool
    {
        return (bool) auth()->user()?->canOpen('kasir');
    }

    /** Shift yang sedang berjalan hari ini, bila ada. */
    #[Computed]
    public function openShiftRow(): ?array
    {
        return collect(MockData::cashDay(today()->toDateString())['shifts'])->firstWhere('status', 'open');
    }

    #[Computed]
    public function day(): Carbon
    {
        return $this->date === '' ? today() : Carbon::parse($this->date);
    }

    #[Computed]
    public function cash(): array
    {
        return MockData::cashDay($this->day->toDateString());
    }

    /** Tren 7 hari sampai tanggal yang dilihat. */
    #[Computed]
    public function week(): array
    {
        return MockData::cashWeek($this->day->toDateString());
    }

    /** Tanggal tidak valid atau di masa depan → hari ini (string kosong). */
    private function normalize(string $date): string
    {
        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }
        try {
            $day = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            return '';
        }

        return $day->format('Y-m-d') !== $date || $day->gte(today()) ? '' : $date;
    }

    public function render()
    {
        return view('livewire.kas.index');
    }
}
