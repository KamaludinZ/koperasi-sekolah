<?php

namespace App\Providers;

use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Aksi Livewire (POST /livewire/update) ikut dicek dengan pembatas rute halaman asalnya.
        Livewire::addPersistentMiddleware([EnsureMenuAccess::class, EnsureRole::class]);

        // @can('menu', 'laporan') di view / Gate::allows('menu', 'laporan') di kode.
        Gate::define('menu', fn (User $user, string $menu) => $user->canOpen($menu));

        // Melihat pengaturan cukup punya menu "pengaturan" (bisa dibagikan ke peran lain);
        // mengubahnya (profil, logo, struk, pajak) tetap khusus Admin yang aktif.
        Gate::define('ubah-pengaturan', fn (User $user) => $user->hasRole(Role::ADMIN) && $user->canOpen('pengaturan'));
    }
}
