<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Penulis & pembaca XLSX sederhana tanpa pustaka tambahan (ZipArchive + XML): menulis satu lembar
 * kerja dengan semua sel teks (inline string, agar kode "007" tidak berubah jadi angka) untuk templat
 * & ekspor kecil, dan membaca lembar pertama untuk impor barang.
 */
class SimpleXlsx
{
    /** @param  list<list<scalar|null>>  $rows */
    public static function write(array $rows, string $sheetName = 'Data'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak dapat membuat berkas Excel.');
        }

        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + ($i - 1) % 26).$s;
            }

            return $s;
        };
        $sheetRows = '';
        foreach (array_values($rows) as $r => $row) {
            $cells = '';
            foreach (array_values($row) as $c => $value) {
                if ($value !== null && $value !== '') {
                    $cells .= '<c r="'.$col($c).($r + 1).'" t="inlineStr"'.($r === 0 ? ' s="1"' : '').'><is><t xml:space="preserve">'.$esc($value).'</t></is></c>';
                }
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$cells.'</row>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$esc($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        $binary = (string) file_get_contents($path);
        @unlink($path);

        return $binary;
    }

    /**
     * Baca lembar kerja pertama sebagai daftar baris (nilai teks). Mendukung shared string,
     * inline string, angka, dan boolean; sel kosong di tengah baris diisi "".
     *
     * @return list<list<string>>
     *
     * @throws RuntimeException bila berkas bukan XLSX yang sah
     */
    public static function read(string $path, int $maxRows = 100_000): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Berkas Excel rusak atau bukan .xlsx.');
        }

        try {
            $xml = function (string $name) use ($zip): ?\SimpleXMLElement {
                $content = $zip->getFromName($name);

                return $content === false ? null : (simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT) ?: null);
            };

            $sheetPath = 'xl/worksheets/sheet1.xml';
            $workbook = $xml('xl/workbook.xml');
            $rels = $xml('xl/_rels/workbook.xml.rels');
            if ($workbook && $rels) {
                $first = $workbook->sheets->sheet[0] ?? null;
                $rid = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : null;
                foreach ($rels->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                    }
                }
            }

            $shared = [];
            if ($sst = $xml('xl/sharedStrings.xml')) {
                foreach ($sst->si as $si) {
                    $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]') ?: []));
                }
            }

            $sheet = $xml($sheetPath);
            if (! $sheet) {
                throw new RuntimeException('Lembar kerja tidak ditemukan di berkas Excel.');
            }

            $rows = [];
            foreach ($sheet->sheetData->row as $row) {
                if (count($rows) >= $maxRows) {
                    break;
                }
                $cells = [];
                foreach ($row->c as $c) {
                    $ref = preg_replace('/\d+/', '', (string) $c['r']);
                    $index = $ref === '' ? count($cells) : self::columnIndex($ref);
                    $value = match ((string) $c['t']) {
                        's' => $shared[(int) $c->v] ?? '',
                        'inlineStr' => isset($c->is->t) ? (string) $c->is->t : implode('', array_map('strval', $c->is->xpath('.//*[local-name()="t"]') ?: [])),
                        'b' => (string) $c->v === '1' ? 'ya' : 'tidak',
                        default => self::number((string) $c->v),
                    };
                    $cells[$index] = $value;
                }
                $line = (int) $row['r'] ?: count($rows) + 1;
                $max = $cells ? max(array_keys($cells)) : -1;
                $rows[$line] = array_map(fn ($i) => $cells[$i] ?? '', $max >= 0 ? range(0, $max) : []);
            }
            ksort($rows);

            // Baris kosong di antara baris berisi dipertahankan agar nomor baris sama dengan Excel.
            $out = [];
            $last = $rows ? max(array_keys($rows)) : 0;
            for ($i = 1; $i <= $last; $i++) {
                $out[] = $rows[$i] ?? [];
            }

            return $out;
        } finally {
            $zip->close();
        }
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /** Angka Excel → teks tanpa ".0" & notasi ilmiah untuk bilangan bulat. */
    private static function number(string $v): string
    {
        if (is_numeric($v) && (float) $v == (int) (float) $v && abs((float) $v) < 1e15) {
            return (string) (int) (float) $v;
        }

        return $v;
    }
}
