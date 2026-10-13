<?php

use App\Http\Controllers\Akun\PasswordController;
use App\Http\Controllers\Akun\SessionController;
use App\Http\Controllers\Anggota\MemberController;
use App\Http\Controllers\Anggota\SavingsController;
use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Audit\TrashController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Barang\CategoryController;
use App\Http\Controllers\Barang\ImportTemplateController;
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
use App\Http\Controllers\Promo\PromoController;
use App\Http\Controllers\Pengaturan\SettingsController;
use App\Http\Controllers\Pengguna\LockoutController;
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
use App\Livewire\Pengadaan;
use App\Livewire\Pengaturan;
use App\Livewire\Pengguna;
use App\Livewire\Promo;
use App\Livewire\Transaksi;
use App\Services\LogoService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;

// Halaman petugas dijaga per menu: `menu:<kunci>` = wajib masuk + peran boleh membuka menu
// itu (App\Support\Menu, tabel roles.permissions); `peran:<nama>` = pembatas tambahan.
// Ikon tab: logo koperasi yang diunggah, atau lambang bawaan (SVG) bila belum ada logo.
Route::get('/favicon.ico', function () {
    $logo = \App\Support\MockData::profile()['logo'] ?? null;

    return $logo ? redirect($logo, 302)->header('Cache-Control', 'public, max-age=3600') : redirect()->route('favicon');
})->name('favicon.ico');
Route::get('/favicon.svg', fn () => response(view('components.brand.mark', ['attributes' => new \Illuminate\View\ComponentAttributeBag(['xmlns' => 'http://www.w3.org/2000/svg'])])->render(), 200, [
    'Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400',
]))->name('favicon');

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
    // Penanda petugas masih di depan layar (gerak/ketik tanpa permintaan lain); EnsureActiveSession mencatat aktivitasnya.
    Route::post('/akun/sesi/aktif', fn () => response()->noContent())->middleware('throttle:60,1')->name('akun.sessions.ping');
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
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/promo/data', [PromoController::class, 'index'])->name('promo.data');
        Route::get('/promo/jadwal/data', [PromoController::class, 'schedule'])->name('promo.schedule.data');
        Route::get('/promo/{promo}/dampak', [PromoController::class, 'impact'])->whereNumber('promo')->name('promo.impact');
    });
});

Route::middleware('menu:pengadaan')->group(function () {
    Route::get('/pengadaan', Pengadaan\Index::class)->name('pengadaan');
    Route::get('/pengadaan/pesanan', Pengadaan\Orders::class)->name('pengadaan.pesanan');
    Route::get('/pengadaan/pesanan/baru', Pengadaan\OrderForm::class)->name('pengadaan.pesanan.buat');
    Route::get('/pengadaan/penerimaan', Pengadaan\Receiving::class)->name('pengadaan.penerimaan');
    Route::get('/pengadaan/hutang', Pengadaan\Payables::class)->name('pengadaan.hutang');
    Route::get('/pengadaan/hutang/bayar', Pengadaan\PaymentForm::class)->name('pengadaan.hutang.bayar');
    Route::get('/pengadaan/supplier', Pengadaan\Suppliers::class)->name('pengadaan.supplier');
    Route::get('/pengadaan/supplier/baru', Pengadaan\SupplierForm::class)->name('pengadaan.supplier.buat');
    Route::get('/pengadaan/supplier/{supplier}/ubah', Pengadaan\SupplierForm::class)->whereNumber('supplier')->name('pengadaan.supplier.ubah');
});

Route::middleware('menu:barang')->group(function () {
    Route::get('/barang', Barang\Index::class)->name('barang');
    Route::get('/barang/kategori', Barang\Catalog::class)->name('barang.kategori');
    Route::get('/barang/kategori/data', [CategoryController::class, 'index'])->name('barang.kategori.data');
    Route::middleware(['can:kelola-katalog', 'throttle:60,1'])->group(function () {
        Route::post('/barang/kategori', [CategoryController::class, 'store'])->name('barang.kategori.store');
        Route::put('/barang/kategori/urutan', [CategoryController::class, 'reorder'])->name('barang.kategori.reorder');
        Route::put('/barang/kategori/{category}', [CategoryController::class, 'update'])->whereNumber('category')->name('barang.kategori.update');
        Route::delete('/barang/kategori/{category}', [CategoryController::class, 'destroy'])->whereNumber('category')->name('barang.kategori.destroy');
    });
    Route::get('/barang/impor/templat/{format}', ImportTemplateController::class)->whereIn('format', ['csv', 'xlsx'])->name('barang.impor.templat');
    Route::get('/barang/impor', Barang\Import::class)->middleware('peran:admin,pengurus')->name('barang.impor');
    Route::get('/barang/impor/{import}/pratinjau', Barang\ImportPreview::class)->whereNumber('import')
        ->middleware('peran:admin,pengurus')->name('barang.impor.pratinjau');
    Route::get('/barang/impor/riwayat', Barang\ImportHistory::class)->middleware('peran:admin,pengurus')->name('barang.impor.riwayat');
    Route::get('/barang/impor/{import}', Barang\ImportDetail::class)->whereNumber('import')
        ->middleware('peran:admin,pengurus')->name('barang.impor.detail');
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
        Route::get('/laporan/diskon', [SalesReportController::class, 'discounts'])->name('laporan.discounts');
    });
    Route::get('/laporan/unduh', SalesExportController::class)
        ->middleware('throttle:20,1')
        ->name('laporan.export');
});

