<?php

namespace App\Services;

use App\Models\Member;
use App\Support\ImpersonationSession;

/**
 * Panduan anggota berbasis sesi portal (login kata sandi + PIN transaksi 6 digit): isi panduan anggota dari
 * GuideService ditambah keadaan sesi anggota yang relevan — PIN sudah diatur/terkunci, angsuran menunggak, dan mode
 * baca-saja saat admin masuk sebagai anggota — sebagai pemberitahuan per panduan. Membaca panduan tidak memakai
 * token PIN (bukan transaksi); nilai PIN maupun token tidak pernah dikirim.
 */
class MemberGuideService
{
    public function __construct(private GuideService $guides, private PortalPinService $pins) {}

    /**
     * Keadaan sesi anggota untuk panduan.
     *
     * @return array{pin: array{has_pin: bool, locked: bool, minutes: int, attempts_left: int}, overdue_installments: int, read_only: bool}
     */
    public function context(Member $member): array
    {
        $attempts = $this->pins->attempts($member);

        return [
            'pin' => ['has_pin' => $member->hasPin(), 'locked' => $attempts['locked'], 'minutes' => $attempts['minutes'], 'attempts_left' => $attempts['left']],
            'overdue_installments' => MemberLoanService::overdueCount($member->id),
            'read_only' => ImpersonationSession::impersonatingMember(),
        ];
    }

    /**
     * Pemberitahuan untuk satu panduan anggota dari keadaan sesinya.
     *
     * @return list<array{type: 'info'|'warning', code: string, text: string}>
     */
    public function notices(string $key, array $ctx): array
    {
        $out = [];
        $payments = in_array($key, ['portal.belanja', 'portal.beranda'], true);
        if ($ctx['read_only'] && in_array($key, ['portal.belanja', 'portal.pinjaman', 'portal.simpanan'], true)) {
            $out[] = ['type' => 'info', 'code' => 'baca_saja', 'text' => 'Mode masuk sebagai anggota: pembayaran dan PIN dinonaktifkan, panduan tetap bisa dibaca.'];
        }
        if ($payments && ! $ctx['pin']['has_pin']) {
            $out[] = ['type' => 'warning', 'code' => 'pin_belum_diatur', 'text' => 'PIN transaksi Anda belum diatur. Minta pengurus koperasi mengaturnya sebelum membayar dengan saldo.'];
        } elseif ($payments && $ctx['pin']['locked']) {
            $out[] = ['type' => 'warning', 'code' => 'pin_terkunci', 'text' => "PIN terkunci sementara karena salah berulang kali. Coba lagi dalam {$ctx['pin']['minutes']} menit."];
        }
        if ($ctx['overdue_installments'] > 0 && in_array($key, ['portal.pinjaman', 'portal.beranda'], true)) {
            $out[] = ['type' => 'warning', 'code' => 'angsuran_menunggak', 'text' => "Ada {$ctx['overdue_installments']} angsuran pinjaman yang menunggak. Lihat rinciannya di menu Pinjaman."];
        }

        return $out;
    }

    /**
     * Daftar panduan anggota (opsional disaring kata kunci) beserta pemberitahuan sesi.
     *
     * @return array{data: list<array>, meta: array}
     */
    public function listFor(Member $member, string $search = ''): array
    {
        $ctx = $this->context($member);
        $data = array_map(fn ($g) => $g + ['notices' => $this->notices($g['key'], $ctx)], $this->guides->listFor(null, 'anggota', false, $search));

        return ['data' => $data, 'meta' => ['total' => count($data)] + $ctx];
    }

    /** Satu panduan anggota (null bila kunci bukan panduan anggota). */
    public function find(Member $member, string $key): ?array
    {
        $all = $this->guides->all('anggota');
        if (! isset($all[$key])) {
            return null;
        }
        $ctx = $this->context($member);

        return ['data' => $this->guides->present($key, $all[$key], true) + ['notices' => $this->notices($key, $ctx)], 'meta' => $ctx];
    }
}
