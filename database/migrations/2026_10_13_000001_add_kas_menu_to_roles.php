<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Menu baru "Kas Harian" (`kas`): tambahkan ke hak menu peran bawaan yang sudah ada
     * (admin, kasir, pengurus), tepat setelah menu transaksi. Peran yang sudah punya
     * menu `kas` dibiarkan.
     */
    public function up(): void
    {
        foreach (DB::table('roles')->whereIn('name', ['admin', 'kasir', 'pengurus'])->get() as $role) {
            $menus = json_decode($role->permissions, true) ?: [];
            if (in_array('kas', $menus, true)) {
                continue;
            }
            $at = array_search('transaksi', $menus, true);
            array_splice($menus, $at === false ? count($menus) : $at + 1, 0, 'kas');
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $menus = array_values(array_diff(json_decode($role->permissions, true) ?: [], ['kas']));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus)]);
        }
    }
};
