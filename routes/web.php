<?php

use App\Http\Controllers\Akun\PasswordController;
use App\Http\Controllers\Akun\SessionController;
use App\Http\Controllers\Anggota\MemberController;
use App\Http\Controllers\Anggota\SavingsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Barang\ProductController;
use App\Http\Controllers\BerandaController;
use App\Http\Controllers\Barang\ProductListController;
use App\Http\Controllers\Barang\StockAlertController;
use App\Http\Controllers\Kas\CashExportController;
use App\Http\Controllers\Kas\CashMovementController;
use App\Http\Controllers\Kas\CashReportController;
use App\Http\Controllers\Kas\ShiftHistoryController;
use App\Http\Controllers\Kas\ShiftController;
use App\Http\Controllers\Kasir\CheckoutController;
use App\Http\Controllers\Kasir\CouponCheckController;
use App\Http\Controllers\Kasir\ProductSearchController;
use App\Http\Controllers\Kasir\TransactionReceiptController;
use App\Http\Controllers\Laporan\SalesExportController;
use App\Http\Controllers\Laporan\SalesReportController;
use App\Http\Controllers\Notifikasi\AnnouncementController;
use App\Http\Controllers\Notifikasi\NotificationController;
use App\Http\Controllers\Pengaturan\LogoController;
use App\Http\Controllers\Pengaturan\SettingsController;
use App\Http\Controllers\Pengguna\UserController;
use App\Http\Controllers\Transaksi\TransactionDetailController;
use App\Http\Controllers\Transaksi\TransactionListController;
use App\Livewire\Akun;
use App\Livewire\Anggota;
use App\Livewire\Audit;
use App\Livewire\Auth\Login;
use App\Livewire\Barang;
use App\Livewire\Kas;
use App\Livewire\Kasir;
use App\Livewire\Laporan;
use App\Livewire\Notifikasi;
use App\Livewire\Pengaturan;
use App\Livewire\Pengguna;
use App\Livewire\Promo;
use App\Livewire\Transaksi;
use App\Services\LogoService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;

// Halaman petugas dijaga per menu: `menu:<kunci>` = wajib masuk + peran boleh membuka menu
// itu (App\Support\Menu, tabel roles.permissions); `peran:<nama>` = pembatas tambahan.
Route::get('/', BerandaController::class)->name('beranda');
Route::get('/logo/{file}', [LogoController::class, 'show'])->where('file', LogoService::FILE_PATTERN)->name('logo');

Route::get('/masuk', Login::class)->name('login');
Route::post('/masuk', LoginController::class)->middleware('throttle:30,1')->name('login.store');
Route::post('/keluar', LogoutController::class)->name('logout');
// Akun sendiri: cukup sudah masuk (semua peran).
Route::get('/akun/kata-sandi', Akun\Password::class)->middleware(['menu', 'auth'])->name('akun.password');
Route::get('/akun/sesi', Akun\Sessions::class)->middleware(['menu', 'auth'])->name('akun.sessions');
Route::get('/notifikasi', Notifikasi\Index::class)->middleware(['menu', 'auth'])->name('notifikasi');
Route::get('/notifikasi/data', [NotificationController::class, 'index'])->middleware(['menu', 'auth'])->name('notifikasi.data');
Route::get('/notifikasi/terbaru', [NotificationController::class, 'latest'])->middleware(['menu', 'auth'])->name('notifikasi.latest');
Route::middleware(['menu', 'auth', 'throttle:60,1'])->group(function () {
    Route::post('/notifikasi/tandai', [NotificationController::class, 'mark'])->name('notifikasi.mark');
    Route::post('/notifikasi/tandai-semua', [NotificationController::class, 'markAll'])->name('notifikasi.mark-all');
    Route::post('/notifikasi/sembunyikan', [NotificationController::class, 'dismiss'])->name('notifikasi.dismiss');
});
Route::get('/notifikasi/pengumuman', Notifikasi\Announcements::class)
    ->middleware(['menu', 'auth', 'peran:admin,pengurus'])
    ->name('notifikasi.pengumuman');
Route::middleware(['menu', 'auth', 'peran:admin,pengurus'])->group(function () {
    Route::get('/notifikasi/pengumuman/data', [AnnouncementController::class, 'index'])->name('notifikasi.pengumuman.data');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/notifikasi/pengumuman', [AnnouncementController::class, 'store'])->name('notifikasi.pengumuman.store');
        Route::put('/notifikasi/pengumuman/{pengumuman}', [AnnouncementController::class, 'update'])->whereNumber('pengumuman')->name('notifikasi.pengumuman.update');
        Route::delete('/notifikasi/pengumuman/{pengumuman}', [AnnouncementController::class, 'destroy'])->whereNumber('pengumuman')->name('notifikasi.pengumuman.destroy');
    });
});
Route::middleware('auth')->group(function () {
    Route::put('/akun/kata-sandi', PasswordController::class)
        ->middleware('throttle:10,1')
        ->name('akun.password.update');
    Route::get('/akun/sesi/daftar', [SessionController::class, 'index'])->name('akun.sessions.list');
    Route::delete('/akun/sesi/{session}', [SessionController::class, 'destroy'])->name('akun.sessions.destroy');
    Route::post('/akun/sesi/keluarkan-lain', [SessionController::class, 'destroyOthers'])
        ->middleware('throttle:10,1')
        ->name('akun.sessions.others');
});

