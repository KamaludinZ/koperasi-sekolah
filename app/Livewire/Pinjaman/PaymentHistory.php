<?php

namespace App\Livewire\Pinjaman;

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Services\LoanPaymentService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pinjaman → Angsuran → Riwayat pembayaran: seluruh pembayaran angsuran berurutan (terbaru dulu) per bulan bayar,
 * filter metode & pencarian; total bulan itu dihitung dari pembayaran sah (yang dikoreksi tampil dicoret).
 */
#[Layout('components.layouts.app')]
#[Title('Riwayat Pembayaran Angsuran')]
class PaymentHistory extends Component
{
    use WithPagination;

    public const PER_PAGE = 30;

    #[Url(as: 'bulan', except: '')]
    public string $month = '';

    #[Url(as: 'metode', except: '')]
    public string $method = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        if (! $this->validMonth()) {
            $this->month = today()->format('Y-m');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['month', 'method', 'search'], true)) {
            $this->resetPage();
        }
    }

    private function validMonth(): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month);
    }

    private function query()
    {
        $month = $this->validMonth() ? $this->month : today()->format('Y-m');
        $from = $month.'-01';
        $to = \Illuminate\Support\Carbon::parse($from)->endOfMonth()->toDateString();
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim(mb_substr($this->search, 0, 100))));

        return LoanPayment::query()->with(['user:id,name', 'loan:id,code,member_id', 'loan.member:id,member_code,name,deleted_at'])
            ->whereDayRange('paid_at', $from, $to)
            ->when(array_key_exists($this->method, Loan::METHODS), fn ($q) => $q->where('payment_method', $this->method))
            ->when($term !== '', fn ($q) => $q->whereHas('loan', fn ($l) => $l->whereRaw("LOWER(code) LIKE ? ESCAPE '\\'", ["%{$term}%"])
                ->orWhereHas('member', fn ($m) => $m->withTrashed()->where(fn ($x) => $x->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", ["%{$term}%"])
                    ->orWhereRaw("LOWER(member_code) LIKE ? ESCAPE '\\'", ["%{$term}%"])))));
    }

    #[Computed]
    public function payments()
    {
        return $this->query()->orderByDesc('paid_at')->orderByDesc('id')->paginate(self::PER_PAGE)
            ->through(fn (LoanPayment $p) => LoanPaymentService::paymentToArray($p) + [
                'loan' => ['id' => $p->loan->id, 'code' => $p->loan->code], 'member' => $p->loan->member?->name]);
    }

    #[Computed]
    public function totals(): array
    {
        $valid = $this->query()->whereNull('voided_at');

        return ['count' => (clone $valid)->count(), 'amount' => (int) round((float) (clone $valid)->sum('amount')),
            'by_method' => collect(Loan::METHODS)->map(fn ($l, $m) => (int) round((float) (clone $valid)->where('payment_method', $m)->sum('amount')))->all()];
    }

    public function render()
    {
        return view('livewire.pinjaman.payment-history');
    }
}
