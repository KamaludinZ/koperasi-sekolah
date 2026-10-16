<?php

namespace App\Support;

use App\Models\GuideOverride;

/**
 * Perbandingan isi dua versi panduan per bagian:
 * - judul & ringkasan: berubah atau tidak, isi lama & baru;
 * - langkah & tips: daftar berurutan dengan penanda sama / ditambah / dihapus (LCS per baris; langkah dibandingkan
 *   teks + tautan halaman);
 * - penanda bagian yang disunting (overridden): ditambah/dilepas.
 */
class GuideVersionDiff
{
    /**
     * @return array{changed: list<string>, fields: array<string, array>, overridden: array{added: list<string>, removed: list<string>}}
     */
    public static function compare(array $a, array $b): array
    {
        $fields = [];
        foreach (['title', 'summary'] as $f) {
            $fields[$f] = ['type' => 'text', 'changed' => ($a[$f] ?? null) !== ($b[$f] ?? null), 'old' => $a[$f] ?? null, 'new' => $b[$f] ?? null];
        }
        $step = fn ($s) => is_array($s) ? trim(($s['text'] ?? '').(! empty($s['route']) ? ' → '.$s['route'] : '')) : (string) $s;
        foreach (['steps' => $step, 'tips' => fn ($t) => (string) $t] as $f => $line) {
            $old = array_map($line, $a[$f] ?? []);
            $new = array_map($line, $b[$f] ?? []);
            $fields[$f] = ['type' => 'list', 'changed' => $old !== $new, 'lines' => self::lines($old, $new)];
        }
        $oa = $a['overridden'] ?? [];
        $ob = $b['overridden'] ?? [];

        return [
            'changed' => array_keys(array_filter($fields, fn ($x) => $x['changed'])),
            'fields' => $fields,
            'overridden' => ['added' => array_values(array_diff($ob, $oa)), 'removed' => array_values(array_diff($oa, $ob))],
        ];
    }

    /**
     * Beda baris berurutan (LCS).
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<array{op: 'same'|'add'|'del', text: string}>
     */
    public static function lines(array $old, array $new): array
    {
        $n = count($old);
        $m = count($new);
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $L[$i][$j] = $old[$i] === $new[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $out = [];
        [$i, $j] = [0, 0];
        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $out[] = ['op' => 'same', 'text' => $old[$i]];
                $i++;
                $j++;
            } elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) {
                $out[] = ['op' => 'del', 'text' => $old[$i++]];
            } else {
                $out[] = ['op' => 'add', 'text' => $new[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['op' => 'del', 'text' => $old[$i++]];
        }
        while ($j < $m) {
            $out[] = ['op' => 'add', 'text' => $new[$j++]];
        }

        return $out;
    }

    /** Label bagian. */
    public static function label(string $field): string
    {
        return GuideOverride::FIELDS[$field] ?? $field;
    }
}
