<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Position;
use Illuminate\Support\Facades\DB;

/** Tambah jabatan (FR-024). Jabatan baru belum punya bawahan, jadi tidak mungkin membentuk siklus. */
final class CreatePosition
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi PositionRequest (kunci = Position::AUDITED)
     */
    public function handle(array $data, ?int $userId): Position
    {
        return DB::transaction(function () use ($data, $userId) {
            $position = Position::query()->create([...$data, 'created_by' => $userId, 'updated_by' => $userId]);

            $this->audit->record('position.created', $position, null, self::audited($position));

            return $position;
        });
    }

    /** @return array<string, mixed> enum ditulis sebagai nilainya agar audit mudah dibaca */
    public static function audited(Position $position): array
    {
        return [...$position->only(Position::AUDITED), 'attendance_mode' => $position->attendance_mode->value];
    }
}
