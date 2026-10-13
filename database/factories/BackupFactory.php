<?php

namespace Database\Factories;

use App\Models\Backup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Backup> */
class BackupFactory extends Factory
{
    public function definition(): array
    {
        $at = now()->subDay()->setTime(23, 0);

        return [
            'name' => 'koperasi-'.$at->format('Y-m-d').'-'.fake()->unique()->numerify('######').'.zip',
            'path' => 'cadangan/'.$at->format('Y-m-d').'.zip',
            'type' => 'lengkap',
            'trigger' => 'terjadwal',
            'status' => 'berhasil',
            'size' => 2_400_000,
            'duration_s' => 20,
            'checksum' => hash('sha256', fake()->uuid()),
            'includes' => ['basis data', 'logo & berkas unggahan'],
            'started_at' => $at,
            'finished_at' => $at->copy()->addSeconds(20),
        ];
    }

    public function failed(string $error = 'Ruang penyimpanan penuh'): static
    {
        return $this->state(fn () => ['status' => 'gagal', 'path' => null, 'checksum' => null, 'size' => 0, 'includes' => [], 'error' => $error]);
    }
}
