<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Isi panduan bawaan per menu (Pusat Panduan). Kunci = kunci menu App\Support\Menu::ITEMS (panduan petugas) atau
 * kunci umum (`akun`) dan `portal.*` (panduan anggota). Tiap panduan: judul, ringkasan singkat, langkah praktis
 * berurutan (opsional menunjuk halaman lewat nama rute), tips, dan halaman terkait. Ditulis mengikuti fitur yang
 * benar-benar ada di aplikasi; menjadi bahan dasar sebelum versi tersimpan/hasil generate & suntingan admin.
 */
class GuideCatalog
{
    /**
     * @return array<string, array{menu: ?string, audience: string, title: string, summary: string,
     *     steps: list<array{text: string, route?: string}>, tips: list<string>, related: array<string, string>}>
     */
    public static function entries(): array
    {
        return [
            'kasir' => [
                'menu' => 'kasir', 'audience' => 'petugas',
                'title' => 'Melayani penjualan di layar Kasir',
                'summary' => 'Catat penjualan barang, terima pembayaran tunai, potong saldo anggota, atau bon, lalu cetak struk. Shift kasir harus dibuka lebih dulu.',
                'steps' => [
                    ['text' => 'Buka shift: isi uang modal di laci lalu tekan Buka shift.', 'route' => 'kas'],
                    ['text' => 'Tambahkan barang dengan mengetik nama/kode, memindai barcode, atau mengklik kartu barang di katalog.', 'route' => 'kasir'],
                    ['text' => 'Pilih anggota bila pembeli anggota koperasi (perlu untuk potong saldo atau bon).'],
                    ['text' => 'Masukkan kupon atau diskon manual bila ada; total otomatis dihitung ulang termasuk pajak & pembulatan.'],
                    ['text' => 'Pilih cara bayar: tunai (isi uang diterima, kembalian dihitung), potong tabungan/simpanan, atau bon bertenggat.'],
                    ['text' => 'Tekan Bayar, lalu cetak struk (thermal/PDF) atau kirim ulang dari riwayat transaksi.'],
                    ['text' => 'Di akhir tugas, tutup shift: hitung uang fisik di laci; sistem menampilkan selisih Kurang/Lebih.', 'route' => 'kas.shift'],
                ],
                'tips' => [
                    'Tombol Bayar aman ditekan sekali — transaksi yang sama tidak tercatat dua kali walau jaringan putus.',
                    'Stok berkurang otomatis dan tercatat di riwayat stok barang.',
                    'Daftar barang diurutkan dari yang paling laku (label Terlaris); barang lain dicari dengan nama/kode, kategori, atau scan barcode. Jumlah & periodenya diatur Admin di Pengaturan → Layar kasir.',
                    'Sinyal hilang? Tekan Buka kasir offline (atau buka /kasir/offline): tetap bisa berjualan tunai/QRIS dari katalog di perangkat, struk sementara OFF-…; penjualan terkirim otomatis saat tersambung dan muncul di Transaksi → Penjualan offline. Buka layar Kasir sekali tiap hari saat online agar katalog perangkat terbaru.',
                ],
                'related' => ['kas' => 'Kas Harian', 'transaksi' => 'Riwayat transaksi'],
            ],
            'transaksi' => [
                'menu' => 'transaksi', 'audience' => 'petugas',
                'title' => 'Melihat riwayat transaksi, retur & pembatalan',
                'summary' => 'Cari transaksi penjualan, lihat rinciannya, cetak ulang struk, dan ajukan retur atau pembatalan yang nantinya disetujui Admin/Pengurus.',
                'steps' => [
                    ['text' => 'Buka Transaksi; saring menurut tanggal, kasir, cara bayar, atau cari nomor struk.', 'route' => 'transaksi'],
                    ['text' => 'Klik transaksi untuk melihat barang, diskon, pembayaran, dan pelanggan.'],
                    ['text' => 'Untuk mengembalikan barang, pilih Ajukan retur, isi jumlah & alasan.'],
                    ['text' => 'Untuk membatalkan seluruh transaksi, pilih Batalkan dan tulis alasannya.'],
                    ['text' => 'Pengajuan menunggu persetujuan di Transaksi → Retur; setelah disetujui stok & uang dikembalikan otomatis.', 'route' => 'transaksi.retur'],
                    ['text' => 'Pesanan dari portal anggota diproses di Pesanan anggota.', 'route' => 'pesanan'],
                ],
                'tips' => ['Batas waktu retur diatur di Pengaturan → Retur.'],
                'related' => ['transaksi.retur' => 'Retur & pembatalan', 'pesanan' => 'Pesanan anggota'],
            ],
            'kas' => [
                'menu' => 'kas', 'audience' => 'petugas',
                'title' => 'Mengelola shift & kas harian',
                'summary' => 'Buka dan tutup shift kasir, catat uang masuk/keluar laci, dan pantau rekap kas beserta selisihnya.',
                'steps' => [
                    ['text' => 'Buka Kas Harian, lalu Buka shift dengan modal awal.', 'route' => 'kas'],
                    ['text' => 'Catat setoran atau pengambilan uang laci di luar penjualan (mis. setor ke bendahara) beserta keterangannya.'],
                    ['text' => 'Saat tutup, hitung uang fisik dan masukkan jumlahnya; sistem membandingkan dengan seharusnya.'],
                    ['text' => 'Selisih ditampilkan sebagai Kurang RpX atau Lebih RpX dan tercatat di riwayat shift.', 'route' => 'kas.shift'],
                    ['text' => 'Pengurus memantau ringkasan harian/bulanan di Rekap kas.', 'route' => 'kas.rekap'],
                ],
                'tips' => ['Shift yang lupa ditutup bisa ditutupkan Admin.'],
                'related' => ['kas.shift' => 'Riwayat shift', 'kas.rekap' => 'Rekap kas'],
            ],
            'barang' => [
                'menu' => 'barang', 'audience' => 'petugas',
                'title' => 'Mengelola barang, stok & barcode',
                'summary' => 'Tambah dan ubah barang beserta harga, kategori, stok minimum, cetak barcode/label, impor massal, dan pantau riwayat stok.',
                'steps' => [
                    ['text' => 'Buka Barang & Stok untuk melihat daftar, cari, atau saring barang yang stoknya menipis.', 'route' => 'barang'],
                    ['text' => 'Tambah barang baru: isi nama, kategori, harga beli & jual, stok awal, dan batas minimum.'],
                    ['text' => 'Atur kategori (warna & urutan) di Kategori.', 'route' => 'barang.kategori'],
                    ['text' => 'Cetak barcode CODE128 atau label rak siap tempel.', 'route' => 'barang.barcode'],
                    ['text' => 'Impor banyak barang sekaligus dari CSV/XLSX: unggah, periksa pratinjau, lalu terapkan.', 'route' => 'barang.impor'],
                    ['text' => 'Telusuri perubahan stok (sebelum → sesudah) di Riwayat stok.', 'route' => 'barang.riwayat-stok'],
                ],
                'tips' => ['Saran restok menyusun daftar barang menipis untuk dibuatkan pesanan pembelian.'],
                'related' => ['barang.saran-restok' => 'Saran restok', 'pengadaan' => 'Pengadaan'],
            ],
            'pengadaan' => [
                'menu' => 'pengadaan', 'audience' => 'petugas',
                'title' => 'Pengadaan barang dari pemasok',
                'summary' => 'Kelola pemasok, terbitkan pesanan pembelian (PO), terima barang yang menambah stok otomatis, dan bayar hutang ke pemasok.',
                'steps' => [
                    ['text' => 'Daftarkan pemasok beserta kontak & tempo pembayaran.', 'route' => 'pengadaan.supplier'],
                    ['text' => 'Buat pesanan pembelian (PO-YYYYMM-NNNN) manual atau isi otomatis dari barang yang menipis.', 'route' => 'pengadaan.pesanan.buat'],
                    ['text' => 'Saat barang datang, catat penerimaan sesuai surat jalan — stok bertambah otomatis.', 'route' => 'pengadaan.penerimaan'],
                    ['text' => 'Tagihan pemasok muncul di Hutang; catat pembayarannya sebagian atau lunas.', 'route' => 'pengadaan.hutang'],
                ],
                'tips' => ['Pengingat hutang jatuh tempo dikirim otomatis setiap pagi.'],
                'related' => ['barang.saran-restok' => 'Saran restok', 'pengadaan.hutang' => 'Hutang pemasok'],
            ],
            'promo' => [
                'menu' => 'promo', 'audience' => 'petugas',
                'title' => 'Diskon, promo & kupon',
                'summary' => 'Atur promo otomatis, kupon, dan batas diskon manual yang berlaku di layar Kasir dan portal anggota.',
                'steps' => [
                    ['text' => 'Buka Diskon & Promo untuk melihat promo yang berlaku, terjadwal, dan berakhir.', 'route' => 'promo'],
                    ['text' => 'Tekan Buat promo: pilih jenis (potongan persen, potongan rupiah, beli X gratis Y, harga grosir), barang/kategori yang dicakup, hari & jam, serta masa berlaku.', 'route' => 'promo.buat'],
                    ['text' => 'Tekan Buat kupon: isi kode, potongan, minimal belanja, kuota total & per anggota, dan masa berlaku.', 'route' => 'promo.kupon.buat'],
                    ['text' => 'Pakai tombol Nonaktifkan/Aktifkan atau Ubah pada promo & kupon di daftar; yang belum pernah dipakai bisa dihapus dari halaman Ubah.'],
                    ['text' => 'Lihat jadwal promo yang sedang dan akan berjalan beserta dampaknya.', 'route' => 'promo.jadwal'],
                    ['text' => 'Atur batas diskon manual kasir di Pengaturan → Diskon manual.', 'route' => 'pengaturan.diskon'],
                ],
                'tips' => ['Kasir memasukkan kode kupon saat transaksi; promo otomatis langsung terpasang.', 'Satu barang hanya mendapat satu promo — yang paling hemat.', 'Promo atau kupon yang sudah dipakai tidak bisa dihapus agar laporan diskon tetap utuh; nonaktifkan saja.'],
                'related' => ['promo.jadwal' => 'Jadwal promo', 'pengaturan.diskon' => 'Diskon manual'],
            ],
            'anggota' => [
                'menu' => 'anggota', 'audience' => 'petugas',
                'title' => 'Data anggota, tabungan & bon',
                'summary' => 'Kelola data siswa/guru anggota, setor dan tarik tabungan, serta catat dan lunasi bon (piutang) belanja.',
                'steps' => [
                    ['text' => 'Buka Anggota untuk mencari atau menambah anggota (siswa/guru).', 'route' => 'anggota'],
                    ['text' => 'Buka profil anggota untuk melihat saldo, simpanan, bon, dan riwayat.'],
                    ['text' => 'Catat setor atau tarik tabungan; saldo langsung diperbarui dan tercatat.'],
                    ['text' => 'Untuk bon, buka dialog pelunasan dan pilih bayar tunai atau potong tabungan.'],
                    ['text' => 'Pelunasan dialokasikan otomatis ke bon jatuh tempo terlama.', 'route' => 'anggota.piutang'],
                    ['text' => 'Aktifkan akun portal agar anggota bisa memeriksa saldo & memesan sendiri.'],
                ],
                'tips' => ['Batas bon per anggota diatur di Pengaturan → Bon.'],
                'related' => ['anggota.piutang' => 'Piutang (bon)', 'simpanan' => 'Simpanan'],
            ],
            'simpanan' => [
                'menu' => 'simpanan', 'audience' => 'petugas',
                'title' => 'Simpanan pokok, wajib & sukarela',
                'summary' => 'Catat simpanan anggota per jenis, kelola setoran bulanan (potong gaji), dan telusuri mutasinya.',
                'steps' => [
                    ['text' => 'Atur jenis simpanan (pokok, wajib, sukarela) dan nominalnya.', 'route' => 'simpanan'],
                    ['text' => 'Catat setoran atau penarikan simpanan per anggota.', 'route' => 'simpanan.mutasi'],
                    ['text' => 'Buka Setoran bulanan untuk membuat tagihan wajib bulan berjalan dan menandai yang sudah dibayar/potong gaji.', 'route' => 'simpanan.setoran'],
                    ['text' => 'Koreksi setoran bila salah — riwayat koreksinya tersimpan.'],
                    ['text' => 'Unduh rekap setoran bulanan untuk bendahara.', 'route' => 'simpanan.setoran.rekap'],
                ],
                'tips' => [],
                'related' => ['simpanan.setoran' => 'Setoran bulanan'],
            ],
            'pinjaman' => [
                'menu' => 'pinjaman', 'audience' => 'petugas',
                'title' => 'Siklus pinjaman anggota',
                'summary' => 'Catat pengajuan, setujui/tolak dengan alasan, cairkan dengan bukti, lalu terima angsuran dan pantau tunggakan.',
                'steps' => [
                    ['text' => 'Catat pengajuan pinjaman: anggota, nominal, tenor, dan tujuan.', 'route' => 'pinjaman.pengajuan'],
                    ['text' => 'Pengurus meninjau: setujui, tolak, atau batalkan — alasan wajib diisi.'],
                    ['text' => 'Pinjaman disetujui masuk antrean pencairan; catat pencairan beserta bukti.', 'route' => 'pinjaman.pencairan'],
                    ['text' => 'Jadwal angsuran (pokok & bunga) aktif; catat pembayaran angsuran saat diterima.', 'route' => 'pinjaman.angsuran'],
                    ['text' => 'Pantau angsuran yang lewat jatuh tempo di Tunggakan.', 'route' => 'pinjaman.tunggakan'],
                    ['text' => 'Unduh rekap pinjaman dan periksa jejak audit pinjaman yang berantai.', 'route' => 'pinjaman.rekap'],
                ],
                'tips' => ['Setiap perubahan angsuran tercatat dan dapat diverifikasi di Pinjaman → Audit.'],
                'related' => ['pinjaman.audit' => 'Audit pinjaman', 'pinjaman.tunggakan' => 'Tunggakan'],
            ],
            'laporan' => [
                'menu' => 'laporan', 'audience' => 'petugas',
                'title' => 'Laporan penjualan',
                'summary' => 'Lihat ringkasan penjualan, laba kotor, barang terlaris, dan grafik per periode; unduh dalam PDF/CSV/XLSX.',
                'steps' => [
                    ['text' => 'Buka Laporan dan pilih periode (hari ini, minggu, bulan, atau rentang tanggal).', 'route' => 'laporan'],
                    ['text' => 'Baca ringkasan: jumlah transaksi, omzet, diskon, dan laba kotor.'],
                    ['text' => 'Lihat barang terlaris dan grafik penjualan harian.'],
                    ['text' => 'Unduh laporan; berkas besar disiapkan di latar belakang dan muncul di daftar unduhan.'],
                ],
                'tips' => [],
                'related' => ['kas.rekap' => 'Rekap kas'],
            ],
            'audit' => [
                'menu' => 'audit', 'audience' => 'petugas',
                'title' => 'Audit & riwayat aktivitas',
                'summary' => 'Telusuri siapa melakukan apa dan kapan: login, perubahan data, data terhapus, perubahan peran, dan sesi impersonasi — semuanya dijaga checksum HMAC.',
                'steps' => [
                    ['text' => 'Buka Audit & Riwayat; saring menurut kategori, petugas, periode, atau kata kunci.', 'route' => 'audit'],
                    ['text' => 'Klik baris untuk melihat rincian sebelum → sesudah.'],
                    ['text' => 'Lihat Perubahan data per objek (barang, anggota, pengaturan, …).', 'route' => 'audit.perubahan'],
                    ['text' => 'Pulihkan data yang terhapus lunak dari Data terhapus (khusus Admin).', 'route' => 'audit.terhapus'],
                    ['text' => 'Periksa perubahan peran & impersonasi beserta status keaslian catatan.', 'route' => 'audit.peran'],
                ],
                'tips' => ['Catatan audit tidak bisa diubah atau dihapus; pemeriksaan keaslian berjalan otomatis setiap malam.'],
                'related' => ['audit.peran' => 'Peran & impersonasi'],
            ],
            'pengguna' => [
                'menu' => 'pengguna', 'audience' => 'petugas',
                'title' => 'Akun petugas, peran & impersonasi',
                'summary' => 'Kelola akun petugas dan perannya (bisa lebih dari satu), hak menu tiap peran, sesi aktif, akun terkunci, dan masuk sementara sebagai akun lain untuk membantu.',
                'steps' => [
                    ['text' => 'Tambah akun petugas dan centang satu atau beberapa peran.', 'route' => 'pengguna'],
                    ['text' => 'Ubah peran lewat tombol Peran; isi alasan bila perlu — perubahan tercatat di audit.'],
                    ['text' => 'Atur menu yang boleh dibuka tiap peran; hak akun = gabungan semua perannya.'],
                    ['text' => 'Simulasikan kombinasi peran di Hak akses gabungan sebelum memberikannya.', 'route' => 'pengguna.akses'],
                    ['text' => 'Keluarkan sesi di perangkat hilang atau buka akun yang terkunci.', 'route' => 'pengguna.sesi'],
                    ['text' => 'Masuk sebagai akun lain (dengan alasan & batas waktu) untuk menelusuri masalah; kembali lewat spanduk merah.', 'route' => 'pengguna.impersonasi'],
                ],
                'tips' => ['Setiap akun wajib punya minimal satu peran; Admin tidak bisa saling dimasuki.'],
                'related' => ['pengguna.akses' => 'Hak akses gabungan', 'pengguna.impersonasi' => 'Masuk sebagai akun'],
            ],
            'sistem' => [
                'menu' => 'sistem', 'audience' => 'petugas',
                'title' => 'Kesehatan sistem & cadangan',
                'summary' => 'Pantau kesehatan aplikasi, antrean tugas latar belakang, performa halaman, cadangan basis data, dan uji pemulihan.',
                'steps' => [
                    ['text' => 'Buka Sistem untuk melihat status kesehatan dan riwayat gangguan 24 jam.', 'route' => 'sistem'],
                    ['text' => 'Periksa antrean tugas (ekspor, notifikasi) dan ulangi tugas yang gagal.', 'route' => 'sistem.antrean'],
                    ['text' => 'Buat atau unduh cadangan basis data; cadangan otomatis berjalan setiap malam.', 'route' => 'sistem.cadangan'],
                    ['text' => 'Jalankan uji pemulihan untuk memastikan cadangan benar-benar bisa dipakai.', 'route' => 'sistem.restore'],
                    ['text' => 'Lihat halaman yang lambat di Performa.', 'route' => 'sistem.performa'],
                ],
                'tips' => [],
                'related' => ['sistem.cadangan' => 'Cadangan'],
            ],
            'pengaturan' => [
                'menu' => 'pengaturan', 'audience' => 'petugas',
                'title' => 'Pengaturan koperasi',
                'summary' => 'Atur identitas koperasi & logo, tampilan struk, pajak, aturan bon, retur, serta keamanan dan retensi data.',
                'steps' => [
                    ['text' => 'Isi profil koperasi (nama, sekolah, alamat, logo).', 'route' => 'pengaturan.profil'],
                    ['text' => 'Atur tampilan struk dan ukuran kertas thermal.', 'route' => 'pengaturan.struk'],
                    ['text' => 'Atur pajak dan pembulatan harga.', 'route' => 'pengaturan.pajak'],
                    ['text' => 'Atur batas diskon manual kasir (persen & rupiah per transaksi) dan wajib alasan.', 'route' => 'pengaturan.diskon'],
                    ['text' => 'Tentukan batas bon dan tenggatnya.', 'route' => 'pengaturan.bon'],
                    ['text' => 'Atur batas waktu retur.', 'route' => 'pengaturan.retur'],
                    ['text' => 'Atur keamanan: batas percobaan login, lama sesi menganggur, dan masa simpan data terhapus.', 'route' => 'pengaturan.keamanan'],
                ],
                'tips' => ['Perubahan pengaturan hanya bisa dilakukan Admin dan tercatat di audit.'],
                'related' => ['pengaturan.keamanan' => 'Keamanan'],
            ],
            'akun' => [
                'menu' => null, 'audience' => 'petugas',
                'title' => 'Akun saya: kata sandi, sesi & notifikasi',
                'summary' => 'Ganti kata sandi, lihat perangkat yang sedang masuk, dan baca notifikasi atau pengumuman.',
                'steps' => [
                    ['text' => 'Ganti kata sandi dari ikon kunci di bawah menu samping.', 'route' => 'akun.password'],
                    ['text' => 'Lihat dan keluarkan perangkat lain yang masih masuk dengan akun Anda.', 'route' => 'akun.sessions'],
                    ['text' => 'Klik lonceng untuk membaca notifikasi (stok menipis, persetujuan, perubahan peran).', 'route' => 'notifikasi'],
                    ['text' => 'Klik nama Anda di bawah menu samping untuk melihat peran aktif dan menu dari tiap peran.'],
                ],
                'tips' => ['Sesi berakhir otomatis bila lama tidak ada aktivitas; peringatan muncul sebelumnya.'],
                'related' => ['notifikasi' => 'Notifikasi'],
            ],
            // ── Panduan anggota (portal) ──
            'portal.beranda' => [
                'menu' => null, 'audience' => 'anggota', 'portal_route' => 'portal',
                'title' => 'Mengenal portal anggota',
                'summary' => 'Portal menampilkan saldo tabungan, simpanan, pinjaman, dan pesanan Anda. Masuk dengan kode anggota/email; pertama kali memakai kata sandi sementara dari petugas koperasi.',
                'steps' => [
                    ['text' => 'Masuk memakai kode anggota atau email dan kata sandi portal.', 'route' => 'portal'],
                    ['text' => 'Saat pertama masuk, buat kata sandi dan PIN transaksi 6 angka milik Anda sendiri di halaman Siapkan akun.'],
                    ['text' => 'Beranda menampilkan saldo tabungan, ringkasan simpanan, dan pinjaman aktif.'],
                    ['text' => 'Pakai menu di atas (atau bawah layar HP) untuk berpindah: Simpanan, Pinjaman, Riwayat, Belanja, Pesanan.'],
                    ['text' => 'Tekan Keluar bila memakai perangkat bersama.'],
                ],
                'tips' => ['Kata sandi & PIN hanya Anda yang tahu — petugas tidak pernah melihat PIN Anda.', 'Lupa kata sandi atau PIN? Minta petugas mengatur ulang, lalu buat yang baru saat masuk.'],
                'related' => ['portal.akun' => 'Akun saya'],
            ],
            'portal.simpanan' => [
                'menu' => null, 'audience' => 'anggota', 'portal_route' => 'portal.simpanan',
                'title' => 'Melihat tabungan & simpanan',
                'summary' => 'Periksa saldo tabungan dan simpanan pokok, wajib, sukarela beserta riwayat setoran dan penarikannya.',
                'steps' => [
                    ['text' => 'Buka menu Simpanan.', 'route' => 'portal.simpanan'],
                    ['text' => 'Lihat saldo per jenis simpanan dan total keseluruhan.'],
                    ['text' => 'Gulir riwayat mutasi untuk melihat setoran, penarikan, dan potongan belanja.'],
                    ['text' => 'Setoran wajib bulanan yang belum dibayar ditandai jelas.'],
                ],
                'tips' => ['Setor dan tarik tabungan dilakukan di loket koperasi.'],
                'related' => [],
            ],
            'portal.pinjaman' => [
                'menu' => null, 'audience' => 'anggota', 'portal_route' => 'portal.pinjaman',
                'title' => 'Memantau pinjaman & angsuran',
                'summary' => 'Lihat pinjaman Anda, sisa pokok, jadwal angsuran, dan angsuran yang lewat jatuh tempo.',
                'steps' => [
                    ['text' => 'Buka menu Pinjaman; titik merah berarti ada angsuran menunggak.', 'route' => 'portal.pinjaman'],
                    ['text' => 'Pilih pinjaman untuk melihat jadwal angsuran (pokok & bunga) dan status tiap bulan.'],
                    ['text' => 'Lihat riwayat pembayaran angsuran yang sudah diterima petugas.'],
                ],
                'tips' => ['Pengajuan pinjaman baru dilakukan melalui pengurus koperasi.'],
                'related' => [],
            ],
            'portal.belanja' => [
                'menu' => null, 'audience' => 'anggota', 'portal_route' => 'portal.belanja',
                'title' => 'Belanja & memesan dari portal',
                'summary' => 'Pilih barang, masukkan keranjang, lalu pesan untuk diambil di koperasi atau diantar. Bayar tunai, QRIS/transfer, atau potong saldo dengan PIN 6 digit.',
                'steps' => [
                    ['text' => 'Buka Belanja, cari barang atau pilih kategori.', 'route' => 'portal.belanja'],
                    ['text' => 'Tambahkan barang ke keranjang dan atur jumlahnya.'],
                    ['text' => 'Lanjut ke pembayaran: pilih ambil di koperasi atau antar.'],
                    ['text' => 'Pilih cara bayar; untuk potong saldo masukkan PIN 6 digit Anda.'],
                    ['text' => 'Pantau status pesanan di menu Pesanan; pesanan yang masih menunggu bisa dibatalkan.', 'route' => 'portal.pesanan'],
                ],
                'tips' => ['Jangan beri tahu PIN kepada siapa pun, termasuk petugas.'],
                'related' => [],
            ],
            'portal.riwayat' => [
                'menu' => null, 'audience' => 'anggota', 'portal_route' => 'portal.riwayat',
                'title' => 'Riwayat belanja',
                'summary' => 'Lihat daftar belanja Anda di koperasi beserta rincian barang dan cara pembayarannya.',
                'steps' => [
                    ['text' => 'Buka menu Riwayat.', 'route' => 'portal.riwayat'],
                    ['text' => 'Pilih transaksi untuk melihat rincian barang dan pembayaran.'],
                    ['text' => 'Bila ada transaksi yang tidak Anda kenali, segera laporkan ke petugas koperasi.'],
                ],
                'tips' => [],
                'related' => [],
            ],
            'portal.akun' => [
                'menu' => null, 'audience' => 'anggota', 'portal_route' => 'portal.akun',
                'title' => 'Mengganti kata sandi & PIN',
                'summary' => 'Ganti sendiri kata sandi portal dan PIN transaksi Anda kapan saja.',
                'steps' => [
                    ['text' => 'Buka menu Akun di bagian atas portal.', 'route' => 'portal.akun'],
                    ['text' => 'Ganti kata sandi: isi kata sandi sekarang, lalu kata sandi baru (min. 8 karakter, huruf & angka) dua kali.'],
                    ['text' => 'Ganti PIN: isi PIN baru 6 angka dua kali, lalu kata sandi portal untuk konfirmasi.'],
                    ['text' => 'Tekan Simpan. Setelah kata sandi diganti, perangkat lain yang masih masuk diminta masuk lagi.'],
                ],
                'tips' => ['Lupa PIN? Cukup buat PIN baru di menu Akun dengan kata sandi Anda.', 'Hindari PIN yang angkanya sama semua atau berurutan naik/turun.', 'Salah kata sandi 5 kali → penggantian dikunci 15 menit.'],
                'related' => ['portal' => 'Beranda portal'],
            ],
        ];
    }

