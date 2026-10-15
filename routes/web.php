<?php

use App\Http\Controllers\Akun\MenuController;
use App\Http\Controllers\Akun\PasswordController;
use App\Http\Controllers\Akun\SessionController;
use App\Http\Controllers\Anggota\CreditController;
use App\Http\Controllers\Anggota\MemberController;
use App\Http\Controllers\Anggota\SavingsController;
use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Audit\IntegrityController;
use App\Http\Controllers\Audit\RoleImpersonationTrailController;
use App\Http\Controllers\Audit\TrashController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Barang\BarcodeController;
use App\Http\Controllers\Barang\BarcodeImageController;
use App\Http\Controllers\Barang\CategoryController;
use App\Http\Controllers\Barang\ImportTemplateController;
use App\Http\Controllers\Barang\LabelPrintController;
use App\Http\Controllers\Barang\ProductController;
use App\Http\Controllers\Barang\ProductImportController;
use App\Http\Controllers\Barang\ProductListController;
use App\Http\Controllers\Barang\StockAlertController;
use App\Http\Controllers\BerandaController;
use App\Http\Controllers\Impersonasi\ImpersonationController;
use App\Http\Controllers\Impersonasi\StartMemberController;
use App\Http\Controllers\Impersonasi\StartStaffController;
use App\Http\Controllers\Impersonasi\StopController;
use App\Http\Controllers\Kas\CashExportController;
use App\Http\Controllers\Kas\CashMovementController;
use App\Http\Controllers\Kas\CashReportController;
use App\Http\Controllers\Kas\ShiftController;
use App\Http\Controllers\Kas\ShiftHistoryController;
use App\Http\Controllers\Kasir\CheckoutController;
use App\Http\Controllers\Kasir\CouponCheckController;
use App\Http\Controllers\Kasir\MemberPaymentController;
use App\Http\Controllers\Kasir\ProductSearchController;
use App\Http\Controllers\Kasir\TransactionReceiptController;
use App\Http\Controllers\Laporan\SalesExportController;
use App\Http\Controllers\Laporan\SalesReportController;
use App\Http\Controllers\Notifikasi\AnnouncementController;
use App\Http\Controllers\Notifikasi\NotificationController;
use App\Http\Controllers\Pengadaan\GoodsReceiptController;
use App\Http\Controllers\Pengadaan\PurchaseOrderController;
use App\Http\Controllers\Pengadaan\SupplierBillController;
use App\Http\Controllers\Pengadaan\SupplierController;
use App\Http\Controllers\Pengaturan\LogoController;
use App\Http\Controllers\Pengaturan\SettingsController;
use App\Http\Controllers\Pengguna\LockoutController;
use App\Http\Controllers\Pengguna\SessionAdminController;
use App\Http\Controllers\Pengguna\UserController;
use App\Http\Controllers\Pinjaman\ArrearsController;
use App\Http\Controllers\Pinjaman\AuditTrailController;
use App\Http\Controllers\Pinjaman\DisbursementController;
use App\Http\Controllers\Pinjaman\DisbursementProofController;
use App\Http\Controllers\Pinjaman\InstallmentController;
use App\Http\Controllers\Pinjaman\LoanApplicationController;
use App\Http\Controllers\Pinjaman\LoanPaymentController;
use App\Http\Controllers\Pinjaman\LoanPaymentCorrectionController;
use App\Http\Controllers\Pinjaman\RecapController;
use App\Http\Controllers\Pinjaman\RecapExportController;
use App\Http\Controllers\Portal\CartController;
use App\Http\Controllers\Portal\DeductionController;
use App\Http\Controllers\Portal\HistoryController;
use App\Http\Controllers\Portal\LoanController;
use App\Http\Controllers\Portal\OrderController;
use App\Http\Controllers\Portal\PinController;
use App\Http\Controllers\Promo\PromoController;
use App\Http\Controllers\Simpanan\MemberSavingController;
use App\Http\Controllers\Simpanan\MonthlyDuesController;
use App\Http\Controllers\Simpanan\MonthlyDuesExportController;
use App\Http\Controllers\Simpanan\SavingTypeController;
use App\Http\Controllers\Sistem\BackupController;
use App\Http\Controllers\Transaksi\MemberOrderController;
use App\Http\Controllers\Transaksi\ReturnController;
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
use App\Livewire\Pinjaman\Applications;
use App\Livewire\Pinjaman\Arrears;
use App\Livewire\Pinjaman\AuditTrail;
use App\Livewire\Pinjaman\Disbursements;
use App\Livewire\Pinjaman\Index;
use App\Livewire\Pinjaman\Installments;
use App\Livewire\Pinjaman\PaymentHistory;
use App\Livewire\Pinjaman\Recap;
use App\Livewire\Pinjaman\Show;
use App\Livewire\Portal\Checkout;
use App\Livewire\Portal\Guide;
use App\Livewire\Portal\Home;
use App\Livewire\Portal\Loans;
use App\Livewire\Portal\Orders;
use App\Livewire\Portal\OrderShow;
use App\Livewire\Portal\Purchases;
use App\Livewire\Portal\Savings;
use App\Livewire\Portal\Shop;
use App\Livewire\Promo;
use App\Livewire\Simpanan;
use App\Livewire\Sistem;
use App\Livewire\Transaksi;
use App\Models\ReportExport;
use App\Services\AnnouncementService;
use App\Services\HealthCheckService;
use App\Services\ImpersonationService;
use App\Services\LogoService;
use App\Services\PortalAuthService;
use App\Services\QrisImageService;
use App\Services\SettingsService;
use App\Support\ImpersonationSession;
use App\Support\MockData;
use App\Support\OpeningHours;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\ComponentAttributeBag;

// Halaman petugas dijaga per menu: `menu:<kunci>` = wajib masuk + peran boleh membuka menu
// itu (App\Support\Menu, tabel roles.permissions); `peran:<nama>` = pembatas tambahan.
// Ikon tab: logo koperasi yang diunggah, atau lambang bawaan (SVG) bila belum ada logo.
Route::get('/favicon.ico', function () {
    $logo = MockData::profile()['logo'] ?? null;

    return $logo ? redirect($logo, 302)->header('Cache-Control', 'public, max-age=3600') : redirect()->route('favicon');
})->name('favicon.ico');
Route::get('/favicon.svg', fn () => response(view('components.brand.mark', ['attributes' => new ComponentAttributeBag(['xmlns' => 'http://www.w3.org/2000/svg'])])->render(), 200, [
    'Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400',
]))->name('favicon');

