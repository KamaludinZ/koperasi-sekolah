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
 * - POST   /pengaturan/data/profile/logo       → unggah logo (multipart `logo`)
 * - DELETE /pengaturan/data/profile/logo       → hapus logo (kembali ke lambang bawaan)
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

    public function uploadLogo(Request $request, SettingsService $settings): JsonResponse
    {
        $request->validate(
            ['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:min_width=64,min_height=64']],
            [
                'logo.required' => 'Pilih berkas logo.',
                'logo.image' => 'Logo harus berupa gambar.',
                'logo.mimes' => 'Gunakan PNG, JPG, atau WEBP.',
                'logo.max' => 'Ukuran logo maksimal 512 KB.',
                'logo.dimensions' => 'Logo minimal 64×64 piksel.',
            ],
        );

        $file = $request->file('logo');
        $all = $settings->save('profile', ['logo' => 'data:'.$file->getMimeType().';base64,'.base64_encode($file->get())], $request->user());

        return response()->json(['data' => $all['profile'], 'meta' => $this->meta($all)]);
    }

    public function deleteLogo(Request $request, SettingsService $settings): JsonResponse
    {
        $all = $settings->save('profile', ['logo' => null], $request->user());

        return response()->json(['data' => $all['profile'], 'meta' => $this->meta($all)]);
    }

    private function meta(array $all): array
    {
        return ['updated_at' => $all['updated_at'], 'updated_by' => $all['updated_by'], 'defaults' => SettingsService::DEFAULTS];
    }
}
