<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\ProductImportRow;
use App\Models\User;
use App\Support\ProductImportFormat;
use App\Support\SimpleXlsx;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Impor barang dari berkas CSV/XLSX. Tahap unggah: berkas diperiksa UploadPolicy (jenis, ukuran,
 * isi), disimpan di disk privat `local` (imports/), dan dicatat sebagai impor berstatus "diunggah"
 * beserta cara menyimpan & opsi perbarui stok, lalu langsung dibaca (parse): CSV (pemisah ; , atau
 * tab, UTF-8/Windows-1252) atau XLSX (SimpleXlsx), judul kolom dipetakan (termasuk alias), dan
 * setiap baris disimpan ke product_import_rows → status "pratinjau"; berkas yang tak terbaca →
 * "gagal" dengan alasannya. evaluate() menilai setiap baris (baru / perbarui / lewati / masalah)
 * terhadap barang & kategori yang ada. Petugas dibatasi beberapa impor terbuka sekaligus.
 */
class ProductImportService
{
    public const DISK = 'local';

    public const DIR = 'imports';

    /** Batas impor yang belum selesai (diunggah/pratinjau) per petugas. */
    public const MAX_OPEN = 5;

    public static function rules(): array
    {
        return [
            'berkas' => UploadPolicy::rules('import'),
            'mode' => ['required', Rule::in(array_keys(ProductImportFormat::MODES))],
            'perbarui_stok' => ['nullable', 'boolean'],
        ];
    }

    public static function messages(): array
    {
        return UploadPolicy::messages('import', 'berkas') + [
            'berkas.required' => 'Pilih berkas CSV atau Excel terlebih dahulu.',
            'mode.in' => 'Cara menyimpan tidak dikenal.',
        ];
    }

