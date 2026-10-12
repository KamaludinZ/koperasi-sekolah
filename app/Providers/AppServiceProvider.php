<?php

namespace App\Providers;

use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureRole;
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
    }
}
