<?php

namespace Tests\Feature\Kas;

use Tests\TestCase;

class DifferenceBadgeTest extends TestCase
{
    protected ?string $loginAs = null;

    private function badge(string $attributes): string
    {
        return (string) $this->blade('<x-kas.difference-badge '.$attributes.' />');
    }

    public function test_states_have_label_icon_and_tone(): void
    {
        $this->blade('<x-kas.difference-badge :amount="0" />')->assertSee('Pas')->assertSee('bg-emerald-100', false)->assertSee('<svg', false);
        $this->blade('<x-kas.difference-badge :amount="-2000" />')->assertSee('Kurang')->assertSee('Rp2.000')->assertSee('bg-red-100', false);
        $this->blade('<x-kas.difference-badge :amount="1500" />')->assertSee('Lebih')->assertSee('Rp1.500')->assertSee('bg-amber-100', false);
        $this->blade('<x-kas.difference-badge :amount="null" />')->assertSee('Belum dihitung');
        $this->blade('<x-kas.difference-badge :amount="-500" running />')->assertSee('Berjalan')->assertDontSee('Rp500');
    }

    public function test_nominal_can_be_hidden_but_stays_in_title(): void
    {
        $html = $this->badge(':amount="-2000" :nominal="false"');

        $this->assertStringContainsString('title="Kas kurang Rp2.000"', $html);
        $this->assertStringNotContainsString('tabular-nums', $html);
    }

    public function test_size_and_extra_classes(): void
    {
        $html = $this->badge(':amount="0" size="md" class="ml-2"');

        $this->assertStringContainsString('text-sm', $html);
        $this->assertStringContainsString('ml-2', $html);
    }
}