    /** Kata kunci tambahan (sinonim/istilah sehari-hari) per panduan agar pencarian tetap menemukan. */
    public const KEYWORDS = [
        'kasir' => 'jual penjualan bayar pembayaran struk kembalian scan pindai checkout pos printer cetak thermal',
        'transaksi' => 'riwayat penjualan nota struk ulang retur batal pembatalan refund kembalikan',
        'kas' => 'shift laci uang modal setor tarik selisih rekonsiliasi tutup buka',
        'barang' => 'stok produk item harga kategori barcode label impor csv xlsx restok menipis',
        'pengadaan' => 'pemasok supplier pembelian po pesanan beli penerimaan surat jalan hutang tagihan',
        'promo' => 'diskon potongan kupon voucher harga khusus',
        'anggota' => 'siswa guru member tabungan setor tarik bon piutang utang pelunasan portal',
        'simpanan' => 'pokok wajib sukarela setoran bulanan potong gaji iuran',
        'pinjaman' => 'pinjam kredit angsuran cicilan cair pencairan tunggakan telat bunga',
        'laporan' => 'rekap omzet laba penjualan terlaris grafik unduh export pdf',
        'audit' => 'riwayat log jejak aktivitas siapa hapus pulihkan',
        'pengguna' => 'akun petugas user peran role izin hak akses impersonasi masuk sebagai kunci sesi',
        'sistem' => 'backup cadangan pemulihan restore antrean queue kesehatan performa',
        'pengaturan' => 'setting profil logo struk printer kertas thermal pajak pembulatan keamanan retensi bon retur',
        'akun' => 'password kata sandi ganti sesi perangkat notifikasi pemberitahuan lonceng',
    ];

