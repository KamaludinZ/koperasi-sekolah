<?php

namespace App\Livewire\Anggota;

use App\Models\Member;
use App\Services\MemberService;
use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Anggota → Piutang (bon): daftar anggota yang masih punya bon (sisa, batas, jatuh tempo, status)
 * dan riwayat bon & pelunasan seluruh anggota. Tahap ini dari MockData::memberBills() &
 * memberBonHistory(); backend piutang menyusul dengan bentuk data yang sama.
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

    /** @return list<array<string, mixed>> anggota aktif (cocok pencarian & jenis) */
    private function members(): array
    {
        return app(MemberService::class)->query(['search' => $this->search, 'type' => $this->type])
            ->get()->map(fn (Member $m) => MemberService::toArray($m))->all();
    }

    /** Status bon satu anggota dari bon-bonnya. */
    public static function statusOf(array $bills): string
    {
        $due = collect($bills)->min('due_at');

        return match (true) {
            collect($bills)->contains('overdue', true) => 'lewat',
            $due !== null && Carbon::parse($due)->lte(now()->addDays(7)) => 'minggu',
            default => 'berjalan',
        };
    }

    /** Semua anggota berbon (sebelum filter status), lewat jatuh tempo & jatuh tempo terdekat dulu. */
    #[Computed]
    public function debtors(): array
    {
        $rows = [];
        foreach ($this->members() as $m) {
            $bills = MockData::memberBills($m['id'], $m['type']);
            if (! $bills) {
                continue;
            }
            $credit = MockData::memberCredit($m['id'], $m['type']);
            $rows[] = $m + [
                'bills' => count($bills),
                'outstanding' => $credit['outstanding'],
                'limit' => $credit['limit'],
                'oldest_due_at' => $credit['oldest_due_at'],
                'status' => self::statusOf($bills),
            ];
        }
        $rank = ['lewat' => 0, 'minggu' => 1, 'berjalan' => 2];
        usort($rows, fn ($a, $b) => [$rank[$a['status']], $a['oldest_due_at'], $a['name']] <=> [$rank[$b['status']], $b['oldest_due_at'], $b['name']]);

        return $rows;
    }

    #[Computed]
    public function list(): array
    {
        return $this->status === '' ? $this->debtors : array_values(array_filter($this->debtors, fn ($r) => $r['status'] === $this->status));
    }

    #[Computed]
    public function stats(): array
    {
        $d = collect($this->debtors);
        $late = $d->where('status', 'lewat');

        return [
            'total' => $d->sum('outstanding'),
            'members' => $d->count(),
            'late_total' => $late->sum('outstanding'),
            'late_members' => $late->count(),
            'week_members' => $d->where('status', 'minggu')->count(),
        ];
    }

    /** Riwayat bon & pelunasan sesuai filter, terbaru dulu. */
    #[Computed]
    public function history(): array
    {
        $from = $this->period === 'semua' ? null : now()->subDays((int) $this->period)->toDateString();
        $events = [];
        foreach ($this->members() as $m) {
            foreach (MockData::memberBonHistory($m['id'], $m['type']) as $e) {
                if (($this->kind === '' || $e['kind'] === $this->kind) && ($from === null || $e['date'] >= $from)) {
                    $events[] = $e + ['member' => ['id' => $m['id'], 'name' => $m['name'], 'member_code' => $m['member_code']]];
                }
            }
        }
        usort($events, fn ($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);

        return [
            'rows' => $events,
            'bon' => array_sum(array_map(fn ($e) => $e['kind'] === 'bon' ? $e['amount'] : 0, $events)),
            'paid' => array_sum(array_map(fn ($e) => $e['kind'] === 'pelunasan' ? $e['amount'] : 0, $events)),
        ];
    }

    public function render()
    {
        return view('livewire.anggota.piutang');
    }
}
