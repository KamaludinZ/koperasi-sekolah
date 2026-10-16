<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Metadata satu menu yang terakhir terbaca pemindai panduan (tabel guide_menu_snapshots). */
class GuideMenuSnapshot extends Model
{
    public const ASPECTS = ['menu' => 'label/rute menu', 'roles' => 'izin peran', 'pages' => 'halaman'];

    protected $fillable = ['menu_key', 'label', 'route', 'roles', 'pages', 'menu_hash', 'roles_hash', 'pages_hash', 'scanned_at', 'changed_at'];

    protected function casts(): array
    {
        return ['roles' => 'array', 'pages' => 'array', 'scanned_at' => 'datetime', 'changed_at' => 'datetime'];
    }
}
