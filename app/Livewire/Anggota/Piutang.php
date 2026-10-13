<?php

namespace App\Livewire\Anggota;

use App\Services\MemberCreditService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Anggota → Piutang (bon): daftar anggota yang masih punya bon (sisa, batas, jatuh tempo, status)
 * dan riwayat bon & pelunasan seluruh anggota. Data dari MemberCreditService (sama dengan
 * GET /anggota/piutang/daftar & /anggota/piutang/riwayat).
 */
#[Layout('components.layouts.app')]
#[Title('Piutang Anggota')]
class Piutang extends Component
{
    public const TABS = ['daftar' => 'Daftar piutang', 'riwayat' => 'Riwayat'];

    public const STATUSES = ['' => 'Semua status', 'lewat' => 'Lewat jatuh tempo', 'minggu' => 'Jatuh tempo ≤ 7 hari', 'berjalan' => 'Berjalan'];

    public const KINDS = ['' => 'Bon & pelunasan', 'bon' => 'Bon baru', 'pelunasan' => 'Pelunasan'];

    public const PERIODS = ['30' => '30 hari', '90' => '90 hari', 'semua' => 'Semua'];

    public const PER_PAGE = 25;

    #[Url(as: 'tab', except: 'daftar')]
    public string $tab = 'daftar';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'jenis', except: '')]
    public string $type = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'catatan', except: '')]
    public string $kind = '';

    #[Url(as: 'periode', except: '90')]
    public string $period = '90';

    public int $limit = self::PER_PAGE;

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'daftar';
        $this->limit = self::PER_PAGE;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'status', 'kind', 'period'], true)) {
            $this->limit = self::PER_PAGE;
        }
    }

    public function more(): void
    {
        $this->limit += self::PER_PAGE;
    }

    /** Status piutang dari bon-bon anggota (jatuh tempo tertua). */
    public static function statusOf(array $bills): string
    {
        return MemberCreditService::statusFor(collect($bills)->min('due_at'));
    }

    /** Anggota berbon yang cocok pencarian & jenis (sebelum filter status). */
    #[Computed]
    public function debtors(): array
    {
        return app(MemberCreditService::class)->debtors(['search' => $this->search, 'type' => $this->type]);
    }

    #[Computed]
    public function list(): array
    {
        return $this->status === '' ? $this->debtors : array_values(array_filter($this->debtors, fn ($r) => $r['status'] === $this->status));
    }

    #[Computed]
    public function stats(): array
    {
        return app(MemberCreditService::class)->stats();
    }

    /** Riwayat bon & pelunasan sesuai filter, terbaru dulu. */
    #[Computed]
    public function history(): array
    {
        return app(MemberCreditService::class)->history([
            'search' => $this->search,
            'type' => $this->type,
            'kind' => $this->kind,
            'from' => $this->period === 'semua' ? null : now()->subDays((int) $this->period)->startOfDay(),
        ]);
    }

    public function render()
    {
        return view('livewire.anggota.piutang');
    }
}
