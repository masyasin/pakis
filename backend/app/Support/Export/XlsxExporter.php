<?php

namespace App\Support\Export;

use App\Support\Time\DisplayTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Stringable;

/**
 * Menulis .xlsx secara streaming (OpenSpout): baris dibaca per 1.000 dari DB dan langsung ditulis,
 * sehingga memori tetap kecil walau ekspor 100.000 baris.
 */
final class XlsxExporter
{
    private const CHUNK = 1000;

    /**
     * @param  Builder<*>  $query  query yang sudah tersaring & tersortir (ListingResult::query())
     * @param  ExportColumns<*>  $columns
     * @return int jumlah baris data yang ditulis
     */
    public function write(Builder $query, ExportColumns $columns, string $absolutePath): int
    {
        $directory = dirname($absolutePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        $writer = new Writer;
        $writer->openToFile($absolutePath);

        try {
            $writer->addRow(Row::fromValuesWithStyle($columns->headers(), (new Style)->withFontBold(true)));

            $count = 0;
            foreach ($query->lazy(self::CHUNK) as $model) {
                /** @var Model $model */
                $writer->addRow(Row::fromValues(array_map(
                    fn (mixed $value) => FormulaEscaper::escape(self::normalize($value)),
                    $columns->values($model),
                )));
                $count++;
            }
        } finally {
            $writer->close();
        }

        return $count;
    }

    /**
     * Ubah nilai ke tipe yang dipahami OpenSpout.
     */
    private static function normalize(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, is_string($value), is_int($value), is_float($value), is_bool($value) => $value,
            // Waktu disimpan UTC; berkas untuk manusia memakai zona tampilan (04 §1).
            $value instanceof DateTimeInterface => DisplayTime::toLocal(CarbonImmutable::instance($value))->format('Y-m-d H:i'),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof Stringable => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