    /**
     * @throws ValidationException bila petugas sudah punya terlalu banyak impor terbuka
     */
    public function upload(UploadedFile $file, string $mode, bool $updateStock, ?User $by = null): ProductImport
    {
        $by ??= auth()->user();
        $open = ProductImport::where('user_id', $by?->id)->whereIn('status', ['diunggah', 'pratinjau'])->count();
        if ($open >= self::MAX_OPEN) {
            throw ValidationException::withMessages(['berkas' => 'Masih ada '.$open.' impor yang belum diselesaikan. Simpan atau batalkan dulu sebelum mengunggah lagi.']);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs(self::DIR.'/'.now()->format('Y/m'), Str::random(24).'.'.$ext, self::DISK);
        $name = Str::limit(preg_replace('/[^\pL\pN ._()-]/u', '_', $file->getClientOriginalName()), 190, '');

        $import = ProductImport::create([
            'user_id' => $by?->id,
            'file_name' => $name,
            'format' => $ext,
            'file_path' => $path,
            'file_size' => (int) $file->getSize(),
            'status' => 'diunggah',
            'mode' => $mode,
            'update_stock' => $mode !== 'tambah' && $updateStock,
        ]);

        AuditLog::record('import.uploaded', 'barang', "Berkas impor barang {$name} diunggah", ['type' => 'product_import', 'id' => $import->id, 'label' => $name],
            ['mode' => $mode, 'update_stock' => $import->update_stock, 'size' => $import->file_size]);

        return $this->parse($import);
    }

    /** Baca berkas impor ke baris-baris; status jadi "pratinjau" atau "gagal" (dengan alasan). */
    public function parse(ProductImport $import): ProductImport
    {
        try {
            $raw = $this->readFile($import);
        } catch (\RuntimeException $e) {
            return $this->fail($import, $e->getMessage());
        }

        $headerAt = collect($raw)->search(fn ($r) => collect($r)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty());
        if ($headerAt === false) {
            return $this->fail($import, 'Berkas kosong.');
        }
        $mapped = ProductImportFormat::mapHeaders($raw[$headerAt]);
        if ($mapped['missing']) {
            $found = collect($raw[$headerAt])->filter(fn ($v) => trim((string) $v) !== '')->map(fn ($v) => '"'.trim($v).'"')->take(6)->implode(', ');

            return $this->fail($import, 'Kolom wajib tidak ditemukan: '.implode(', ', $mapped['missing']).'. Judul kolom di berkas: '.($found ?: '—').'. Gunakan templat impor terbaru.');
        }

        $rows = [];
        foreach (array_slice($raw, $headerAt + 1, null, true) as $i => $cells) {
            $values = array_fill_keys(ProductImportFormat::headers(), '');
            foreach ($mapped['map'] as $index => $col) {
                $values[$col] = trim((string) ($cells[$index] ?? ''));
            }
            if (implode('', $values) === '') {
                continue; // baris kosong
            }
            $rows[] = ['line' => $i + 1, 'values' => $values];
        }
        if ($rows === []) {
            return $this->fail($import, 'Berkas hanya berisi judul kolom, belum ada baris barang.');
        }
        if (count($rows) > ProductImportFormat::MAX_ROWS) {
            return $this->fail($import, 'Berkas berisi '.number_format(count($rows), 0, ',', '.').' baris; maksimal '.number_format(ProductImportFormat::MAX_ROWS, 0, ',', '.').' baris per impor. Bagi menjadi beberapa berkas.');
        }

        DB::transaction(function () use ($import, $rows, $mapped) {
            $import->rows()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                ProductImportRow::insert(array_map(fn ($r) => [
                    'product_import_id' => $import->id, 'line' => $r['line'], 'values' => json_encode($r['values'], JSON_UNESCAPED_UNICODE),
                    'skipped' => false, 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            $import->update(['status' => 'pratinjau', 'total_rows' => count($rows), 'file_error' => null,
                'warnings' => $mapped['unknown'] ? ['Kolom diabaikan karena tidak dikenal: '.implode(', ', array_map(fn ($c) => '"'.$c.'"', $mapped['unknown'])).'.'] : null]);
        });

        return $import->refresh();
    }

    private function fail(ProductImport $import, string $message): ProductImport
    {
        $import->update(['status' => 'gagal', 'file_error' => Str::limit($message, 300, ''), 'finished_at' => now()]);
        AuditLog::record('import.failed', 'barang', "Berkas impor {$import->file_name} tidak dapat dibaca", ['type' => 'product_import', 'id' => $import->id, 'label' => $import->file_name],
            ['error' => $message]);

        return $import->refresh();
    }

    /**
     * @return list<list<string>> baris mentah (indeks 0 = baris 1 berkas)
     *
     * @throws \RuntimeException
     */
    private function readFile(ProductImport $import): array
    {
        $path = $import->file_path ? Storage::disk(self::DISK)->path($import->file_path) : null;
        if (! $path || ! is_file($path)) {
            throw new \RuntimeException('Berkas impor tidak ditemukan.');
        }
        if ($import->format === 'xlsx') {
            return SimpleXlsx::read($path, ProductImportFormat::MAX_ROWS + 50);
        }

        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\n|\r/', $content);
        $first = collect($lines)->first(fn ($l) => trim($l) !== '') ?? '';
        $delimiter = collect([';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")])
            ->sortDesc()->keys()->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false && count($rows) < ProductImportFormat::MAX_ROWS + 50) {
            $rows[] = $row === [null] ? [] : array_map(fn ($v) => (string) $v, $row);
        }
        fclose($handle);

        return $rows;
    }

    /** @return array{type: string, id: int, label: string} subjek audit satu impor */
    private static function subject(ProductImport $import): array
    {
        return ['type' => 'product_import', 'id' => $import->id, 'label' => $import->file_name];
    }

    /** Hapus berkas asli impor dari disk (dipakai saat impor dibatalkan/dibersihkan). */
    public function deleteFile(ProductImport $import): void
    {
        if ($import->file_path) {
            Storage::disk(self::DISK)->delete($import->file_path);
            $import->forceFill(['file_path' => null])->save();
        }
    }

    /** @return array<string, mixed> */
    public static function toArray(ProductImport $i): array
    {
        return [
            'id' => $i->id,
            'file_name' => $i->file_name,
            'format' => $i->format,
            'status' => $i->status,
            'mode' => $i->mode,
            'update_stock' => $i->update_stock,
            'total_rows' => $i->total_rows,
            'created' => $i->created,
            'updated' => $i->updated,
            'skipped' => $i->skipped,
            'failed' => $i->failed,
            'file_error' => $i->file_error,
            'user' => $i->user?->name ?? 'Sistem',
            'committed_by' => $i->committed_by ? ($i->committer?->name ?? 'Sistem') : null,
            'warnings' => $i->warnings ?? [],
            'created_at' => $i->created_at?->toDateTimeString(),
            'finished_at' => $i->finished_at?->toDateTimeString(),
        ];
    }

    /**
     * Nilai setiap baris impor terhadap kategori & barang yang ada.
     *
     * @return array{rows: list<array{id: int, line: int, values: array, action: string, reason: ?string, errors: array, changes: array, skipped: bool, product_id: ?int}>, counts: array<string, int>}
     */
    public function evaluate(ProductImport $import): array
    {
        $raw = $import->rows()->get();
        $categories = Category::ordered()->pluck('name')->all();
        $cleaned = $raw->map(fn (ProductImportRow $r) => ['row' => $r, 'values' => ProductImportFormat::clean($r->values)]);

        $seen = [];
        foreach ($cleaned as $c) {
            if (! $c['row']->skipped && $c['values']['kode'] !== '') {
                $seen[$c['values']['kode']] ??= $c['row']->line;
            }
        }
        $existing = Product::whereIn(DB::raw('UPPER(code)'), array_keys($seen) ?: ['-'])->with('category')->get()
            ->keyBy(fn ($p) => mb_strtoupper($p->code));

        $rows = $cleaned->map(function ($c) use ($import, $categories, $seen, $existing) {
            /** @var ProductImportRow $row */
            $row = $c['row'];
            $v = $c['values'];
            $product = $existing[$v['kode']] ?? null;
            $errors = $row->skipped ? [] : ProductImportFormat::validateRow($v, $categories, $seen, $row->line);
            $action = $product ? 'perbarui' : 'baru';
            $reason = null;
            if ($row->skipped) {
                [$action, $reason] = ['lewati', 'Dilewati petugas'];
            } elseif ($import->mode === 'tambah' && $product) {
                [$action, $reason] = ['lewati', 'Kode sudah ada (mode hanya tambah)'];
            } elseif ($import->mode === 'perbarui' && ! $product) {
                [$action, $reason] = ['lewati', 'Kode baru (mode hanya perbarui)'];
            }
            if ($action === 'lewati') {
                $errors = [];
            }

            $changes = $product && $action === 'perbarui' && $errors === [] ? self::changes($product, $v, $import->update_stock) : [];
            if ($product && $action === 'perbarui' && $errors === [] && $changes === []) {
                [$action, $reason] = ['lewati', 'Tidak ada perubahan'];
            }

            return ['id' => $row->id, 'line' => $row->line, 'values' => $v, 'action' => $errors ? 'masalah' : $action, 'reason' => $reason,
                'errors' => $errors, 'changes' => $changes, 'skipped' => $row->skipped, 'product_id' => $product?->id];
        })->values()->all();

        $col = collect($rows);

        return ['rows' => $rows, 'counts' => [
            'semua' => $col->count(),
            'masalah' => $col->where('action', 'masalah')->count(),
            'baru' => $col->where('action', 'baru')->count(),
            'perbarui' => $col->where('action', 'perbarui')->count(),
            'lewati' => $col->where('action', 'lewati')->count(),
        ]];
    }

    /** Kolom yang benar-benar berubah bila baris diterapkan ke barang yang ada: kolom => [lama, baru]. */
    public static function changes(Product $product, array $v, bool $updateStock): array
    {
        $changes = [];
        foreach (['nama' => 'name', 'harga_jual' => 'price', 'harga_modal' => 'cost_price', 'batas_minimum' => 'min_stock', 'satuan' => 'unit'] as $col => $field) {
            $current = in_array($field, ['price', 'cost_price', 'min_stock'], true) ? (string) (int) $product->{$field} : (string) $product->{$field};
            if ($v[$col] !== '' && $v[$col] !== $current) {
                $changes[$col] = [$current, $v[$col]];
            }
        }
        if (mb_strtolower($v['kategori']) !== mb_strtolower((string) $product->category?->name)) {
            $changes['kategori'] = [$product->category?->name, $v['kategori']];
        }
        if (ProductImportFormat::isActive($v['aktif']) !== (bool) $product->is_active) {
            $changes['aktif'] = [$product->is_active ? 'ya' : 'tidak', ProductImportFormat::isActive($v['aktif']) ? 'ya' : 'tidak'];
        }
        if ($updateStock && $v['stok'] !== '' && (int) $v['stok'] !== (int) $product->stock) {
            $changes['stok'] = [(string) $product->stock, $v['stok']];
        }

        return $changes;
    }

    /** Perbaiki nilai satu baris (hanya selama impor masih pratinjau). */
    public function updateRow(ProductImport $import, int $line, array $values): ProductImportRow
    {
        $row = $this->openRow($import, $line);
        $clean = array_map(fn ($v) => Str::limit(trim((string) $v), 200, ''), array_intersect_key($values, array_flip(ProductImportFormat::headers())));
        $before = $row->values;
        $row->update(['values' => array_merge($before, $clean)]);

        $changed = array_keys(array_filter($clean, fn ($v, $k) => (string) ($before[$k] ?? '') !== $v, ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            AuditLog::record('import.row_edited', 'barang', "Baris {$line} impor {$import->file_name} diperbaiki: ".implode(', ', $changed),
                self::subject($import), ['line' => $line],
                old: collect($changed)->mapWithKeys(fn ($k) => [$k => $before[$k] ?? ''])->all(),
                new: collect($changed)->mapWithKeys(fn ($k) => [$k => $clean[$k]])->all());
        }

        return $row;
    }

    /** Tandai satu baris dilewati / disertakan kembali. */
    public function setSkipped(ProductImport $import, int $line, bool $skipped): ProductImportRow
    {
        $row = $this->openRow($import, $line);
        if ($row->skipped !== $skipped) {
            $row->update(['skipped' => $skipped]);
            AuditLog::record('import.row_skipped', 'barang', "Baris {$line} impor {$import->file_name} ".($skipped ? 'dilewati' : 'disertakan kembali'),
                self::subject($import), ['line' => $line, 'skipped' => $skipped]);
        }

        return $row;
    }

    /** Lewati semua baris yang masih bermasalah. Mengembalikan jumlahnya. */
    public function skipProblems(ProductImport $import): int
    {
        $this->assertOpen($import);
        $problems = collect($this->evaluate($import)['rows'])->where('action', 'masalah');
        ProductImportRow::whereIn('id', $problems->pluck('id'))->update(['skipped' => true]);
        if ($problems->isNotEmpty()) {
            AuditLog::record('import.row_skipped', 'barang', $problems->count()." baris bermasalah impor {$import->file_name} dilewati",
                self::subject($import), ['lines' => $problems->pluck('line')->all(), 'skipped' => true]);
        }

        return $problems->count();
    }

    /**
     * Simpan impor ke data barang: baris baru → barang baru (stok awal lewat riwayat stok),
     * baris perbarui → barang diubah (stok hanya bila opsi perbarui stok), sisanya dilewati.
     * Ditolak bila masih ada baris bermasalah. Semua perubahan tercatat di audit dengan alasan impor.
     *
     * @throws ValidationException
     */
    public function commit(ProductImport $import, ?User $by = null): ProductImport
    {
        $by ??= auth()->user();

        return DB::transaction(function () use ($import, $by) {
            $import = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($import);
            $evaluated = $this->evaluate($import);
            if ($evaluated['counts']['masalah'] > 0) {
                throw ValidationException::withMessages(['baris' => "Masih ada {$evaluated['counts']['masalah']} baris bermasalah. Perbaiki atau lewati dulu."]);
            }
            if ($evaluated['counts']['baru'] + $evaluated['counts']['perbarui'] === 0) {
                throw ValidationException::withMessages(['baris' => 'Tidak ada baris yang perlu disimpan.']);
            }

            $import->update(['status' => 'diproses']);
            $products = app(ProductService::class);
            $minDefault = app(MinStockService::class)->defaultMinStock();
            $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

            AuditLog::withReason("Impor {$import->file_name}", function () use ($evaluated, $import, $products, $minDefault, $by, &$counts) {
                foreach ($evaluated['rows'] as $r) {
                    $v = $r['values'];
                    $row = ProductImportRow::find($r['id']);
                    if ($r['action'] === 'baru') {
                        $p = $products->create([
                            'code' => $v['kode'], 'name' => $v['nama'], 'category' => $this->categoryName($v['kategori']),
                            'price' => (int) $v['harga_jual'], 'cost_price' => (int) ($v['harga_modal'] ?: 0),
                            'stock' => (int) ($v['stok'] ?: 0), 'min_stock' => $v['batas_minimum'] === '' ? $minDefault : (int) $v['batas_minimum'],
                            'unit' => $v['satuan'] ?: 'pcs', 'is_active' => ProductImportFormat::isActive($v['aktif']),
                        ], $by?->id);
                        $row->update(['result' => 'baru', 'product_id' => $p->id, 'message' => null]);
                        $counts['created']++;
                    } elseif ($r['action'] === 'perbarui') {
                        $p = Product::with('category')->findOrFail($r['product_id']);
                        $products->update($p, [
                            'code' => $p->code,
                            'name' => $v['nama'] ?: $p->name,
                            'category' => $this->categoryName($v['kategori']) ?? $p->category?->name,
                            'price' => $v['harga_jual'] === '' ? (int) $p->price : (int) $v['harga_jual'],
                            'cost_price' => $v['harga_modal'] === '' ? (int) $p->cost_price : (int) $v['harga_modal'],
                            'stock' => $import->update_stock && $v['stok'] !== '' ? (int) $v['stok'] : $p->stock,
                            'min_stock' => $v['batas_minimum'] === '' ? $p->min_stock : (int) $v['batas_minimum'],
                            'unit' => $v['satuan'] ?: $p->unit,
                            'is_active' => ProductImportFormat::isActive($v['aktif']),
                        ], $by?->id);
                        $row->update(['result' => 'diperbarui', 'product_id' => $p->id, 'message' => null]);
                        $counts['updated']++;
                    } else {
                        $row->update(['result' => 'dilewati', 'product_id' => $r['product_id'], 'message' => $r['reason']]);
                        $counts['skipped']++;
                    }
                }
            });

            $import->update([...$counts, 'failed' => 0, 'status' => $counts['skipped'] > 0 ? 'sebagian' : 'selesai', 'finished_at' => now(),
                'committed_by' => $by?->id]);
            AuditLog::record('import.completed', 'barang',
                "Impor {$import->file_name}: {$counts['created']} baru, {$counts['updated']} diperbarui, {$counts['skipped']} dilewati",
                ['type' => 'product_import', 'id' => $import->id, 'label' => $import->file_name], $counts);
            $this->deleteFile($import);

            return $import->refresh();
        });
    }

    /** Nama kategori sesuai penulisan di data (pencocokan tanpa memperhatikan huruf besar). */
    private function categoryName(string $name): ?string
    {
        return $name === '' ? null : Category::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('name');
    }

    private function openRow(ProductImport $import, int $line): ProductImportRow
    {
        $this->assertOpen($import);

        return $import->rows()->where('line', $line)->firstOr(fn () => abort(404, 'Baris impor tidak ditemukan.'));
    }

    /** @throws ValidationException */
    private function assertOpen(ProductImport $import): void
    {
        if ($import->status !== 'pratinjau') {
            throw ValidationException::withMessages(['impor' => 'Impor ini sudah '.($import->status === 'gagal' ? 'gagal dibaca' : 'disimpan').' dan tidak bisa diubah.']);
        }
    }

    /** @return list<array<string, mixed>> semua impor, terbaru dulu */
    public function history(?int $limit = null): array
    {
        return $this->query()->when($limit, fn ($q) => $q->limit($limit))->get()
            ->map(fn (ProductImport $i) => self::toArray($i))->all();
    }

    /**
     * Rincian hasil: kesalahan berkas & baris yang tidak tersimpan (dilewati/gagal) beserta alasannya.
     *
     * @return array{file_error: ?string, problems: list<array{line: int, kode: string, nama: string, status: string, message: string}>}
     */
    public function detail(ProductImport $import): array
    {
        $problems = $import->rows()->where(fn ($q) => $q->whereIn('result', ['dilewati', 'gagal']))->get()
            ->map(fn (ProductImportRow $r) => [
                'line' => $r->line, 'kode' => mb_strtoupper(trim((string) ($r->values['kode'] ?? ''))), 'nama' => trim((string) ($r->values['nama'] ?? '')),
                'status' => $r->result, 'message' => $r->message ?? '',
            ])->all();

        return ['file_error' => $import->file_error, 'problems' => $problems];
    }

    /** Hari impor yang tidak kunjung disimpan sebelum dibatalkan otomatis. */
    public const ABANDON_DAYS = 30;

    /**
     * Batalkan impor yang terbengkalai (masih pratinjau lebih dari ABANDON_DAYS hari): status gagal
     * dengan alasan, berkas aslinya dihapus. Dipanggil pembersihan retensi.
     */
    public function abandonStale(bool $dryRun = false): int
    {
        $stale = ProductImport::whereIn('status', ['diunggah', 'pratinjau'])->where('created_at', '<', now()->subDays(self::ABANDON_DAYS))->get();
        if (! $dryRun) {
            foreach ($stale as $import) {
                $this->deleteFile($import);
                $import->update(['status' => 'gagal', 'file_error' => 'Tidak disimpan dalam '.self::ABANDON_DAYS.' hari sehingga dibatalkan otomatis.', 'finished_at' => now()]);
            }
        }

        return $stale->count();
    }

    /**
     * Saring riwayat impor: status, kata kunci (nama berkas/pengunggah), halaman.
     *
     * @param  array{status?: string, search?: string}  $f
     */
    public function query(array $f = []): \Illuminate\Database\Eloquent\Builder
    {
        $term = mb_strtolower(trim($f['search'] ?? ''));

        return ProductImport::query()->with(['user', 'committer'])
            ->when(in_array($f['status'] ?? '', ProductImport::STATUSES, true), fn ($q) => $q->where('status', $f['status']))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(file_name) LIKE ?', ["%{$term}%"])
                ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"]))))
            ->latest('created_at')->latest('id');
    }

    /**
     * Laporan hasil impor: ringkasan lalu setiap baris dengan hasilnya (baru/diperbarui/dilewati/gagal)
     * atau penilaian pratinjau bila belum disimpan.
     *
     * @return list<list<scalar|null>>
     */
    public function report(ProductImport $import): array
    {
        $i = self::toArray($import->loadMissing(['user', 'committer']));
        $rows = [
            ['Berkas', $i['file_name']], ['Status', ProductImport::STATUS_LABELS[$i['status']] ?? $i['status']],
            ['Diunggah', $i['user'].', '.$i['created_at']], ['Disimpan', $i['committed_by'] ? $i['committed_by'].', '.$i['finished_at'] : '—'],
            ['Baris', $i['total_rows']], ['Baru', $i['created']], ['Diperbarui', $i['updated']], ['Dilewati', $i['skipped']], ['Gagal', $i['failed']],
        ];
        if ($i['file_error']) {
            $rows[] = ['Kesalahan berkas', $i['file_error']];
        }
        foreach ($i['warnings'] as $w) {
            $rows[] = ['Peringatan', $w];
        }
        $rows[] = [];
        $rows[] = ['baris', 'kode', 'nama', 'hasil', 'alasan'];

        if ($import->status === 'pratinjau') {
            $labels = ['baru' => 'akan ditambah', 'perbarui' => 'akan diperbarui', 'lewati' => 'akan dilewati', 'masalah' => 'bermasalah'];
            foreach ($this->evaluate($import)['rows'] as $r) {
                $rows[] = [$r['line'], $r['values']['kode'], $r['values']['nama'], $labels[$r['action']],
                    $r['errors'] ? implode(' ', $r['errors']) : ($r['reason'] ?? '')];
            }
        } else {
            foreach ($import->rows()->get() as $r) {
                $rows[] = [$r->line, mb_strtoupper(trim((string) ($r->values['kode'] ?? ''))), trim((string) ($r->values['nama'] ?? '')), $r->result ?? '—', $r->message ?? ''];
            }
        }

        return $rows;
    }

    /** Unduh laporan hasil sebagai CSV (titik koma) atau Excel. */
    public function download(ProductImport $import, string $format = 'csv'): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $name = 'hasil-impor-'.(Str::slug(pathinfo($import->file_name, PATHINFO_FILENAME)) ?: $import->id);
        $rows = $this->report($import);
        AuditLog::record('import.report_downloaded', 'laporan', "Unduh hasil impor {$import->file_name}", ['type' => 'product_import', 'id' => $import->id, 'label' => $import->file_name], ['format' => $format]);

        if ($format === 'xlsx') {
            $binary = SimpleXlsx::write($rows, 'Hasil impor');

            return response()->streamDownload(fn () => print ($binary), "{$name}.xlsx",
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) {
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
