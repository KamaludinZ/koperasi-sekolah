<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Siapa pelaku sebenarnya sebuah aksi untuk JEJAK AUDIT. Normalnya petugas yang masuk; selama impersonasi =
 * ADMIN PELAKU (bukan akun target) — sesuai aturan "aksi selama impersonasi diatribusikan ke admin".
 * Kepemilikan data bisnis (mis. shift kasir yang dibantu) tetap mengikuti akun yang sedang dipakai; hanya
 * catatan siapa-melakukan-apa (activity_logs, riwayat status pesanan, riwayat angsuran) yang memakai kelas ini.
 */
class Actor
{
    /** Keterangan impersonasi untuk disimpan bersama catatan audit, atau null bila tidak sedang impersonasi. */
    public static function impersonation(): ?array
    {
        $imp = ImpersonationSession::current();

        return $imp ? [
            'id' => $imp['id'], 'admin_id' => $imp['admin_id'], 'admin_name' => $imp['admin_name'],
            'target_type' => $imp['target_type'], 'target_id' => $imp['target_id'], 'target_name' => $imp['target_name'],
        ] : null;
    }

    /**
     * Pelaku untuk catatan audit: selama impersonasi selalu admin pelaku (apa pun pelaku yang diusulkan pemanggil:
     * akun target petugas, anggota portal, dsb.); di luar impersonasi pelaku usulan apa adanya.
     *
     * @param  ?array{id?: ?int, name?: ?string, role?: ?string}  $proposed
     * @return ?array{id: ?int, name: string, role: ?string}
     */
    public static function forAudit(?array $proposed): ?array
    {
        $imp = ImpersonationSession::current();
        if (! $imp) {
            return $proposed;
        }

        return ['id' => $imp['admin_id'], 'name' => $imp['admin_name'], 'role' => 'admin'];
    }

    /** Id pengguna pelaku untuk riwayat (admin selama impersonasi). */
    public static function userId(?int $proposed): ?int
    {
        return ImpersonationSession::current()['admin_id'] ?? $proposed;
    }

    /**
     * Kolom "oleh siapa" (…_by) pada model yang disimpan selama impersonasi PETUGAS: nilai = id akun target diganti
     * id admin pelaku, karena aksinya (memutuskan, membatalkan, mencatat, menghapus, …) dilakukan admin. Kolom
     * kepemilikan (user_id, kasir pemilik shift, dsb.) tidak disentuh. Dipasang global di AppServiceProvider.
     */
    public static function attributeModel(Model $model): void
    {
        $imp = ImpersonationSession::current();
        if (! $imp || $imp['target_type'] !== 'petugas' || $model instanceof ActivityLog) {
            return;
        }
        foreach ($model->getDirty() as $column => $value) {
            if (str_ends_with($column, '_by') && $value !== null && (int) $value === $imp['target_id'] && is_numeric($value)) {
                $model->setAttribute($column, $imp['admin_id']);
            }
        }
    }

    /** Nama pelaku untuk riwayat, mis. "Admin Koperasi (sebagai Bu Rina)" selama impersonasi. */
    public static function name(?string $proposed): ?string
    {
        $imp = ImpersonationSession::current();

        return $imp ? $imp['admin_name'].' (sebagai '.$imp['target_name'].')' : $proposed;
    }
}
