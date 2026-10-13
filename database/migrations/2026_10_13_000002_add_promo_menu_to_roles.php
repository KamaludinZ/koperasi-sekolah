<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Menu baru "Diskon & Promo" (`promo`): tambahkan ke hak menu peran bawaan yang sudah ada
     * (admin, kasir, pengurus), tepat setelah menu barang. Peran yang sudah punya menu `promo` dibiarkan.
     */
    public function up(): void
    {
        foreach (DB::table('roles')->whereIn('name', ['admin', 'kasir', 'pengurus'])->get() as $role) {
            $menus = json_decode($role->permissions, true) ?: [];
            if (in_array('promo', $menus, true)) {
                continue;
            }
            $at = array_search('barang', $menus, true);
            array_splice($menus, $at === false ? count($menus) : $at + 1, 0, 'promo');
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $menus = array_values(array_diff(json_decode($role->permissions, true) ?: [], ['promo']));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus)]);
        }
    }
};
