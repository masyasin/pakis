<?php

namespace App\Support\Export;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Kolom berkas ekspor: judul + cara mengambil nilai dari satu model. Data sensitif (NIK) wajib
 * dimasking di sini, bukan di tampilan saja (BR-005).
 *
 * Generik per model agar Larastan memeriksa atribut yang dibaca tiap kolom:
 *
 *     ExportColumns::for(Employee::class)->add('Nama', fn (Employee $e) => $e->full_name)
 *
 * @template TModel of Model
 */
final class ExportColumns
{
    /** @var list<array{header: string, value: Closure(TModel): mixed}> */
    private array $columns = [];

    /**
     * @param  class-string<TModel>  $modelClass
     */
    private function __construct(private readonly string $modelClass) {}

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return self<T>
     */
    public static function for(string $model): self
    {
        return new self($model);
    }

    /**
     * @param  Closure(TModel): mixed  $value
     * @return $this
     */
    public function add(string $header, Closure $value): self
    {
        $this->columns[] = ['header' => $header, 'value' => $value];

        return $this;
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return array_column($this->columns, 'header');
    }

    /**
     * Query ekspor dan kolom bisa berasal dari model berbeda bila listing salah tulis;
     * pemeriksaan ini menggagalkannya dengan pesan jelas, bukan TypeError di tengah berkas.
     *
     * @return list<mixed>
     */
    public function values(Model $model): array
    {
        if (! $model instanceof $this->modelClass) {
            throw new LogicException(sprintf(
                'Kolom ekspor untuk %s menerima %s; exportQuery() dan exportColumns() harus model yang sama.',
                $this->modelClass,
                $model::class,
            ));
        }

        return array_map(fn (array $column) => ($column['value'])($model), $this->columns);
    }
}