Route::get('/', BerandaController::class)->name('beranda');
// Status buka/tutup koperasi (JSON publik) dari jam operasional di Pengaturan → Profil.
Route::get('/status-koperasi', fn () => response()->json(['data' => OpeningHours::snapshot(
    MockData::profile()['schedule'], now()
)])->header('Cache-Control', 'public, max-age=60'))->middleware('throttle:60,1')->name('status-koperasi');
Route::get('/pengumuman', fn () => view('pengumuman', [
    'profile' => MockData::profile(),
    'announcements' => app(AnnouncementService::class)->publicList(100),
]))->name('pengumuman.publik');
// Pengumuman publik (JSON, tanpa login): hanya isi yang memang ditandai tampil di beranda & masih berlaku.
Route::get('/pengumuman/data', fn () => response()->json([
    'data' => collect(app(AnnouncementService::class)->publicList(100))
        ->map(fn ($a) => Arr::only($a, ['id', 'title', 'message', 'expires_at', 'created_at']))->values(),
])->header('Cache-Control', 'public, max-age=60'))->middleware('throttle:60,1')->name('pengumuman.publik.data');
Route::get('/logo/{file}', [LogoController::class, 'show'])->where('file', LogoService::FILE_PATTERN)->name('logo');
Route::get('/qris/{file}', function (string $file) {
    $disk = Storage::disk(QrisImageService::DISK);
    abort_unless($disk->exists(QrisImageService::path($file)), 404);

    return $disk->response(QrisImageService::path($file), $file, [
        'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'",
    ]);
})->middleware('auth:web,member')->where('file', QrisImageService::FILE_PATTERN)->name('qris.image'); // petugas & anggota portal

Route::get('/masuk', Login::class)->name('login');

