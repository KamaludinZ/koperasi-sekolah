<?php

namespace App\Livewire\Kas;

use App\Http\Controllers\Kas\ShiftController;
use App\Livewire\Concerns\NotifiesSave;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Services\CashMovementService;
use App\Services\CashReportService;
use App\Services\ShiftService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kas Harian: rekap uang laci kasir per hari — modal awal, penjualan tunai/non-tunai,
 * setoran & penarikan kas, kas seharusnya vs hitungan akhir, dan selisih tiap shift
 * (CashReportService). Kasir/admin membuka shift (modal awal), mencatat uang masuk/keluar
 * laci, dan menutup shift (hitung uang laci, selisih wajib diberi catatan) lewat
 * ShiftService & CashMovementService; menutup/mencatat hanya kasir pembuka shift atau admin.
 */
#[Layout('components.layouts.app')]
#[Title('Kas Harian')]
class Index extends Component
{
    use NotifiesSave;

    public const SHIFT_NAMES = CashShift::NAMES;

    /** Batas wajar uang laci (modal awal / hitungan akhir). */
    public const MAX_CASH = ShiftService::MAX_CASH;

    /** Pecahan uang untuk alat bantu hitung laci saat tutup shift. */
    public const DENOMINATIONS = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];

    /** Alasan cepat setoran (in) / penarikan (out) kas laci, beserta kategorinya. */
    public const MOVEMENT_REASONS = [
        'in' => ['Tambah uang kembalian' => 'kembalian', 'Tukar pecahan dari bendahara' => 'bendahara', 'Titipan iuran kegiatan' => 'lainnya'],
        'out' => ['Setor ke bendahara' => 'bendahara', 'Beli plastik & perlengkapan' => 'operasional', 'Bayar galon air' => 'operasional', 'Ongkos angkut barang' => 'operasional'],
    ];

    /** Dialog yang terbuka: 'buka' | 'tutup' | 'kas' | null. */
    public ?string $dialog = null;

    public string $shiftName = 'Shift pagi';

    public ?int $openingCash = null;

    public ?int $countedCash = null;

    public string $closeNote = '';

    /** Setoran/penarikan kas: 'in' (uang masuk laci) | 'out' (uang keluar laci). */
    public string $movementType = 'in';

    public string $movementCategory = 'kembalian';

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
        if ($dialog !== 'buka' && ! $this->canHandleShift) {
            $this->toast('error', 'Shift ini hanya dapat dikelola kasir yang membukanya atau admin.');

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
            $this->setMovementType('in');
            $this->movementAmount = null;
            $this->movementReason = '';
        }
    }

    public function closeDialog(): void
    {
        $this->dialog = null;
        $this->resetValidation();
    }

    public function openShift(ShiftService $shifts): void
    {
        abort_unless($this->canOperate, 403);

        $this->saveWithToast(function () use ($shifts) {
            $this->validate([
                'shiftName' => ShiftService::rules()['open']['name'],
                'openingCash' => ShiftService::rules()['open']['opening_cash'],
            ], self::rename(ShiftService::messages(), ['name' => 'shiftName', 'opening_cash' => 'openingCash']));
            $this->mapErrors(fn () => $shifts->open($this->shiftName, (int) $this->openingCash, auth()->user()), ['name' => 'shiftName', 'opening_cash' => 'openingCash']);
        }, $this->shiftName.' dibuka dengan modal Rp'.number_format((int) $this->openingCash, 0, ',', '.').'.');

        $this->afterChange();
    }

    public function closeShift(ShiftService $shifts): void
    {
        $shift = $this->runningShift;
        abort_unless($this->canOperate && $shift, 403);
        ShiftController::authorizeOrAudit('close', $shift);

        $this->saveWithToast(function () use ($shifts, $shift) {
            $this->validate([
                'countedCash' => ShiftService::rules()['close']['counted_cash'],
                'closeNote' => ShiftService::rules()['close']['note'],
            ], self::rename(ShiftService::messages(), ['counted_cash' => 'countedCash']));
            $this->mapErrors(fn () => $shifts->close($shift, (int) $this->countedCash, $this->closeNote, auth()->user()), ['counted_cash' => 'countedCash', 'note' => 'closeNote']);
        }, $shift->name.' ditutup.');

        $this->afterChange();
    }

    public function setMovementType(string $type): void
    {
        if (array_key_exists($type, CashMovement::TYPES)) {
            $this->movementType = $type;
            $this->movementCategory = CashMovement::CATEGORIES_BY_TYPE[$type][0];
            $this->resetValidation();
        }
    }

    /** Pilih alasan cepat sekaligus kategorinya. */
    public function pickReason(string $reason): void
    {
        if ($category = self::MOVEMENT_REASONS[$this->movementType][$reason] ?? null) {
            $this->movementReason = $reason;
            $this->movementCategory = $category;
        }
    }

    /** Catat uang masuk/keluar laci di luar penjualan pada shift yang sedang berjalan. */
    public function saveMovement(CashMovementService $movements): void
    {
        $shift = $this->runningShift;
        abort_unless($this->canOperate, 403);
        if ($shift) {
            ShiftController::authorizeOrAudit('recordMovement', $shift);
        }
        $isIn = $this->movementType === CashMovement::TYPE_IN;
        $map = ['type' => 'movementType', 'category' => 'movementCategory', 'amount' => 'movementAmount', 'reason' => 'movementReason'];

        $this->saveWithToast(function () use ($movements, $map) {
            $rules = CashMovementService::rules();
            $this->validate(array_combine(array_values($map), array_values($rules)), self::rename(CashMovementService::messages(), $map));
            $this->mapErrors(fn () => $movements->record($this->movementType, $this->movementCategory, (int) $this->movementAmount, $this->movementReason, auth()->user()), $map);
        }, ($isIn ? 'Setoran' : 'Penarikan').' kas Rp'.number_format((int) $this->movementAmount, 0, ',', '.').' dicatat.');

        $this->afterChange();
    }

    /** Hanya petugas yang boleh membuka layar kasir (kasir & admin) yang mengelola shift. */
    #[Computed]
    public function canOperate(): bool
    {
        return (bool) auth()->user()?->canOpen('kasir');
    }

    /** Boleh menutup / mencatat kas shift yang sedang berjalan (kasir pembukanya atau admin). */
    #[Computed]
    public function canHandleShift(): bool
    {
        return $this->runningShift !== null && Gate::allows('close', $this->runningShift);
    }

    /** Shift yang sedang terbuka (bisa dari hari sebelumnya bila lupa ditutup). */
    #[Computed]
    public function runningShift(): ?CashShift
    {
        return app(ShiftService::class)->current();
    }

    #[Computed]
    public function openShiftRow(): ?array
    {
        return $this->runningShift ? ShiftService::toArray($this->runningShift) : null;
    }

    #[Computed]
    public function day(): Carbon
    {
        return $this->date === '' ? today() : Carbon::parse($this->date);
    }

    #[Computed]
    public function cash(): array
    {
        return app(CashReportService::class)->day($this->day);
    }

    /** Tren 7 hari sampai tanggal yang dilihat (lama → baru). */
    #[Computed]
    public function week(): array
    {
        return app(CashReportService::class)->range($this->day->copy()->subDays(6), $this->day);
    }

    private function afterChange(): void
    {
        $this->dialog = null;
        unset($this->cash, $this->week, $this->runningShift, $this->openShiftRow, $this->canHandleShift);
    }

    /** Jalankan aksi layanan; galat validasinya dipindah ke nama properti form. */
    private function mapErrors(callable $action, array $map): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$map[$k] ?? $k => $m])->all());
        }
    }

    /** Ganti awalan kunci pesan validasi (mis. `opening_cash.max` → `openingCash.max`). */
    private static function rename(array $messages, array $map): array
    {
        return collect($messages)->mapWithKeys(function ($m, $key) use ($map) {
            [$field, $rule] = explode('.', $key, 2) + [1 => null];

            return [($map[$field] ?? $field).($rule ? '.'.$rule : '') => $m];
        })->all();
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