    /** Teks yang dicari untuk satu panduan (judul, ringkasan, langkah, tips, kata kunci), dinormalkan tanpa huruf besar/aksen. */
    public static function haystack(string $key, array $entry): string
    {
        return Str::lower(Str::ascii(implode(' ', [
            $entry['menu'] ? (Menu::ITEMS[$entry['menu']]['label'] ?? '') : '', $entry['title'], $entry['summary'],
            ...array_column($entry['steps'], 'text'), ...$entry['tips'], $entry['keywords'] ?? self::KEYWORDS[$key] ?? '',
        ])));
    }

    /**
     * Panduan untuk satu kelompok pembaca.
     *
     * @return array<string, array>
     */
    /** Halaman portal yang tidak punya panduan sendiri → topik panduan yang membahasnya. */
    public const PORTAL_ALIASES = ['portal.checkout' => 'portal.belanja', 'portal.pesanan' => 'portal.belanja', 'portal.pesanan.show' => 'portal.belanja'];

    /** Topik panduan anggota untuk halaman portal yang sedang dibuka (null bila tidak ada). */
    public static function portalTopicFor(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }
        foreach (self::forAudience('anggota') as $key => $e) {
            if (($e['portal_route'] ?? null) === $routeName) {
                return $key;
            }
        }

        return self::PORTAL_ALIASES[$routeName] ?? null;
    }

    public static function forAudience(string $audience): array
    {
        return array_filter(self::entries(), fn ($e) => $e['audience'] === $audience);
    }

    /** Judul grup menu samping untuk kunci menu (null = Umum). */
    public static function groupFor(?string $menu): string
    {
        return $menu ? (Menu::GROUPS[Menu::ITEMS[$menu]['group'] ?? ''] ?? 'Lainnya') : 'Umum';
    }
}
