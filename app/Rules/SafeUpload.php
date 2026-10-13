<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Pemeriksaan isi berkas unggahan (melengkapi aturan ukuran & jenis di UploadPolicy):
 * - nama berkas tanpa ekstensi berbahaya tersembunyi (mis. logo.php.png) dan tidak kosong;
 * - tidak menyisipkan kode (<?php, <script) di dalam berkas;
 * - gambar benar-benar gambar yang jenisnya sesuai akhiran;
 * - CSV berupa teks (tanpa byte NUL);
 * - XLSX berupa buku kerja Excel yang sah, tanpa makro (vbaProject.bin), dan tidak "zip bomb".
 */
class SafeUpload implements ValidationRule
{
    public const DANGEROUS_EXT = '/\.(php\d?|phtml|phar|pht|exe|bat|cmd|com|sh|js|mjs|html?|svgz?|xlsm|xltm|jar|vbs|ps1)(\.|$)/i';

    /** Batas isi XLSX setelah dibuka (byte) dan jumlah berkas di dalamnya. */
    public const XLSX_MAX_UNCOMPRESSED = 50 * 1024 * 1024;

    public const XLSX_MAX_ENTRIES = 5000;

    public function __construct(private string $kind) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return; // ditangani aturan `file`
        }

        $name = $value->getClientOriginalName();
        if (preg_match(self::DANGEROUS_EXT, preg_replace('/\.[^.]+$/', '', $name).'.') || preg_match('/\.(xlsm|xltm|svgz?)$/i', $name)) {
            $fail('Nama atau jenis berkas tidak diizinkan.');

            return;
        }
        if ($value->getSize() === 0) {
            $fail('Berkas kosong.');

            return;
        }

        $path = $value->getRealPath();
        $ext = strtolower($value->getClientOriginalExtension());
        $head = (string) file_get_contents($path, false, null, 0, 1024 * 1024);

        if ($ext !== 'xlsx' && preg_match('/<\?php|<\?=|<script\b/i', $head)) {
            $fail('Berkas berisi kode yang tidak diizinkan.');

            return;
        }

        $error = match (true) {
            in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) => $this->checkImage($path, $ext),
            $ext === 'csv' => str_contains(substr($head, 0, 65536), "\0") ? 'Berkas CSV harus berupa teks.' : null,
            $ext === 'xlsx' => $this->checkXlsx($path),
            default => null,
        };
        if ($error) {
            $fail($error);
        }
    }

    private function checkImage(string $path, string $ext): ?string
    {
        $info = @getimagesize($path);
        $expected = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'webp' => IMAGETYPE_WEBP][$ext];

        return $info && $info[2] === $expected ? null : 'Isi berkas tidak sesuai dengan jenis gambarnya.';
    }

    private function checkXlsx(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return 'Berkas Excel rusak atau bukan .xlsx.';
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                return 'Berkas bukan buku kerja Excel (.xlsx).';
            }
            if ($zip->numFiles > self::XLSX_MAX_ENTRIES) {
                return 'Berkas Excel terlalu rumit untuk diproses.';
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (stripos($stat['name'], 'vbaproject') !== false) {
                    return 'Berkas Excel berisi makro dan ditolak. Simpan ulang sebagai .xlsx biasa.';
                }
                $total += $stat['size'];
                if ($total > self::XLSX_MAX_UNCOMPRESSED) {
                    return 'Isi berkas Excel terlalu besar setelah dibuka.';
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }
}