// Portal anggota (guard `member`, terpisah dari petugas).
Route::get('/portal/masuk', App\Livewire\Portal\Login::class)->middleware('guest:member')->name('portal.login');
Route::middleware('portal')->group(function () { // bukan auth:member — guard bawaan tetap milik petugas
    Route::get('/portal', Home::class)->name('portal');
    Route::get('/portal/simpanan', Savings::class)->name('portal.simpanan');
    Route::get('/portal/pinjaman', Loans::class)->name('portal.pinjaman');
    Route::get('/portal/pinjaman/data', [LoanController::class, 'index'])->middleware('throttle:120,1')->name('portal.pinjaman.data');
    Route::get('/portal/pinjaman/sisa', [LoanController::class, 'outstanding'])->middleware('throttle:120,1')->name('portal.pinjaman.sisa');
    Route::get('/portal/pinjaman/{loan}/riwayat', [LoanController::class, 'history'])->whereNumber('loan')->middleware('throttle:120,1')->name('portal.pinjaman.riwayat');
    Route::get('/portal/simpanan/mutasi', [MemberSavingController::class, 'myEntries'])->name('portal.simpanan.mutasi');
    Route::get('/portal/simpanan/data', [MemberSavingController::class, 'mine'])->name('portal.simpanan.data');
    Route::get('/portal/riwayat/data', [HistoryController::class, 'transactions'])->name('portal.riwayat.data');
    Route::get('/portal/pesanan/data', [HistoryController::class, 'orders'])->name('portal.pesanan.data');
    Route::get('/portal/pesanan/{order}/data', [HistoryController::class, 'order'])->whereNumber('order')->name('portal.pesanan.detail');
    Route::get('/portal/potong-saldo', DeductionController::class)->name('portal.potong-saldo');
    Route::get('/portal/pin/status', [PinController::class, 'status'])->name('portal.pin.status');
    Route::post('/portal/pesanan/{order}/bayar-saldo', [OrderController::class, 'payWithBalance'])
        ->whereNumber('order')->middleware('throttle:20,1')->name('portal.pesanan.bayar-saldo');
    Route::get('/portal/keranjang', [CartController::class, 'show'])->name('portal.keranjang');
    Route::put('/portal/keranjang', [CartController::class, 'update'])->middleware('throttle:120,1')->name('portal.keranjang.update');
    Route::delete('/portal/keranjang', [CartController::class, 'clear'])->name('portal.keranjang.clear');
    Route::post('/portal/pesanan', [OrderController::class, 'store'])->middleware('throttle:20,1')->name('portal.pesanan.store');
    Route::post('/portal/pin/verifikasi', [PinController::class, 'verify'])->middleware('throttle:20,1')->name('portal.pin.verify');
    Route::get('/portal/riwayat', Purchases::class)->name('portal.riwayat');
    Route::get('/portal/belanja', Shop::class)->name('portal.belanja');
    Route::get('/portal/checkout', Checkout::class)->name('portal.checkout');
    Route::get('/portal/pesanan', Orders::class)->name('portal.pesanan');
    Route::get('/portal/pesanan/{order}', OrderShow::class)->whereNumber('order')->name('portal.pesanan.show');
    Route::get('/portal/panduan', Guide::class)->name('portal.panduan');
    Route::post('/portal/keluar', function (Request $request) {
        if (ImpersonationSession::impersonatingMember()) { // admin: kembali ke akunnya, bukan keluar
            $svc = app(ImpersonationService::class);

            return $svc->stopResponse($svc->stop($request));
        }
        app(PortalAuthService::class)->logout();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    })->name('portal.logout');
});
Route::post('/masuk', LoginController::class)->middleware('throttle:30,1')->name('login.store');
Route::post('/keluar', LogoutController::class)->name('logout');
Route::post('/impersonasi/selesai', StopController::class)->middleware('throttle:30,1')->name('impersonasi.selesai');
// Akun sendiri: cukup sudah masuk (semua peran).
Route::get('/akun/kata-sandi', Akun\Password::class)->middleware(['menu', 'auth'])->name('akun.password');
Route::get('/panduan', App\Livewire\Panduan\Index::class)->middleware(['menu', 'auth'])->name('panduan');
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
    Route::get('/akun/menu', MenuController::class)->middleware(['menu', 'throttle:120,1'])->name('akun.menu');
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
    Route::get('/kasir/anggota/cari', [MemberPaymentController::class, 'search'])
        ->middleware('throttle:120,1')
        ->name('kasir.members.search');
    Route::get('/kasir/anggota/{member}/tabungan', [MemberPaymentController::class, 'savings'])
        ->whereNumber('member')
        ->middleware('throttle:120,1')
        ->name('kasir.members.savings');
    Route::get('/kasir/anggota/{member}/bon', [MemberPaymentController::class, 'credit'])
        ->whereNumber('member')
        ->middleware('throttle:120,1')
        ->name('kasir.members.credit');
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
    Route::get('/pengadaan/pesanan/data', [PurchaseOrderController::class, 'index'])->name('pengadaan.pesanan.data');
    Route::get('/pengadaan/pesanan/{order}/data', [PurchaseOrderController::class, 'show'])->whereNumber('order')->name('pengadaan.pesanan.show');
    Route::middleware(['can:kelola-pengadaan', 'throttle:60,1'])->group(function () {
        Route::post('/pengadaan/pesanan', [PurchaseOrderController::class, 'store'])->name('pengadaan.pesanan.store');
        Route::post('/pengadaan/pesanan/{order}/kirim', [PurchaseOrderController::class, 'send'])->whereNumber('order')->name('pengadaan.pesanan.send');
        Route::post('/pengadaan/pesanan/{order}/batal', [PurchaseOrderController::class, 'cancel'])->whereNumber('order')->name('pengadaan.pesanan.cancel');
        Route::post('/pengadaan/pesanan/{order}/terima', [GoodsReceiptController::class, 'store'])->whereNumber('order')->name('pengadaan.pesanan.receive');
    });
    Route::get('/pengadaan/pesanan/baru', Pengadaan\OrderForm::class)->middleware('can:kelola-pengadaan')->name('pengadaan.pesanan.buat');
    Route::get('/pengadaan/penerimaan', Pengadaan\Receiving::class)->middleware('can:kelola-pengadaan')->name('pengadaan.penerimaan');
    Route::get('/pengadaan/penerimaan/data', [GoodsReceiptController::class, 'index'])->name('pengadaan.penerimaan.data');
    Route::get('/pengadaan/hutang', Pengadaan\Payables::class)->name('pengadaan.hutang');
    Route::get('/pengadaan/hutang/data', [SupplierBillController::class, 'index'])->name('pengadaan.hutang.data');
    Route::get('/pengadaan/hutang/{bill}/data', [SupplierBillController::class, 'show'])->whereNumber('bill')->name('pengadaan.hutang.show');
    Route::post('/pengadaan/hutang/bayar', [SupplierBillController::class, 'pay'])
        ->middleware(['can:kelola-pengadaan', 'throttle:30,1'])->name('pengadaan.hutang.pay');
    Route::post('/pengadaan/hutang/pembayaran/{payment}/batal', [SupplierBillController::class, 'cancelPayment'])->whereNumber('payment')
        ->middleware(['peran:admin', 'throttle:30,1'])->name('pengadaan.hutang.payment.cancel');
    Route::get('/pengadaan/hutang/bayar', Pengadaan\PaymentForm::class)->middleware('can:kelola-pengadaan')->name('pengadaan.hutang.bayar');
    Route::get('/pengadaan/supplier', Pengadaan\Suppliers::class)->name('pengadaan.supplier');
    Route::get('/pengadaan/supplier/data', [SupplierController::class, 'index'])->name('pengadaan.supplier.data');
    Route::get('/pengadaan/supplier/{supplier}/data', [SupplierController::class, 'show'])->whereNumber('supplier')->name('pengadaan.supplier.show');
    Route::middleware(['can:kelola-pengadaan', 'throttle:60,1'])->group(function () {
        Route::post('/pengadaan/supplier', [SupplierController::class, 'store'])->name('pengadaan.supplier.store');
        Route::put('/pengadaan/supplier/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('pengadaan.supplier.update');
        Route::delete('/pengadaan/supplier/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->name('pengadaan.supplier.destroy');
    });
    Route::get('/pengadaan/supplier/baru', Pengadaan\SupplierForm::class)->middleware('can:kelola-pengadaan')->name('pengadaan.supplier.buat');
    Route::get('/pengadaan/supplier/{supplier}/ubah', Pengadaan\SupplierForm::class)->whereNumber('supplier')->middleware('can:kelola-pengadaan')->name('pengadaan.supplier.ubah');
});

