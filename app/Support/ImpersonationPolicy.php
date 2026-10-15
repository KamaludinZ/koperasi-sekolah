<?php

namespace App\Support;

use App\Models\Member;
use App\Models\Role;
use App\Models\User;

/**
 * Aturan impersonasi (masuk sementara sebagai akun lain untuk membantu), dipakai halaman daftar akun dan
 * layanan impersonasi supaya keputusannya selalu sama:
 * - hanya Admin aktif yang boleh memulai, dan tidak sedang dalam impersonasi lain (tidak berantai);
 * - target petugas: aktif, punya peran, bukan akun sendiri, dan BUKAN Admin (sesama admin tidak bisa saling
 *   menyamar — mencegah pengambilalihan hak/jejak admin lain);
 * - target anggota: punya akses portal (aktif, belum dihapus, kata sandi portal sudah dibuat);
 * - alasan wajib (10–200 karakter) karena dicatat di jejak audit;
 * - sesi impersonasi dibatasi MAX_MINUTES menit lalu otomatis kembali ke sesi admin.
 */
class ImpersonationPolicy
{
    public const MAX_MINUTES = 30;

    public const REASON_MIN = 10;

    public const REASON_MAX = 200;

    /**
     * Portal anggota saat diimpersonasi = mode lihat saja untuk aksi uang & rahasia anggota: checkout/pesanan baru,
     * bayar pakai saldo, verifikasi PIN, dan pembatalan pesanan. Admin hanya melihat apa yang dilihat anggota.
     */
    public const MEMBER_READ_ONLY_ROUTES = ['portal.checkout', 'portal.pesanan.store', 'portal.pesanan.bayar-saldo', 'portal.pin.verify'];

    public const MEMBER_READ_ONLY_MESSAGE = 'Tidak tersedia dalam mode impersonasi — belanja, pembayaran, PIN, dan pembatalan pesanan hanya bisa dilakukan anggota sendiri.';

    /** Pilihan lama sesi (menit), semuanya ≤ MAX_MINUTES. */
    public const DURATIONS = [10, 15, 30];

    /** Alasan cepat (bisa dilengkapi admin) per jenis akun. */
    public const REASON_PRESETS = [
        'petugas' => [
            'Memeriksa masalah yang dilaporkan petugas di layarnya',
            'Membantu kasir: struk/transaksi bermasalah',
            'Memastikan menu & hak akses sesuai peran barunya',
        ],
        'anggota' => [
            'Membantu anggota yang tidak bisa memakai portal',
            'Memeriksa saldo/simpanan yang dikeluhkan anggota',
            'Memeriksa status pengajuan pinjaman anggota',
        ],
    ];

    /** Admin ini boleh memulai impersonasi? Null = boleh, string = alasan ditolak. */
    public static function actorDenial(?User $actor): ?string
    {
        if (! $actor || ! $actor->is_active) {
            return 'Hanya admin aktif yang dapat masuk sebagai akun lain.';
        }
        if (! $actor->loadMissing('role', 'roles')->hasRole(Role::ADMIN)) {
            return 'Hanya peran Admin yang dapat masuk sebagai akun lain.';
        }

        return null;
    }

    /** Petugas ini boleh dimasuki oleh $actor? Null = boleh, string = alasan ditolak. */
    public static function staffDenial(User $target, ?User $actor): ?string
    {
        if ($actor && $target->is($actor)) {
            return 'Ini akun Anda sendiri.';
        }
        if (! $target->is_active) {
            return 'Akun nonaktif.';
        }
        $target->loadMissing('role', 'roles');
        if ($target->roleNames() === []) {
            return 'Akun belum punya peran.';
        }
        if ($target->hasRole(Role::ADMIN)) {
            return 'Akun Admin tidak bisa dimasuki.';
        }

        return null;
    }

    /** Anggota ini boleh dimasuki (portal)? Null = boleh, string = alasan ditolak. */
    public static function memberDenial(Member $member): ?string
    {
        if ($member->trashed()) {
            return 'Anggota sudah dihapus.';
        }
        if (! $member->is_active) {
            return 'Anggota nonaktif.';
        }
        if (! $member->hasPortalAccess()) {
            return 'Akun portal belum dibuat.';
        }

        return null;
    }

    /** @return array<string, list<string>> aturan validasi alasan & lama sesi */
    public static function reasonRules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX],
            'minutes' => ['required', 'integer', 'in:'.implode(',', self::DURATIONS)],
        ];
    }

    /** @return array<string, string> */
    public static function reasonMessages(): array
    {
        return [
            'reason.required' => 'Tulis alasan masuk sebagai akun ini — dicatat di jejak audit.',
            'reason.min' => 'Alasan minimal '.self::REASON_MIN.' karakter (mis. "membantu kasir yang struknya gagal tercetak").',
            'reason.max' => 'Alasan maksimal '.self::REASON_MAX.' karakter.',
            'minutes.in' => 'Pilih lama sesi '.implode(', ', self::DURATIONS).' menit.',
            'minutes.required' => 'Pilih lama sesi.',
        ];
    }
}
