<?php

namespace App\Providers;

use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsurePortalMember;
use App\Http\Middleware\EnsureRole;
use App\Models\CashShift;
use App\Models\Role;
use App\Models\User;
use App\Policies\CashShiftPolicy;
use App\Services\LoanAutoAudit;
use App\Services\NotificationService;
use App\Services\PromoEngine;
use App\Services\QueueService;
use App\Support\ImpersonationPolicy;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->scoped(PromoEngine::class); // promo berjalan di-cache per permintaan
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Denyut pekerja antrean (dari proses queue:work) untuk pemantauan kesehatan; ditulis paling sering tiap 30 detik.
        Queue::looping(function () {
            static $last = 0;
            if (time() - $last >= 30) {
                $last = time();
                Cache::put('health:worker_heartbeat', now()->toDateTimeString(), now()->addHour());
            }
        });
        // Catat pekerjaan latar yang selesai (jumlah harian & 20 terakhir) untuk Sistem → Antrean.
        Queue::before(fn ($e) => QueueService::jobStarted($e->job));
        Queue::after(fn ($e) => QueueService::jobFinished($e->job));
        // /up (pemantau eksternal) ikut memastikan basis data terjangkau; gagal → 500.
        Event::listen(DiagnosingHealth::class, fn () => DB::select('SELECT 1'));

        // Format rupiah seragam: @rupiah($n) → "Rp12.500" (lihat App\Support\Rupiah).
        Blade::directive('rupiah', fn ($expr) => "<?php echo e(\\App\\Support\\Rupiah::format({$expr})); ?>");
        // Rentang hari inklusif yang aman untuk kolom tanggal di semua driver (SQLite menyimpan 'Y-m-d 00:00:00',
        // sehingga BETWEEN 'awal' AND 'akhir' melewatkan hari terakhir): kolom >= awal AND kolom < akhir + 1 hari.
        Builder::macro('whereDayRange', function (string $column, string $from, string $to) {
            /** @var Builder $this */
            return $this->where($column, '>=', Carbon::parse($from)->toDateString())
                ->where($column, '<', Carbon::parse($to)->addDay()->toDateString());
        });
        Blade::directive('tanggal', fn ($expr) => "<?php echo e(\\App\\Support\\Tanggal::format({$expr})); ?>");

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
        Livewire::addPersistentMiddleware([EnsureMenuAccess::class, EnsureRole::class, Authorize::class, EnsurePortalMember::class]);

        // @can('menu', 'laporan') di view / Gate::allows('menu', 'laporan') di kode.
        // transaksi dibatalkan → perubahan pinjaman yang tertunda (LoanAutoAudit) dibuang
        Event::listen(TransactionRolledBack::class,
            fn ($e) => LoanAutoAudit::rolledBack($e->connection->transactionLevel()));

        // Pemeriksaan rantai HMAC jejak audit pinjaman cukup berat: batas sendiri (tidak berbagi hitungan dengan rute lain).
        RateLimiter::for('verifikasi-audit-pinjaman', fn (Request $r) => Limit::perMinute(10)
            ->by('verifikasi-audit-pinjaman:'.($r->user()?->getAuthIdentifier() ?? $r->ip())));

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
        // Rekap & ekspor pinjaman (data keuangan seluruh anggota): hanya pengurus (dan admin sebagai pemegang seluruh modul)
        // yang menu Pinjaman-nya aktif — kasir & anggota tidak pernah, walau diberi menu.
        Gate::define('lihat-rekap-pinjaman', fn (User $user) => $user->hasRole(Role::PENGURUS, Role::ADMIN) && $user->canOpen('pinjaman'));

        Gate::define('kelola-pengadaan', fn (User $user) => $user->hasRole(Role::ADMIN, Role::PENGURUS) && $user->canOpen('pengadaan'));

        // Retur & pembatalan transaksi disetujui/ditolak oleh Admin & Pengurus pemegang menu Transaksi.
        Gate::define('setujui-retur', fn (User $user) => $user->hasRole(Role::ADMIN, Role::PENGURUS) && $user->canOpen('transaksi'));

        // Data terhapus lunak bisa dilihat siapa pun yang punya menu Audit; memulihkannya khusus Admin.
        // Masuk sebagai akun lain (impersonasi): hanya Admin aktif pemegang menu Pengguna, tidak berantai.
        Gate::define('impersonasi', fn (User $user) => ImpersonationPolicy::actorDenial($user) === null);

        Gate::define('pulihkan-data', fn (User $user) => $user->hasRole(Role::ADMIN) && $user->canOpen('audit'));
    }
}