Route::middleware('menu:kasir')->group(function () {
    Route::get('/kasir', Kasir\Index::class)->name('kasir');
    Route::get('/kasir/barang/cari', ProductSearchController::class)
        ->middleware('throttle:120,1')
        ->name('kasir.products.search');
    Route::post('/kasir/kupon/cek', CouponCheckController::class)
        ->middleware('throttle:30,1')
        ->name('kasir.coupons.check');
    Route::post('/kasir/transaksi', CheckoutController::class)
        ->middleware('throttle:60,1')
        ->name('kasir.checkout');
    Route::get('/kasir/transaksi/{transaction}', [TransactionReceiptController::class, 'show'])
        ->whereNumber('transaction')
        ->name('kasir.transactions.show');
    Route::get('/kasir/transaksi/{transaction}/struk', [TransactionReceiptController::class, 'print'])
        ->whereNumber('transaction')
        ->name('kasir.transactions.receipt');
    Route::get('/kasir/transaksi/{transaction}/struk.pdf', [TransactionReceiptController::class, 'pdf'])
        ->whereNumber('transaction')
        ->middleware('throttle:60,1')
        ->name('kasir.transactions.receipt.pdf');
});

Route::middleware('menu:kas')->group(function () {
    Route::get('/kas', Kas\Index::class)->name('kas');
    Route::get('/kas/rekap', Kas\Recap::class)->name('kas.rekap');
    Route::get('/kas/shift', Kas\Shifts::class)->name('kas.shift');
    Route::get('/kas/shift/aktif', [ShiftController::class, 'current'])->name('kas.shift.current');
    Route::get('/kas/mutasi', [CashMovementController::class, 'index'])->middleware('throttle:120,1')->name('kas.movements');
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/kas/rekap/harian', [CashReportController::class, 'day'])->name('kas.report.day');
        Route::get('/kas/rekap/periode', [CashReportController::class, 'period'])->name('kas.report.period');
        Route::get('/kas/shift/riwayat', [ShiftHistoryController::class, 'index'])->name('kas.shift.history');
        Route::get('/kas/shift/{shift}/rincian', [ShiftHistoryController::class, 'show'])->whereNumber('shift')->name('kas.shift.detail');
        Route::get('/kas/rekap/ekspor/{export}', [CashExportController::class, 'show'])->whereNumber('export')->name('kas.export.show');
    });
    Route::post('/kas/rekap/ekspor', [CashExportController::class, 'store'])->middleware('throttle:10,1')->name('kas.export.store');
    Route::get('/kas/rekap/ekspor/{export}/unduh', [CashExportController::class, 'download'])->whereNumber('export')->middleware('throttle:30,1')->name('kas.export.download');
    Route::middleware(['can:kelola-shift', 'throttle:30,1'])->group(function () {
        Route::post('/kas/shift/buka', [ShiftController::class, 'open'])->name('kas.shift.open');
        Route::post('/kas/shift/{shift}/tutup', [ShiftController::class, 'close'])->whereNumber('shift')->name('kas.shift.close');
        Route::post('/kas/mutasi', [CashMovementController::class, 'store'])->name('kas.movements.store');
    });
});

Route::middleware('menu:promo')->group(function () {
    Route::get('/promo', Promo\Index::class)->name('promo');
    Route::get('/promo/jadwal', Promo\Schedule::class)->name('promo.jadwal');
});

