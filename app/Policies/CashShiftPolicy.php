<?php

namespace App\Policies;

use App\Models\CashShift;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Hak atas shift kasir:
 * - melihat rincian: admin & pengurus semua shift; kasir hanya shift yang ia buka;
 * - menutup & mencatat setoran/penarikan: petugas kasir yang membuka shift itu, atau admin
 *   (mis. menutupkan shift yang lupa ditutup).
 */
class CashShiftPolicy
{
    public function view(User $user, CashShift $shift): Response
    {
        return $user->hasRole(Role::ADMIN, Role::PENGURUS) || $shift->user_id === $user->id
            ? Response::allow()
            : Response::deny('Kasir hanya dapat melihat shift miliknya sendiri.');
    }

    public function close(User $user, CashShift $shift): Response
    {
        return $this->operate($user, $shift, 'Shift hanya dapat ditutup oleh kasir yang membukanya atau admin.');
    }

    public function recordMovement(User $user, CashShift $shift): Response
    {
        return $this->operate($user, $shift, 'Uang laci hanya dapat dicatat oleh kasir shift ini atau admin.');
    }

    private function operate(User $user, CashShift $shift, string $message): Response
    {
        if (! $user->canOpen('kasir')) {
            return Response::deny('Hanya petugas kasir yang dapat mengelola shift.');
        }

        return $user->hasRole(Role::ADMIN) || $shift->user_id === $user->id ? Response::allow() : Response::deny($message);
    }
}
