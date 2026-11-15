<?php

namespace App\Domain\Employee\Models;

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Employee\Enums\ImportStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu berkas impor (04 §2.10). Batch memuat data pegawai (nama, nomor, NIK termasking), jadi
 * hanya terlihat oleh pemegang scope `all` atau oleh pengunggahnya sendiri (06 §5); di luar itu
 * route binding → 404 (SEC-033).
 *
 * @property int $id
 * @property string $type
 * @property string $original_name
 * @property string $stored_path
 * @property string $file_hash
 * @property ImportStatus $status
 * @property array<string, mixed>|null $mapping `header_row`, `headers`, `columns` (indeks → field), `failure`
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $error_rows
 * @property int $created_rows
 * @property int $updated_rows
 * @property int $skipped_rows
 * @property Carbon|null $completed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
class ImportBatch extends Model
{
    public const TYPE_EMPLOYEES = 'employees';

    /** Semua kolom diisi Action lewat forceFill, tidak pernah dari input mentah. */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'mapping' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    /**
     * @param  Builder<ImportBatch>  $query
     * @return Builder<ImportBatch>
     */
    public static function visibleTo(Builder $query, User $user): Builder
    {
        return ScopeResolver::for($user) === DataScope::All
            ? $query
            : $query->where($query->qualifyColumn('created_by'), $user->id);
    }

    public function isVisibleTo(User $user): bool
    {
        return ScopeResolver::for($user) === DataScope::All || $this->created_by === $user->id;
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $user = request()->user();
        if (! $user instanceof User) {
            return null;
        }

        return self::visibleTo(self::query(), $user)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * Kolom berkas → field sistem (JSON menyimpan indeks kolom sebagai kunci string).
     *
     * @return array<int, string>
     */
    public function columnMapping(): array
    {
        $columns = $this->mapping['columns'] ?? null;
        $map = [];
        foreach (is_array($columns) ? $columns : [] as $index => $field) {
            if (is_string($field)) {
                $map[(int) $index] = $field;
            }
        }

        return $map;
    }

    /** @return list<string> judul kolom berkas apa adanya */
    public function headers(): array
    {
        $headers = $this->mapping['headers'] ?? null;

        return is_array($headers) ? array_values(array_map(fn (mixed $h) => is_scalar($h) ? (string) $h : '', $headers)) : [];
    }

    public function headerRow(): int
    {
        $row = $this->mapping['header_row'] ?? null;

        return is_int($row) ? $row : 1;
    }
}