Route::middleware('menu:barang')->group(function () {
    Route::get('/barang', Barang\Index::class)->name('barang');
    Route::get('/barang/daftar', ProductListController::class)
        ->middleware('throttle:120,1')
        ->name('barang.list');
    Route::post('/barang', [ProductController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('barang.store');
    Route::put('/barang/{product}', [ProductController::class, 'update'])
        ->whereNumber('product')
        ->middleware('throttle:60,1')
        ->name('barang.update');
    Route::get('/barang/stok-menipis', [StockAlertController::class, 'lowStock'])->name('barang.low-stock');
    Route::get('/barang/batas-minimum', [StockAlertController::class, 'minStocks'])->name('barang.min-stock');
    Route::put('/barang/batas-minimum', [StockAlertController::class, 'updateMinStocks'])
        ->middleware('throttle:30,1')
        ->name('barang.min-stock.update');
});

Route::middleware('menu:transaksi')->group(function () {
    Route::get('/transaksi', Transaksi\Index::class)->name('transaksi');
    Route::get('/transaksi/daftar', [TransactionListController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('transaksi.list');
    Route::get('/transaksi/ringkasan', [TransactionListController::class, 'summary'])
        ->middleware('throttle:120,1')
        ->name('transaksi.summary');
    Route::get('/transaksi/{transaction}', Transaksi\Show::class)
        ->whereNumber('transaction')
        ->name('transaksi.show');
    Route::get('/transaksi/{transaction}/rincian', TransactionDetailController::class)
        ->whereNumber('transaction')
        ->name('transaksi.detail');
});

Route::middleware('menu:anggota')->group(function () {
    Route::get('/anggota', Anggota\Index::class)->name('anggota');
    Route::get('/anggota/daftar', [MemberController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('anggota.list');
    Route::get('/anggota/cari', [MemberController::class, 'search'])
        ->middleware('throttle:120,1')
        ->name('anggota.search');
    Route::get('/anggota/kode-baru', [MemberController::class, 'nextCode'])->name('anggota.next-code');
    Route::post('/anggota', [MemberController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('anggota.store');
    Route::get('/anggota/{member}/data', [MemberController::class, 'show'])
        ->whereNumber('member')
        ->name('anggota.data');
    Route::put('/anggota/{member}', [MemberController::class, 'update'])
        ->whereNumber('member')
        ->middleware('throttle:60,1')
        ->name('anggota.update');
    Route::delete('/anggota/{member}', [MemberController::class, 'destroy'])
        ->whereNumber('member')
        ->middleware('throttle:30,1')
        ->name('anggota.destroy');
    Route::get('/anggota/{member}/tabungan', [SavingsController::class, 'index'])
        ->whereNumber('member')
        ->middleware('throttle:120,1')
        ->name('anggota.savings');
    Route::post('/anggota/{member}/tabungan', [SavingsController::class, 'store'])
        ->whereNumber('member')
        ->middleware('throttle:60,1')
        ->name('anggota.savings.store');
    Route::get('/anggota/{member}', Anggota\Show::class)
        ->whereNumber('member')
        ->name('anggota.show');
});

Route::middleware('menu:laporan')->group(function () {
    Route::get('/laporan', Laporan\Index::class)->name('laporan');
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/laporan/hari-ini', [SalesReportController::class, 'today'])->name('laporan.today');
        Route::get('/laporan/ringkasan', [SalesReportController::class, 'summary'])->name('laporan.summary');
        Route::get('/laporan/grafik', [SalesReportController::class, 'chart'])->name('laporan.chart');
    });
    Route::get('/laporan/unduh', SalesExportController::class)
        ->middleware('throttle:20,1')
        ->name('laporan.export');
});

Route::middleware(['menu:pengguna', 'peran:admin'])->group(function () {
    Route::get('/pengguna', Pengguna\Index::class)->name('pengguna');
    Route::get('/pengguna/daftar', [UserController::class, 'index'])->name('pengguna.list');
    Route::get('/pengguna/peran', [UserController::class, 'roles'])->name('pengguna.roles');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/pengguna', [UserController::class, 'store'])->name('pengguna.store');
        Route::put('/pengguna/peran/{role:name}', [UserController::class, 'updateRole'])->name('pengguna.roles.update');
        Route::put('/pengguna/{user}', [UserController::class, 'update'])->whereNumber('user')->name('pengguna.update');
        Route::post('/pengguna/{user}/status', [UserController::class, 'toggle'])->whereNumber('user')->name('pengguna.toggle');
    });
});
Route::get('/audit', Audit\Index::class)->middleware(['menu:audit', 'peran:admin,pengurus'])->name('audit');

Route::middleware('menu:pengaturan')->group(function () {
    Route::get('/pengaturan', Pengaturan\Index::class)->name('pengaturan');
    Route::get('/pengaturan/profil', Pengaturan\Profile::class)->name('pengaturan.profil');
    Route::get('/pengaturan/struk', Pengaturan\Receipt::class)->name('pengaturan.struk');
    Route::get('/pengaturan/pajak', Pengaturan\Tax::class)->name('pengaturan.pajak');

    Route::get('/pengaturan/data', [SettingsController::class, 'index'])->name('pengaturan.data');
    Route::get('/pengaturan/data/{section}', [SettingsController::class, 'show'])->whereIn('section', SettingsService::SECTIONS)->name('pengaturan.data.show');
    Route::middleware(['can:ubah-pengaturan', 'throttle:30,1'])->group(function () {
        Route::post('/pengaturan/data/profile/logo', [LogoController::class, 'store'])->name('pengaturan.logo.store');
        Route::delete('/pengaturan/data/profile/logo', [LogoController::class, 'destroy'])->name('pengaturan.logo.destroy');
        Route::put('/pengaturan/data/{section}', [SettingsController::class, 'update'])->whereIn('section', SettingsService::SECTIONS)->name('pengaturan.data.update');
        Route::post('/pengaturan/data/{section}/reset', [SettingsController::class, 'reset'])->whereIn('section', SettingsService::SECTIONS)->name('pengaturan.data.reset');
    });
});
