<?php

namespace App\Livewire\Barang;

use App\Models\Product;
use App\Support\BarcodeImage;
use App\Support\LabelFormats;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Barang → Barcode & label → Cetak label: susun daftar barang & jumlah salinan, pilih format kertas
 * (A4 / printer label / printer struk), tampilkan nama & harga, lihat pratinjau lembar pertama, lalu
 * buka tampilan siap cetak (LabelPrintController). Bisa diawali dari pilihan di halaman Barcode
 * (?barang=12,15 atau 12:3,15:1).
 */
#[Layout('components.layouts.app')]
#[Title('Cetak Label Barcode')]
class Labels extends Component
{
    /** @var list<array{id: int, copies: int}> */
    public array $rows = [];

    public string $format = LabelFormats::DEFAULT;

    public bool $showName = true;

    public bool $showPrice = true;

    public string $search = '';

    /** Lembar yang ditampilkan di pratinjau (mulai 0). */
    public int $previewPage = 0;

    public function mount(): void
    {
        foreach (LabelFormats::parseItems(request()->query('barang')) as $id => $copies) {
            $this->rows[] = ['id' => $id, 'copies' => $copies];
        }
        $this->rows = array_slice($this->rows, 0, 200);
        $this->format = array_key_exists((string) request()->query('format'), LabelFormats::FORMATS) ? request()->query('format') : LabelFormats::DEFAULT;
    }

    /** @return array<int, array{id: int, code: string, name: string, price: int, barcode: string}> */
    #[Computed]
    public function products(): array
    {
        return Product::whereKey(array_column($this->rows, 'id'))->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name,
                'price' => (int) round((float) $p->price), 'barcode' => BarcodeImage::value($p)]])->all();
    }

    #[Computed]
    public function results(): array
    {
        $term = mb_strtolower(trim($this->search));
        if (mb_strlen($term) < 2) {
            return [];
        }

        return Product::active()
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])->orWhereRaw('LOWER(code) LIKE ?', ["%{$term}%"])
                ->orWhereRaw('LOWER(barcode) LIKE ?', ["%{$term}%"]))
            ->orderBy('name')->limit(8)->get(['id', 'code', 'name'])->toArray();
    }

    /** id barang → salinan (baris terhapus/tidak valid diabaikan). */
    #[Computed]
    public function items(): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if (isset($this->products[(int) $r['id']])) {
                $out[(int) $r['id']] = max(1, min(LabelFormats::MAX_COPIES, (int) $r['copies']));
            }
        }

        return $out;
    }

    #[Computed]
    public function sheet(): array
    {
        return LabelFormats::sheets($this->items, $this->format);
    }

    #[Computed]
    public function printUrl(): ?string
    {
        return $this->items ? route('barang.label.cetak', [
            'barang' => LabelFormats::encodeItems($this->items), 'format' => $this->format,
            'nama' => (int) $this->showName, 'harga' => (int) $this->showPrice, 'otomatis' => 1,
        ]) : null;
    }

    #[Computed]
    public function pdfUrl(): ?string
    {
        return $this->items ? route('barang.label.pdf', [
            'barang' => LabelFormats::encodeItems($this->items), 'format' => $this->format,
            'nama' => (int) $this->showName, 'harga' => (int) $this->showPrice,
        ]) : null;
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'rows')) {
            foreach ($this->rows as $i => $r) {
                $this->rows[$i]['copies'] = max(1, min(LabelFormats::MAX_COPIES, (int) $r['copies']));
            }
        }
        if ($property === 'format' && ! array_key_exists($this->format, LabelFormats::FORMATS)) {
            $this->format = LabelFormats::DEFAULT;
        }
        unset($this->products, $this->items, $this->sheet, $this->printUrl, $this->pdfUrl);
        $this->previewTo($this->previewPage);
    }

    public function previewTo(int $page): void
    {
        $this->previewPage = max(0, min($page, count($this->sheet['pages']) - 1));
    }

    public function add(int $id): void
    {
        if (! Product::whereKey($id)->exists()) {
            return;
        }
        $i = array_search($id, array_column($this->rows, 'id'), true);
        $i === false ? $this->rows[] = ['id' => $id, 'copies' => 1] : $this->rows[$i]['copies']++;
        $this->search = '';
        unset($this->products, $this->items, $this->sheet, $this->printUrl, $this->pdfUrl);
    }

    public function remove(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        unset($this->products, $this->items, $this->sheet, $this->printUrl, $this->pdfUrl);
    }

    /** Bagi satu lembar rata ke semua barang: salinan = label per lembar / jumlah barang (min. 1). */
    public function fillSheet(): void
    {
        $per = LabelFormats::get($this->format)['per_page'];
        $n = count($this->rows) ?: 1;
        foreach ($this->rows as $i => $r) {
            $this->rows[$i]['copies'] = max(1, intdiv($per, $n));
        }
        unset($this->items, $this->sheet, $this->printUrl, $this->pdfUrl);
    }

    public function clear(): void
    {
        $this->rows = [];
        unset($this->products, $this->items, $this->sheet, $this->printUrl, $this->pdfUrl);
    }

    public function render()
    {
        return view('livewire.barang.labels');
    }
}