Route::middleware('menu:barang')->group(function () {
    Route::get('/barang', Barang\Index::class)->name('barang');
    Route::get('/barang/kategori', Barang\Catalog::class)->name('barang.kategori');
    Route::get('/barang/riwayat-stok', Barang\StockHistory::class)->name('barang.riwayat-stok');
    Route::get('/barang/saran-restok', Barang\Restock::class)->name('barang.saran-restok');
    Route::get('/barang/barcode', Barang\Barcodes::class)->name('barang.barcode');
    Route::get('/barang/{product}/barcode.{format}', BarcodeImageController::class)
        ->whereNumber('product')->whereIn('format', ['svg', 'png'])->middleware('throttle:120,1')->name('barang.barcode.image');
    Route::middleware(['can:kelola-katalog', 'throttle:30,1'])->group(function () {
        Route::put('/barang/{product}/barcode', [BarcodeController::class, 'update'])->whereNumber('product')->name('barang.barcode.update');
        Route::post('/barang/barcode/massal', [BarcodeController::class, 'bulk'])->name('barang.barcode.bulk');
    });
    Route::get('/barang/barcode/label', Barang\Labels::class)->name('barang.label');
    Route::get('/barang/barcode/label/cetak', LabelPrintController::class)
        ->middleware('throttle:30,1')->name('barang.label.cetak');
    Route::get('/barang/barcode/label/data', [LabelPrintController::class, 'data'])
        ->middleware('throttle:60,1')->name('barang.label.data');
    Route::get('/barang/barcode/label/unduh', [LabelPrintController::class, 'pdf'])
        ->middleware('throttle:10,1')->name('barang.label.pdf');
    Route::get('/barang/kategori/data', [CategoryController::class, 'index'])->name('barang.kategori.data');
    Route::middleware(['can:kelola-katalog', 'throttle:60,1'])->group(function () {
        Route::post('/barang/kategori', [CategoryController::class, 'store'])->name('barang.kategori.store');
        Route::put('/barang/kategori/urutan', [CategoryController::class, 'reorder'])->name('barang.kategori.reorder');
        Route::put('/barang/kategori/{category}', [CategoryController::class, 'update'])->whereNumber('category')->name('barang.kategori.update');
        Route::delete('/barang/kategori/{category}', [CategoryController::class, 'destroy'])->whereNumber('category')->name('barang.kategori.destroy');
    });
    Route::post('/barang/impor', [ProductImportController::class, 'store'])
        ->middleware(['can:kelola-katalog', 'throttle:10,1'])->name('barang.impor.store');
    Route::get('/barang/impor/{import}/baris', [ProductImportController::class, 'rows'])->whereNumber('import')
        ->middleware('can:kelola-katalog')->name('barang.impor.rows');
    Route::middleware('can:kelola-katalog')->group(function () {
        Route::get('/barang/impor/data', [ProductImportController::class, 'index'])->name('barang.impor.data');
        Route::get('/barang/impor/{import}/data', [ProductImportController::class, 'show'])->whereNumber('import')->name('barang.impor.show');
        Route::get('/barang/impor/{import}/hasil', [ProductImportController::class, 'report'])->whereNumber('import')
            ->middleware('throttle:30,1')->name('barang.impor.report');
    });
    Route::middleware(['can:kelola-katalog', 'throttle:120,1'])->whereNumber(['import', 'line'])->group(function () {
        Route::put('/barang/impor/{import}/baris/{line}', [ProductImportController::class, 'updateRow'])->name('barang.impor.rows.update');
        Route::post('/barang/impor/{import}/baris/{line}/lewati', [ProductImportController::class, 'skipRow'])->name('barang.impor.rows.skip');
        Route::post('/barang/impor/{import}/lewati-masalah', [ProductImportController::class, 'skipProblems'])->name('barang.impor.skip-problems');
        Route::post('/barang/impor/{import}/simpan', [ProductImportController::class, 'commit'])->name('barang.impor.commit');
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
        ->middleware(['can:kelola-katalog', 'throttle:60,1'])
        ->name('barang.store');
    Route::get('/barang/saran-restok/data', [ProductController::class, 'restock'])->middleware('throttle:120,1')->name('barang.saran-restok.data');
    Route::get('/barang/riwayat-stok/data', [ProductController::class, 'allMovements'])->middleware('throttle:120,1')->name('barang.riwayat-stok.data');
    Route::get('/barang/{product}', [ProductController::class, 'show'])->whereNumber('product')->middleware('throttle:120,1')->name('barang.show');
    Route::get('/barang/{product}/riwayat-stok', [ProductController::class, 'movements'])->whereNumber('product')->middleware('throttle:120,1')->name('barang.movements');
    Route::put('/barang/{product}', [ProductController::class, 'update'])
        ->whereNumber('product')
        ->middleware(['can:kelola-katalog', 'throttle:60,1'])
        ->name('barang.update');
    Route::get('/barang/stok-menipis', [StockAlertController::class, 'lowStock'])->name('barang.low-stock');
    Route::get('/barang/batas-minimum', [StockAlertController::class, 'minStocks'])->name('barang.min-stock');
    Route::put('/barang/batas-minimum', [StockAlertController::class, 'updateMinStocks'])
        ->middleware(['can:kelola-katalog', 'throttle:30,1'])
        ->name('barang.min-stock.update');
});

Route::middleware('menu:transaksi')->group(function () {
    Route::get('/pesanan', Transaksi\MemberOrders::class)->name('pesanan');
    Route::get('/pesanan/data', [MemberOrderController::class, 'index'])->name('pesanan.data');
    Route::get('/pesanan/{order}/data', [MemberOrderController::class, 'show'])->whereNumber('order')->name('pesanan.detail');
    Route::post('/pesanan/{order}/bayar', [MemberOrderController::class, 'pay'])
        ->whereNumber('order')->middleware('throttle:60,1')->name('pesanan.bayar');
    Route::post('/pesanan/{order}/status', [MemberOrderController::class, 'status'])
        ->whereNumber('order')->middleware('throttle:60,1')->name('pesanan.status');
    Route::post('/pesanan/{order}/batal', [MemberOrderController::class, 'cancel'])
        ->whereNumber('order')->middleware('throttle:30,1')->name('pesanan.batal');
    Route::get('/transaksi', Transaksi\Index::class)->name('transaksi');
    Route::get('/transaksi/daftar', [TransactionListController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('transaksi.list');
    Route::get('/transaksi/ringkasan', [TransactionListController::class, 'summary'])
        ->middleware('throttle:120,1')
        ->name('transaksi.summary');
    Route::get('/transaksi/retur', Transaksi\Returns::class)->name('transaksi.retur');
    Route::get('/transaksi/retur/riwayat', Transaksi\ReturnHistory::class)->name('transaksi.retur.riwayat');
    Route::get('/transaksi/{transaction}', Transaksi\Show::class)
        ->whereNumber('transaction')
        ->name('transaksi.show');
    Route::get('/transaksi/{transaction}/retur', Transaksi\ReturnForm::class)
        ->whereNumber('transaction')
        ->name('transaksi.retur.buat');
    Route::post('/transaksi/{transaction}/retur', [ReturnController::class, 'store'])
        ->whereNumber('transaction')
        ->middleware('throttle:30,1')
        ->name('transaksi.retur.store');
    Route::get('/transaksi/{transaction}/batal', Transaksi\VoidForm::class)
        ->whereNumber('transaction')
        ->name('transaksi.retur.batal');
    Route::post('/transaksi/{transaction}/batal', [ReturnController::class, 'void'])
        ->whereNumber('transaction')
        ->middleware('throttle:30,1')
        ->name('transaksi.retur.void');
    Route::get('/transaksi/retur/daftar', [ReturnController::class, 'index'])->middleware('throttle:120,1')->name('transaksi.retur.list');
    Route::get('/transaksi/retur/riwayat/data', [ReturnController::class, 'history'])->middleware('throttle:120,1')->name('transaksi.retur.history');
    Route::get('/transaksi/retur/riwayat/unduh', [ReturnController::class, 'export'])->middleware('throttle:20,1')->name('transaksi.retur.export');
    Route::get('/transaksi/retur/menunggu', [ReturnController::class, 'queue'])->middleware(['can:setujui-retur', 'throttle:120,1'])->name('transaksi.retur.queue');
    Route::get('/transaksi/retur/{return}', [ReturnController::class, 'show'])
        ->whereNumber('return')
        ->name('transaksi.retur.show');
    Route::middleware(['can:setujui-retur', 'throttle:60,1'])->group(function () {
        Route::post('/transaksi/retur/{return}/setujui', [ReturnController::class, 'approve'])->whereNumber('return')->name('transaksi.retur.approve');
        Route::post('/transaksi/retur/{return}/tolak', [ReturnController::class, 'reject'])->whereNumber('return')->name('transaksi.retur.reject');
    });
    Route::get('/transaksi/{transaction}/rincian', TransactionDetailController::class)
        ->whereNumber('transaction')
        ->name('transaksi.detail');
});

Route::middleware('menu:anggota')->group(function () {
    Route::get('/anggota', Anggota\Index::class)->name('anggota');
    Route::get('/anggota/piutang', Anggota\Piutang::class)->name('anggota.piutang');
    Route::get('/anggota/piutang/daftar', [CreditController::class, 'debtors'])
        ->middleware('throttle:120,1')
        ->name('anggota.piutang.list');
    Route::get('/anggota/piutang/riwayat', [CreditController::class, 'history'])
        ->middleware('throttle:120,1')
        ->name('anggota.piutang.history');
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
    Route::patch('/anggota/{member}/status', [MemberController::class, 'status'])
        ->whereNumber('member')
        ->middleware('throttle:60,1')
        ->name('anggota.status');
    Route::delete('/anggota/{member}', [MemberController::class, 'destroy'])
        ->whereNumber('member')
        ->middleware(['can:kelola-anggota', 'throttle:30,1'])
        ->name('anggota.destroy');
    Route::get('/anggota/{member}/tabungan', [SavingsController::class, 'index'])
        ->whereNumber('member')
        ->middleware('throttle:120,1')
        ->name('anggota.savings');
    Route::post('/anggota/{member}/tabungan', [SavingsController::class, 'store'])
        ->whereNumber('member')
        ->middleware('throttle:60,1')
        ->name('anggota.savings.store');
    Route::get('/anggota/{member}/bon', [CreditController::class, 'index'])
        ->whereNumber('member')
        ->middleware('throttle:120,1')
        ->name('anggota.credits');
    Route::post('/anggota/{member}/bon/pelunasan', [CreditController::class, 'repay'])
        ->whereNumber('member')
        ->middleware('throttle:60,1')
        ->name('anggota.credits.repay');
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
        Route::get('/laporan/barang-terjual', [SalesReportController::class, 'soldProducts'])->name('laporan.sold');
    });
    Route::get('/laporan/unduh', SalesExportController::class)
        ->middleware('throttle:20,1')
        ->name('laporan.export');
    Route::get('/laporan/unduhan/{export}', function (Request $request, ReportExport $export) {
        abort_unless($export->user_id === $request->user()->id && $export->type === 'penjualan', 404);
        abort_unless($export->status === 'done' && $export->file_path && Storage::disk('local')->exists($export->file_path), 409, 'Berkas belum siap atau sudah dibersihkan.');

        return Storage::disk('local')->download($export->file_path, $export->file_name);
    })->whereNumber('export')->middleware('throttle:30,1')->name('laporan.unduhan');
});

// Pinjaman: hanya Pengurus & Admin pemegang menu Pinjaman (kasir & portal anggota tidak), dicek juga di setiap aksi Livewire.
Route::middleware(['menu:pinjaman', 'peran:admin,pengurus', 'can:kelola-pinjaman'])->group(function () {
    Route::get('/pinjaman', Index::class)->name('pinjaman');
    Route::get('/pinjaman/pengajuan', Applications::class)->name('pinjaman.pengajuan');
    Route::get('/pinjaman/pengajuan/data', [LoanApplicationController::class, 'index'])->middleware('throttle:120,1')->name('pinjaman.pengajuan.data');
    Route::post('/pinjaman/pengajuan', [LoanApplicationController::class, 'store'])->middleware('throttle:30,1')->name('pinjaman.pengajuan.store');
    Route::post('/pinjaman/pengajuan/{loan}/setujui', [LoanApplicationController::class, 'approve'])->whereNumber('loan')->middleware('throttle:30,1')->name('pinjaman.pengajuan.approve');
    Route::post('/pinjaman/pengajuan/{loan}/tolak', [LoanApplicationController::class, 'reject'])->whereNumber('loan')->middleware('throttle:30,1')->name('pinjaman.pengajuan.reject');
    Route::put('/pinjaman/pengajuan/{loan}', [LoanApplicationController::class, 'update'])->whereNumber('loan')->middleware('throttle:30,1')->name('pinjaman.pengajuan.update');
    Route::post('/pinjaman/pengajuan/{loan}/batal', [LoanApplicationController::class, 'cancel'])->whereNumber('loan')->middleware('throttle:30,1')->name('pinjaman.pengajuan.cancel');
    Route::get('/pinjaman/pencairan', Disbursements::class)->name('pinjaman.pencairan');
    Route::get('/pinjaman/angsuran', Installments::class)->name('pinjaman.angsuran');
    Route::get('/pinjaman/angsuran/riwayat', PaymentHistory::class)->name('pinjaman.angsuran.riwayat');
    Route::get('/pinjaman/tunggakan', Arrears::class)->name('pinjaman.tunggakan');
    Route::get('/pinjaman/tunggakan/data', [ArrearsController::class, 'index'])->middleware('throttle:120,1')->name('pinjaman.tunggakan.data');
    Route::get('/pinjaman/audit', AuditTrail::class)->name('pinjaman.audit');
    Route::get('/pinjaman/audit/data', [AuditTrailController::class, 'index'])->middleware('throttle:120,1')->name('pinjaman.audit.data');
    Route::get('/pinjaman/audit/transaksi', [AuditTrailController::class, 'ledger'])->middleware('throttle:120,1')->name('pinjaman.audit.transaksi');
    Route::get('/pinjaman/audit/ekspor', [AuditTrailController::class, 'export'])->middleware('throttle:20,1')->name('pinjaman.audit.ekspor');
    Route::get('/pinjaman/audit/verifikasi', [AuditTrailController::class, 'verify'])->middleware('throttle:verifikasi-audit-pinjaman')->name('pinjaman.audit.verifikasi');
    Route::post('/pinjaman/audit/verifikasi-berkas', [AuditTrailController::class, 'verifyFile'])->middleware('throttle:20,1')->name('pinjaman.audit.verifikasi-berkas');
    Route::get('/pinjaman/rekap', Recap::class)->middleware('can:lihat-rekap-pinjaman')->name('pinjaman.rekap');
    Route::get('/pinjaman/rekap/berjalan', [RecapController::class, 'running'])->middleware('throttle:120,1')->middleware('can:lihat-rekap-pinjaman')->name('pinjaman.rekap.berjalan');
    Route::get('/pinjaman/rekap/data', [RecapController::class, 'period'])->middleware('throttle:120,1')->middleware('can:lihat-rekap-pinjaman')->name('pinjaman.rekap.data');
    Route::get('/pinjaman/rekap/metode', [RecapController::class, 'methods'])->middleware('throttle:120,1')->middleware('can:lihat-rekap-pinjaman')->name('pinjaman.rekap.metode');
    Route::post('/pinjaman/rekap/ekspor', [RecapExportController::class, 'store'])->middleware('throttle:10,1')->middleware('can:lihat-rekap-pinjaman')->name('pinjaman.rekap.ekspor');
    Route::get('/pinjaman/rekap/ekspor', [RecapExportController::class, 'index'])->middleware('throttle:120,1')->middleware('can:lihat-rekap-pinjaman')->name('pinjaman.rekap.ekspor.index');
    Route::get('/pinjaman/rekap/ekspor/{export}', [RecapExportController::class, 'show'])->whereNumber('export')->middleware('throttle:120,1')->middleware('can:lihat-rekap-pinjaman')->name('pinjaman.rekap.ekspor.show');
    Route::get('/pinjaman/rekap/ekspor/{export}/unduh', [RecapExportController::class, 'download'])->whereNumber('export')->middleware('throttle:30,1')->middleware('can:lihat-rekap-pinjaman')->name('pinjaman.rekap.ekspor.unduh');
    Route::get('/pinjaman/angsuran/data', [InstallmentController::class, 'index'])->middleware('throttle:120,1')->name('pinjaman.angsuran.data');
    Route::post('/pinjaman/angsuran/pembayaran/{payment}/koreksi', [LoanPaymentCorrectionController::class, 'store'])->whereNumber('payment')->middleware('throttle:30,1')->name('pinjaman.angsuran.koreksi');
    Route::get('/pinjaman/{loan}/angsuran/jejak', [LoanPaymentCorrectionController::class, 'trail'])->whereNumber('loan')->middleware('throttle:120,1')->name('pinjaman.angsuran.jejak');
    Route::get('/pinjaman/pencairan/data', [DisbursementController::class, 'index'])->middleware('throttle:120,1')->name('pinjaman.pencairan.data');
    Route::get('/pinjaman/pencairan/{disbursement}/bukti', DisbursementProofController::class)
        ->whereNumber('disbursement')->middleware('throttle:60,1')->name('pinjaman.pencairan.bukti');
    Route::post('/pinjaman/pencairan/{disbursement}/bukti', [DisbursementController::class, 'updateProof'])
        ->whereNumber('disbursement')->middleware('throttle:30,1')->name('pinjaman.pencairan.bukti.update');
    Route::get('/pinjaman/ringkasan', [App\Http\Controllers\Pinjaman\LoanController::class, 'summary'])->middleware('throttle:120,1')->name('pinjaman.summary');
    Route::get('/pinjaman/daftar', [App\Http\Controllers\Pinjaman\LoanController::class, 'index'])->middleware('throttle:120,1')->name('pinjaman.list');
    Route::get('/pinjaman/{loan}/pencairan', [DisbursementController::class, 'show'])->whereNumber('loan')->middleware('throttle:120,1')->name('pinjaman.pencairan.show');
    Route::post('/pinjaman/{loan}/pencairan', [DisbursementController::class, 'store'])->whereNumber('loan')->middleware('throttle:30,1')->name('pinjaman.pencairan.store');
    Route::get('/pinjaman/{loan}/data', [App\Http\Controllers\Pinjaman\LoanController::class, 'show'])->whereNumber('loan')->middleware('throttle:120,1')->name('pinjaman.data');
    Route::get('/pinjaman/{loan}/audit', [App\Http\Controllers\Pinjaman\LoanController::class, 'audit'])->whereNumber('loan')->middleware('throttle:120,1')->name('pinjaman.audit.loan');
    Route::get('/pinjaman/{loan}/angsuran', [App\Http\Controllers\Pinjaman\LoanController::class, 'installments'])->whereNumber('loan')->middleware('throttle:120,1')->name('pinjaman.installments');
    Route::post('/pinjaman/{loan}/angsuran', [LoanPaymentController::class, 'store'])->whereNumber('loan')->middleware('throttle:30,1')->name('pinjaman.installments.store');
    Route::post('/pinjaman/{loan}/hitung-ulang', [LoanPaymentController::class, 'recalculate'])->whereNumber('loan')->middleware('throttle:30,1')->name('pinjaman.recalculate');
    Route::get('/pinjaman/{loan}', Show::class)->whereNumber('loan')->name('pinjaman.show');
});

Route::middleware(['menu:simpanan', 'peran:admin,pengurus'])->group(function () {
    Route::get('/simpanan', Simpanan\Types::class)->name('simpanan');
    Route::get('/simpanan/mutasi', Simpanan\Entries::class)->name('simpanan.mutasi');
    Route::get('/simpanan/setoran-bulanan', Simpanan\MonthlyDues::class)->name('simpanan.setoran');
    Route::get('/simpanan/setoran-bulanan/rekap', Simpanan\DuesRecap::class)->name('simpanan.setoran.rekap');
    Route::get('/simpanan/setoran-bulanan/data', [MonthlyDuesController::class, 'sheet'])->name('simpanan.setoran.data');
    Route::get('/simpanan/setoran-bulanan/rekap/data', [MonthlyDuesController::class, 'recap'])->name('simpanan.setoran.rekap.data');
    Route::post('/simpanan/setoran-bulanan/{due}/koreksi', [MonthlyDuesController::class, 'correct'])
        ->whereNumber('due')->middleware(['can:kelola-simpanan', 'throttle:30,1'])->name('simpanan.setoran.koreksi');
    Route::get('/simpanan/setoran-bulanan/rekap/unduh', [MonthlyDuesExportController::class, 'recap'])
        ->middleware('throttle:20,1')->name('simpanan.setoran.rekap.unduh');
    Route::get('/simpanan/setoran-bulanan/unduh', MonthlyDuesExportController::class)
        ->middleware('throttle:20,1')->name('simpanan.setoran.unduh');
    Route::post('/simpanan/anggota/{member}/mutasi', [MemberSavingController::class, 'store'])
        ->whereNumber('member')->middleware(['can:kelola-simpanan', 'throttle:60,1'])->name('simpanan.anggota.mutasi');
    Route::get('/simpanan/anggota/{member}/saldo', [MemberSavingController::class, 'show'])->whereNumber('member')->name('simpanan.anggota.saldo');
    Route::get('/simpanan/jenis/data', [SavingTypeController::class, 'index'])->name('simpanan.jenis.data');
    Route::middleware(['can:kelola-simpanan', 'throttle:30,1'])->group(function () {
        Route::post('/simpanan/jenis', [SavingTypeController::class, 'store'])->name('simpanan.jenis.store');
        Route::put('/simpanan/jenis/{type}', [SavingTypeController::class, 'update'])->whereNumber('type')->name('simpanan.jenis.update');
        Route::patch('/simpanan/jenis/{type}/status', [SavingTypeController::class, 'status'])->whereNumber('type')->name('simpanan.jenis.status');
        Route::delete('/simpanan/jenis/{type}', [SavingTypeController::class, 'destroy'])->whereNumber('type')->name('simpanan.jenis.destroy');
    });
});

Route::middleware(['menu:pengguna', 'peran:admin'])->group(function () {
    Route::get('/pengguna', Pengguna\Index::class)->name('pengguna');
    Route::get('/pengguna/terkunci', Pengguna\Locked::class)->name('pengguna.terkunci');
    Route::get('/pengguna/sesi-aktif', Pengguna\ActiveSessions::class)->name('pengguna.sesi');
    Route::get('/pengguna/hak-akses', Pengguna\AccessSimulator::class)->name('pengguna.akses');
    Route::get('/pengguna/impersonasi', Pengguna\Impersonation::class)->name('pengguna.impersonasi');
    Route::post('/pengguna/impersonasi', [ImpersonationController::class, 'store'])->middleware('throttle:10,1')->name('pengguna.impersonasi.mulai');
    Route::get('/pengguna/impersonasi/aktif', [ImpersonationController::class, 'active'])->name('pengguna.impersonasi.aktif');
    Route::post('/pengguna/impersonasi/petugas/{user}', StartStaffController::class)
        ->whereNumber('user')->middleware('throttle:10,1')->name('pengguna.impersonasi.petugas');
    Route::post('/pengguna/impersonasi/anggota/{member}', StartMemberController::class)
        ->whereNumber('member')->middleware('throttle:10,1')->name('pengguna.impersonasi.anggota');
    Route::get('/pengguna/sesi-aktif/data', [SessionAdminController::class, 'index'])->name('pengguna.sesi.data');
    Route::delete('/pengguna/sesi-aktif/{session}', [SessionAdminController::class, 'destroy'])
        ->where('session', '[A-Za-z0-9\-]{1,255}')->middleware('throttle:30,1')->name('pengguna.sesi.destroy');
    Route::get('/pengguna/terkunci/data', [LockoutController::class, 'index'])->name('pengguna.terkunci.data');
    Route::post('/pengguna/terkunci/{lockout}/buka', [LockoutController::class, 'unlock'])
        ->whereNumber('lockout')->middleware('throttle:30,1')->name('pengguna.terkunci.unlock');
    Route::get('/pengguna/daftar', [UserController::class, 'index'])->name('pengguna.list');
    Route::get('/pengguna/peran', [UserController::class, 'roles'])->name('pengguna.roles');
    Route::get('/pengguna/{user}/data', [UserController::class, 'show'])->whereNumber('user')->name('pengguna.show');
    Route::get('/pengguna/{user}/izin', [UserController::class, 'permissions'])->whereNumber('user')->name('pengguna.izin');
    Route::get('/pengguna/hak-akses/simulasi', [UserController::class, 'simulate'])->middleware('throttle:60,1')->name('pengguna.akses.simulasi');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/pengguna', [UserController::class, 'store'])->name('pengguna.store');
        Route::put('/pengguna/peran/{role:name}', [UserController::class, 'updateRole'])->name('pengguna.roles.update');
        Route::put('/pengguna/{user}', [UserController::class, 'update'])->whereNumber('user')->name('pengguna.update');
        Route::post('/pengguna/{user}/status', [UserController::class, 'toggle'])->whereNumber('user')->name('pengguna.toggle');
        Route::put('/pengguna/{user}/peran', [UserController::class, 'saveRoles'])->whereNumber('user')->name('pengguna.peran.save');
        Route::post('/pengguna/{user}/peran/{role}', [UserController::class, 'addRole'])->whereNumber('user')->whereAlpha('role')->name('pengguna.peran.add');
        Route::delete('/pengguna/{user}/peran/{role}', [UserController::class, 'removeRole'])->whereNumber('user')->whereAlpha('role')->name('pengguna.peran.remove');
    });
});
Route::middleware(['menu:audit', 'peran:admin,pengurus'])->group(function () {
    Route::get('/audit', Audit\Index::class)->name('audit');
    Route::get('/audit/perubahan', Audit\Changes::class)->name('audit.perubahan');
    Route::get('/audit/terhapus', Audit\Trash::class)->name('audit.terhapus');
    Route::get('/audit/peran-impersonasi', Audit\RoleImpersonation::class)->name('audit.peran');
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/audit/integritas', [IntegrityController::class, 'show'])->name('audit.integritas');
        Route::get('/audit/integritas/kegagalan', [IntegrityController::class, 'failures'])->name('audit.integritas.kegagalan');
        Route::post('/audit/integritas/periksa', [IntegrityController::class, 'run'])->middleware('peran:admin')->name('audit.integritas.periksa');
        Route::get('/audit/peran-impersonasi/data', [RoleImpersonationTrailController::class, 'index'])->name('audit.peran.data');
        Route::get('/audit/peran-impersonasi/sesi/{impersonation}', [RoleImpersonationTrailController::class, 'session'])
            ->whereUuid('impersonation')->name('audit.peran.sesi');
        Route::get('/audit/peran-impersonasi/catatan/{log}', [RoleImpersonationTrailController::class, 'roleChange'])
            ->whereNumber('log')->name('audit.peran.catatan');
    });
    Route::get('/audit/data', [AuditLogController::class, 'index'])->name('audit.data');
    Route::get('/audit/perubahan/data', [AuditLogController::class, 'changes'])->name('audit.perubahan.data');
    Route::get('/audit/{log}/data', [AuditLogController::class, 'show'])->whereNumber('log')->name('audit.detail');
    Route::get('/audit/terhapus/data', [TrashController::class, 'index'])->name('audit.terhapus.data');
    Route::post('/audit/terhapus/pulihkan', [TrashController::class, 'restore'])
        ->middleware(['can:pulihkan-data', 'throttle:30,1'])->name('audit.terhapus.restore');
});

