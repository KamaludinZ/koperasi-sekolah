<?php

namespace App\Support;

use App\Services\HealthCheckService;

/**
 * Pintu masuk lama untuk halaman & view: status, ambang disk, format ukuran berkas, dan laporan
 * kesehatan (didelegasikan ke HealthCheckService).
 */
class SystemHealth
{
    public const STATUSES = HealthCheckService::STATUSES;

    public const DISK_WARN = HealthCheckService::DISK_WARN;

    public const DISK_FAIL = HealthCheckService::DISK_FAIL;

    /** @return array{status: string, checked_at: string, checks: list<array>, metrics: array, incidents: list<array>} */
    public static function report(): array
    {
        return HealthCheckService::report();
    }

    public static function bytes(int|float $b): string
    {
        return HealthCheckService::bytes($b);
    }
}
