<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Menu baru "Sistem" (`sistem`: kesehatan sistem, cadangan data, antrean): tambahkan ke hak menu
     * peran admin yang sudah ada, tepat sebelum menu pengaturan. Peran yang sudah punya dibiarkan.
     */
    public function up(): void
    {
        foreach (DB::table('roles')->where('name', 'admin')->get() as $role) {
            $menus = json_decode($role->permissions, true) ?: [];
            if (in_array('sistem', $menus, true)) {
                continue;
            }
            $at = array_search('pengaturan', $menus, true);
            array_splice($menus, $at === false ? count($menus) : $at, 0, 'sistem');
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $menus = array_values(array_diff(json_decode($role->permissions, true) ?: [], ['sistem']));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus)]);
        }
    }
};