Route::middleware('menu:sistem')->group(function () {
    Route::get('/sistem', Sistem\Health::class)->name('sistem');
    Route::get('/sistem/kesehatan/data', fn () => response()->json(HealthCheckService::report()))
        ->middleware('throttle:60,1')->name('sistem.kesehatan.data');
    Route::get('/sistem/cadangan', Sistem\Backups::class)->name('sistem.cadangan');
    Route::get('/sistem/cadangan/data', [BackupController::class, 'index'])->name('sistem.cadangan.data');
    Route::post('/sistem/cadangan', [BackupController::class, 'store'])->middleware('throttle:6,1')->name('sistem.cadangan.store');
    Route::get('/sistem/cadangan/{backup}/unduh', [BackupController::class, 'download'])->whereNumber('backup')->middleware('throttle:10,1')->name('sistem.cadangan.unduh');
    Route::get('/sistem/uji-pemulihan/data', [BackupController::class, 'tests'])->name('sistem.restore.data');
    Route::post('/sistem/uji-pemulihan', [BackupController::class, 'runTest'])->middleware('throttle:6,1')->name('sistem.restore.run');
    Route::get('/sistem/uji-pemulihan', Sistem\RestoreTest::class)->name('sistem.restore');
    Route::get('/sistem/antrean', Sistem\Queue::class)->name('sistem.antrean');
    Route::get('/sistem/antrean/data', [BackupController::class, 'queue'])->middleware('throttle:60,1')->name('sistem.antrean.data');
    Route::post('/sistem/antrean/{uuid}/ulangi', [BackupController::class, 'retryJob'])->middleware('throttle:20,1')->name('sistem.antrean.retry');
    Route::delete('/sistem/antrean/{uuid}', [BackupController::class, 'forgetJob'])->middleware('throttle:20,1')->name('sistem.antrean.forget');
    Route::get('/sistem/kesehatan/riwayat', [BackupController::class, 'healthHistory'])->middleware('throttle:60,1')->name('sistem.kesehatan.riwayat');
    Route::get('/sistem/performa', Sistem\Performance::class)->name('sistem.performa');
});

