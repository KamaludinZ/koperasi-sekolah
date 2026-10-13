<?php

namespace App\Providers;

use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureRole;
use App\Models\CashShift;
use App\Models\Role;
use App\Models\User;
use App\Policies\CashShiftPolicy;
use App\Services\NotificationService;
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
        // Satu instans per request agar antrean pemeriksaan stok terkumpul lalu diproses sekali.
        $this->app->scoped(NotificationService::class);
        $this->app->scoped(\App\Services\PromoEngine::class); // promo berjalan di-cache per permintaan
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
        // Buka/tutup shift & catat kas laci: petugas yang boleh memakai layar kasir (kasir, admin).
        Gate::define('kelola-shift', fn (User $user) => $user->canOpen('kasir'));
        Gate::policy(CashShift::class, CashShiftPolicy::class);

        Gate::define('ubah-pengaturan', fn (User $user) => $user->hasRole(Role::ADMIN) && $user->canOpen('pengaturan'));

        // Data terhapus lunak bisa dilihat siapa pun yang punya menu Audit; memulihkannya khusus Admin.
        Gate::define('pulihkan-data', fn (User $user) => $user->hasRole(Role::ADMIN) && $user->canOpen('audit'));
    }
}
