<?php

namespace App\Http\Controllers\Pengaturan;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Ambil & simpan pengaturan koperasi (JSON, khusus admin — menu pengaturan):
 * - GET    /pengaturan/data                    → semua bagian + kapan/oleh siapa diubah
 * - GET    /pengaturan/data/{bagian}           → satu bagian: profile | receipt | tax
 * - PUT    /pengaturan/data/{bagian}           → simpan sebagian/seluruh isian bagian itu
 * - POST   /pengaturan/data/{bagian}/reset     → kembalikan bagian ke nilai bawaan
 * Logo diunggah/dihapus lewat LogoController.
 */
class SettingsController extends Controller
{
    public function index(SettingsService $settings): JsonResponse
    {
        $all = $settings->all();

        return response()->json([
            'data' => collect($all)->only(SettingsService::SECTIONS),
            'meta' => $this->meta($all),
        ]);
    }

    public function show(string $section, SettingsService $settings): JsonResponse
    {
        $all = $settings->all();

        return response()->json(['data' => $all[$section], 'meta' => $this->meta($all)]);
    }

    public function update(Request $request, string $section, SettingsService $settings): JsonResponse
    {
        $input = $request->except('logo'); // logo lewat endpoint unggah tersendiri
        $data = Validator::make($input, SettingsService::rules($section, $input), SettingsService::messages())
            ->after(function ($validator) use ($section, $input, $settings) {
                if ($validator->errors()->isEmpty()) {
                    foreach (SettingsService::crossCheck($section, $input, $settings->section($section)) as $key => $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            })
            ->validate();

        $all = $settings->save($section, SettingsService::normalize($section, $data), $request->user());

        return response()->json(['data' => $all[$section], 'meta' => $this->meta($all)]);
    }

    public function reset(Request $request, string $section, SettingsService $settings): JsonResponse
    {
        $all = $settings->reset($section, $request->user());

        return response()->json(['data' => $all[$section], 'meta' => $this->meta($all)]);
    }

    private function meta(array $all): array
    {
        return ['updated_at' => $all['updated_at'], 'updated_by' => $all['updated_by'], 'defaults' => SettingsService::DEFAULTS];
    }
}
