<?php

namespace Tests\Unit;

use App\Support\GuideVersionDiff;
use PHPUnit\Framework\TestCase;

class GuideVersionDiffTest extends TestCase
{
    public function test_line_diff_keeps_order_and_marks_changes(): void
    {
        $this->assertSame([
            ['op' => 'same', 'text' => 'a'], ['op' => 'del', 'text' => 'b'], ['op' => 'add', 'text' => 'x'], ['op' => 'same', 'text' => 'c'], ['op' => 'add', 'text' => 'd'],
        ], GuideVersionDiff::lines(['a', 'b', 'c'], ['a', 'x', 'c', 'd']));
        $this->assertSame([], GuideVersionDiff::lines([], []));
        $this->assertSame([['op' => 'del', 'text' => 'a']], GuideVersionDiff::lines(['a'], []));
    }

    public function test_compare_snapshots(): void
    {
        $a = ['title' => 'Judul', 'summary' => 'Ringkasan', 'steps' => [['text' => 'Satu', 'route' => 'kas'], ['text' => 'Dua']], 'tips' => ['T1'], 'overridden' => ['title']];
        $b = ['title' => 'Judul baru', 'summary' => 'Ringkasan', 'steps' => [['text' => 'Satu'], ['text' => 'Dua']], 'tips' => ['T1'], 'overridden' => ['steps']];
        $d = GuideVersionDiff::compare($a, $b);
        $this->assertSame(['title', 'steps'], $d['changed']);
        $this->assertSame(['Judul', 'Judul baru'], [$d['fields']['title']['old'], $d['fields']['title']['new']]);
        $this->assertSame(['del', 'add', 'same'], array_column($d['fields']['steps']['lines'], 'op'));   // tautan halaman ikut dibandingkan
        $this->assertSame(['added' => ['steps'], 'removed' => ['title']], $d['overridden']);
        $this->assertSame([], GuideVersionDiff::compare($a, $a)['changed']);
    }
}