Route::middleware('menu:pengaturan')->group(function () {
    Route::get('/pengaturan', Pengaturan\Index::class)->name('pengaturan');
    Route::get('/pengaturan/profil', Pengaturan\Profile::class)->name('pengaturan.profil');
    Route::get('/pengaturan/struk', Pengaturan\Receipt::class)->name('pengaturan.struk');
    Route::get('/pengaturan/pajak', Pengaturan\Tax::class)->name('pengaturan.pajak');
    Route::get('/pengaturan/keamanan', Pengaturan\Security::class)->name('pengaturan.keamanan');
    Route::get('/pengaturan/bon', Pengaturan\Credit::class)->name('pengaturan.bon');
    Route::get('/pengaturan/retur', Pengaturan\Returns::class)->name('pengaturan.retur');

    Route::get('/pengaturan/data', [SettingsController::class, 'index'])->name('pengaturan.data');
    Route::get('/pengaturan/data/{section}', [SettingsController::class, 'show'])->whereIn('section', SettingsService::SECTIONS)->name('pengaturan.data.show');
    Route::middleware(['can:ubah-pengaturan', 'throttle:30,1'])->group(function () {
        Route::post('/pengaturan/data/profile/logo', [LogoController::class, 'store'])->name('pengaturan.logo.store');
        Route::delete('/pengaturan/data/profile/logo', [LogoController::class, 'destroy'])->name('pengaturan.logo.destroy');
        Route::put('/pengaturan/data/{section}', [SettingsController::class, 'update'])->whereIn('section', SettingsService::SECTIONS)->name('pengaturan.data.update');
        Route::post('/pengaturan/data/{section}/reset', [SettingsController::class, 'reset'])->whereIn('section', SettingsService::SECTIONS)->name('pengaturan.data.reset');
    });
});