Route::middleware(['menu:pengguna', 'peran:admin'])->group(function () {
    Route::get('/pengguna', Pengguna\Index::class)->name('pengguna');
    Route::get('/pengguna/terkunci', Pengguna\Locked::class)->name('pengguna.terkunci');
    Route::get('/pengguna/terkunci/data', [LockoutController::class, 'index'])->name('pengguna.terkunci.data');
    Route::post('/pengguna/terkunci/{lockout}/buka', [LockoutController::class, 'unlock'])
        ->whereNumber('lockout')->middleware('throttle:30,1')->name('pengguna.terkunci.unlock');
    Route::get('/pengguna/daftar', [UserController::class, 'index'])->name('pengguna.list');
    Route::get('/pengguna/peran', [UserController::class, 'roles'])->name('pengguna.roles');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/pengguna', [UserController::class, 'store'])->name('pengguna.store');
        Route::put('/pengguna/peran/{role:name}', [UserController::class, 'updateRole'])->name('pengguna.roles.update');
        Route::put('/pengguna/{user}', [UserController::class, 'update'])->whereNumber('user')->name('pengguna.update');
        Route::post('/pengguna/{user}/status', [UserController::class, 'toggle'])->whereNumber('user')->name('pengguna.toggle');
    });
});
Route::middleware(['menu:audit', 'peran:admin,pengurus'])->group(function () {
    Route::get('/audit', Audit\Index::class)->name('audit');
    Route::get('/audit/perubahan', Audit\Changes::class)->name('audit.perubahan');
    Route::get('/audit/terhapus', Audit\Trash::class)->name('audit.terhapus');
    Route::get('/audit/data', [AuditLogController::class, 'index'])->name('audit.data');
    Route::get('/audit/perubahan/data', [AuditLogController::class, 'changes'])->name('audit.perubahan.data');
    Route::get('/audit/terhapus/data', [TrashController::class, 'index'])->name('audit.terhapus.data');
    Route::post('/audit/terhapus/pulihkan', [TrashController::class, 'restore'])
        ->middleware(['can:pulihkan-data', 'throttle:30,1'])->name('audit.terhapus.restore');
});

Route::middleware('menu:pengaturan')->group(function () {
    Route::get('/pengaturan', Pengaturan\Index::class)->name('pengaturan');
    Route::get('/pengaturan/profil', Pengaturan\Profile::class)->name('pengaturan.profil');
    Route::get('/pengaturan/struk', Pengaturan\Receipt::class)->name('pengaturan.struk');
    Route::get('/pengaturan/pajak', Pengaturan\Tax::class)->name('pengaturan.pajak');
    Route::get('/pengaturan/keamanan', Pengaturan\Security::class)->name('pengaturan.keamanan');

    Route::get('/pengaturan/data', [SettingsController::class, 'index'])->name('pengaturan.data');
    Route::get('/pengaturan/data/{section}', [SettingsController::class, 'show'])->whereIn('section', SettingsService::SECTIONS)->name('pengaturan.data.show');
    Route::middleware(['can:ubah-pengaturan', 'throttle:30,1'])->group(function () {
        Route::post('/pengaturan/data/profile/logo', [LogoController::class, 'store'])->name('pengaturan.logo.store');
        Route::delete('/pengaturan/data/profile/logo', [LogoController::class, 'destroy'])->name('pengaturan.logo.destroy');
        Route::put('/pengaturan/data/{section}', [SettingsController::class, 'update'])->whereIn('section', SettingsService::SECTIONS)->name('pengaturan.data.update');
        Route::post('/pengaturan/data/{section}/reset', [SettingsController::class, 'reset'])->whereIn('section', SettingsService::SECTIONS)->name('pengaturan.data.reset');
    });
});
