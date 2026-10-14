<?php

namespace App\Providers;

use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureRole;
use App\Models\CashShift;
use App\Models\Role;
use App\Models\User;
use App\Policies\CashShiftPolicy;
use App\Services\NotificationService;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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
        // Denyut pekerja antrean (dari proses queue:work) untuk pemantauan kesehatan; ditulis paling sering tiap 30 detik.
        \Illuminate\Support\Facades\Queue::looping(function () {
            static $last = 0;
            if (time() - $last >= 30) {
                $last = time();
                \Illuminate\Support\Facades\Cache::put('health:worker_heartbeat', now()->toDateTimeString(), now()->addHour());
            }
        });
        // Catat pekerjaan latar yang selesai (jumlah harian & 20 terakhir) untuk Sistem → Antrean.
        \Illuminate\Support\Facades\Queue::before(fn ($e) => \App\Services\QueueService::jobStarted($e->job));
        \Illuminate\Support\Facades\Queue::after(fn ($e) => \App\Services\QueueService::jobFinished($e->job));
        // /up (pemantau eksternal) ikut memastikan basis data terjangkau; gagal → 500.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Events\DiagnosingHealth::class, fn () => \Illuminate\Support\Facades\DB::select('SELECT 1'));

        // Format rupiah seragam: @rupiah($n) → "Rp12.500" (lihat App\Support\Rupiah).
        \Illuminate\Support\Facades\Blade::directive('rupiah', fn ($expr) => "<?php echo e(\\App\\Support\\Rupiah::format({$expr})); ?>");
        \Illuminate\Support\Facades\Blade::directive('tanggal', fn ($expr) => "<?php echo e(\\App\\Support\\Tanggal::format({$expr})); ?>");

        // Semua URL yang dibuat aplikasi memakai https bila HTTPS dipaksa.
        if (config('koperasi.force_https')) {
            URL::forceScheme('https');
        }

        // Baca skema/IP asli di balik reverse proxy (TRUSTED_PROXIES, lihat config/koperasi.php).
        if ($proxies = config('koperasi.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Aksi Livewire (POST /livewire/update) ikut dicek dengan pembatas rute halaman asalnya.
        // Termasuk "can:" pada rute halaman (mis. form pengadaan khusus kelola-pengadaan).
        Livewire::addPersistentMiddleware([EnsureMenuAccess::class, EnsureRole::class, \Illuminate\Auth\Middleware\Authorize::class, \App\Http\Middleware\EnsurePortalMember::class]);

        // @can('menu', 'laporan') di view / Gate::allows('menu', 'laporan') di kode.
        Gate::define('menu', fn (User $user, string $menu) => $user->canOpen($menu));

        // Melihat pengaturan cukup punya menu "pengaturan" (bisa dibagikan ke peran lain);
        // mengubahnya (profil, logo, struk, pajak) tetap khusus Admin yang aktif.
        // Buka/tutup shift & catat kas laci: petugas yang boleh memakai layar kasir (kasir, admin).
        Gate::define('kelola-shift', fn (User $user) => $user->canOpen('kasir'));
        Gate::policy(CashShift::class, CashShiftPolicy::class);

        Gate::define('ubah-pengaturan', fn (User $user) => $user->hasRole(Role::ADMIN) && $user->canOpen('pengaturan'));

        // Kategori barang & impor barang dikelola Admin dan Pengurus (kasir hanya melihat).
        Gate::define('kelola-katalog', fn (User $user) => $user->hasRole(Role::ADMIN, Role::PENGURUS) && $user->canOpen('barang'));

        // Hapus anggota & batas bon khusus: Admin & Pengurus (kasir cukup daftar/ubah data dasar, setor/tarik).
        Gate::define('kelola-anggota', fn (User $user) => $user->hasRole(Role::ADMIN, Role::PENGURUS) && $user->canOpen('anggota'));

        // Pengadaan (supplier, pesanan, penerimaan, hutang): Admin & Pengurus pemegang menu Pengadaan.
        // Jenis & mutasi simpanan anggota hanya untuk pengurus/admin (kasir tidak punya menu ini).
        Gate::define('kelola-simpanan', fn (User $user) => $user->hasRole(Role::ADMIN, Role::PENGURUS) && $user->canOpen('simpanan'));

        // Pinjaman anggota hanya dikelola pengurus/admin.
        Gate::define('kelola-pinjaman', fn (User $user) => $user->hasRole(Role::ADMIN, Role::PENGURUS) && $user->canOpen('pinjaman'));

        Gate::define('kelola-pengadaan', fn (User $user) => $user->hasRole(Role::ADMIN, Role::PENGURUS) && $user->canOpen('pengadaan'));

        // Retur & pembatalan transaksi disetujui/ditolak oleh Admin & Pengurus pemegang menu Transaksi.
        Gate::define('setujui-retur', fn (User $user) => $user->hasRole(Role::ADMIN, Role::PENGURUS) && $user->canOpen('transaksi'));

        // Data terhapus lunak bisa dilihat siapa pun yang punya menu Audit; memulihkannya khusus Admin.
        Gate::define('pulihkan-data', fn (User $user) => $user->hasRole(Role::ADMIN) && $user->canOpen('audit'));
    }
}
