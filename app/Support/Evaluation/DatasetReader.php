<?php

namespace App\Support\Evaluation;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use RuntimeException;

/**
 * Reads the first sheet of a CSV, XLSX or XLS file into a header row and data rows of strings.
 */
class DatasetReader
{
    public const EXTENSIONS = ['csv', 'xlsx', 'xls'];

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    public static function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException(__('Upload a CSV or Excel (.xlsx, .xls) file.'));
        }

        try {
            $reader = IOFactory::createReader(match ($extension) {
                'csv' => 'Csv',
                'xls' => 'Xls',
                default => 'Xlsx',
            });

            if ($reader instanceof Csv) {
                $reader->setInputEncoding(Csv::GUESS_ENCODING);
            }

            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
            $table = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $exception) {
            throw new RuntimeException(__('The file could not be read: :reason', ['reason' => $exception->getMessage()]), previous: $exception);
        }

        $table = array_map(
            fn (array $row) => array_map(fn ($value) => trim(self::text($value)), $row),
            $table,
        );

        // Drop empty rows and trailing empty columns.
        $table = array_values(array_filter($table, fn (array $row) => implode('', $row) !== ''));
        $headers = array_shift($table) ?? [];

        while ($headers !== [] && end($headers) === '' && collect($table)->every(fn ($row) => ($row[array_key_last($headers)] ?? '') === '')) {
            array_pop($headers);
        }

        if ($headers === [] || $table === []) {
            throw new RuntimeException(__('The file has no responses: it needs a header row and at least one response.'));
        }

        $width = count($headers);

        return [
            'headers' => $headers,
            'rows' => array_map(fn (array $row) => array_pad(array_slice($row, 0, $width), $width, ''), $table),
        ];
    }

    private static function text(mixed $value): string
    {
        $text = is_float($value) && floor($value) === $value ? (string) (int) $value : (string) ($value ?? '');

        return self::repairEncoding($text);
    }

    /**
     * Fixes text that was saved as UTF-8 twice, e.g. "donâ€™t" back to "don’t".
     */
    public static function repairEncoding(string $text): string
    {
        if (! preg_match('/Ã.|â€/u', $text)) {
            return $text;
        }

        $repaired = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return mb_check_encoding($repaired, 'UTF-8') ? $repaired : $text;
    }
}
