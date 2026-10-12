<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Pengumuman buatan Admin/Pengurus = notifikasi bertipe `pengumuman` (tabel notifications).
 * Masuk ke kotak notifikasi petugas sesuai sasaran; bila show_public, tampil di beranda
 * publik sampai expires_at. Admin boleh mengubah/menghapus semua pengumuman, Pengurus
 * hanya miliknya. Setiap perubahan dicatat di audit (kategori pengumuman).
 */
class AnnouncementService
{
    public const TARGETS = [
        'semua' => 'Semua (petugas & anggota)',
        'anggota' => 'Anggota (siswa & guru)',
        'kasir' => 'Petugas kasir',
        'pengurus' => 'Pengurus',
    ];

    /** Sasaran yang boleh tampil di beranda publik. */
    public const PUBLIC_TARGETS = ['semua', 'anggota'];

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:80'],
            'message' => ['required', 'string', 'min:10', 'max:500'],
            'target' => ['required', 'in:'.implode(',', array_keys(self::TARGETS))],
            'show_public' => ['boolean'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    public static function messages(): array
    {
        return [
            'title.required' => 'Judul wajib diisi.',
            'title.min' => 'Judul minimal :min karakter.',
            'message.required' => 'Isi pengumuman wajib diisi.',
            'message.min' => 'Isi minimal :min karakter.',
            'max' => 'Maksimal :max karakter.',
            'target.in' => 'Pilih sasaran pengumuman.',
            'expires_at.date_format' => 'Format tanggal YYYY-MM-DD.',
            'expires_at.after_or_equal' => 'Tanggal berakhir tidak boleh sebelum hari ini.',
        ];
    }

    /** Semua pengumuman (aktif & lewat), terbaru dulu. */
    public function list(): array
    {
        return Notification::with('author:id,name')->where('type', 'pengumuman')->whereNotNull('created_by')
            ->latest()->orderByDesc('id')->get()
            ->map(fn (Notification $n) => self::toArray($n))->all();
    }

    /** Pengumuman aktif untuk beranda publik. */
    public function publicList(int $limit = 5): array
    {
        return Notification::public()->latest()->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Notification $n) => self::toArray($n))->all();
    }

    /** @param  array{title: string, message: string, target: string, show_public?: bool, expires_at?: ?string}  $data  sudah divalidasi */
    public function create(array $data, User $by): Notification
    {
        return DB::transaction(function () use ($data, $by) {
            $n = Notification::create([...$this->normalize($data), 'type' => 'pengumuman', 'created_by' => $by->id]);
            Notification::markFor($by, [$n->id]); // penulis tidak perlu diberi tahu tulisannya sendiri

            AuditLog::record('announcement.created', 'pengumuman', "Pengumuman \"{$n->title}\" untuk {$n->target_label}".($n->show_public ? ', tampil di beranda' : ''),
                ['type' => 'notification', 'id' => $n->id, 'label' => $n->title],
                ['target' => $n->target, 'show_public' => $n->show_public, 'expires_at' => $n->expires_at?->toDateString()], null, $this->actor($by));

            return $n->load('author:id,name');
        });
    }

    public function update(Notification $n, array $data, User $by): Notification
    {
        $this->authorize($n, $by);
        $fields = ['title', 'message', 'target', 'show_public', 'expires_at'];
        $before = [...$n->only($fields), 'expires_at' => $n->expires_at?->toDateString()];

        $n->update($this->normalize($data));
        $after = [...$n->only($fields), 'expires_at' => $n->expires_at?->toDateString()];

        if ($changes = AuditLog::diff($before, $after, $fields)) {
            AuditLog::record('announcement.updated', 'pengumuman', "Pengumuman \"{$n->title}\" diubah: ".implode(', ', array_keys($changes)),
                ['type' => 'notification', 'id' => $n->id, 'label' => $n->title], ['changes' => $changes], null, $this->actor($by));
        }

        return $n->load('author:id,name');
    }

    public function delete(Notification $n, User $by): void
    {
        $this->authorize($n, $by);
        AuditLog::record('announcement.deleted', 'pengumuman', "Pengumuman \"{$n->title}\" dihapus",
            ['type' => 'notification', 'id' => $n->id, 'label' => $n->title], ['target' => $n->target], null, $this->actor($by));
        $n->delete();
    }

    public function canManage(Notification $n, User $user): bool
    {
        return $n->type === 'pengumuman' && ($user->hasRole('admin') || ($user->hasRole('pengurus') && $n->created_by === $user->id));
    }

    public static function toArray(Notification $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->title,
            'message' => $n->message,
            'target' => $n->target,
            'target_label' => self::TARGETS[$n->target] ?? $n->target_label,
            'show_public' => $n->show_public,
            'expires_at' => $n->expires_at?->toDateString(),
            'is_active' => ! $n->expires_at || $n->expires_at->gte(today()),
            'author' => $n->author?->name,
            'author_id' => $n->created_by,
            'created_at' => $n->created_at->toDateTimeString(),
        ];
    }

    private function normalize(array $data): array
    {
        return [
            'title' => trim($data['title']),
            'message' => trim($data['message']),
            'target' => $data['target'],
            // Pengumuman khusus petugas tidak pernah tampil di beranda publik.
            'show_public' => (bool) ($data['show_public'] ?? false) && in_array($data['target'], self::PUBLIC_TARGETS, true),
            'expires_at' => ($data['expires_at'] ?? null) ?: null,
        ];
    }

    private function authorize(Notification $n, User $by): void
    {
        if (! $this->canManage($n, $by)) {
            throw new AuthorizationException('Hanya Admin atau pengurus pembuatnya yang dapat mengubah pengumuman ini.');
        }
    }

    private function actor(User $by): ?array
    {
        return $by->id === auth()->id() ? null : ['id' => $by->id, 'name' => $by->name, 'role' => $by->roleName()];
    }
}
