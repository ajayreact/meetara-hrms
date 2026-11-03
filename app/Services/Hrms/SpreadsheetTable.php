<?php

namespace App\Services\Hrms;

use ZipArchive;

class SpreadsheetTable
{
    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->csv($path);
        }

        if ($extension === 'xlsx') {
            return $this->xlsx($path);
        }

        throw new PayrollRejected('Upload a CSV or XLSX file. Older Excel .xls files are not accepted.');
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new PayrollRejected('The uploaded file could not be read.');
        }

        $first = fgets($handle);

        if ($first === false) {
            fclose($handle);

            throw new PayrollRejected('The uploaded file is empty.');
        }

        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $headers = $this->cells(str_getcsv($first));
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            $cells = $this->cells($line);

            if ($this->blank($cells)) {
                continue;
            }

            $rows[] = $cells;
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    private function xlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new PayrollRejected('XLSX upload is not available on this server. Save the sheet as CSV and upload that.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new PayrollRejected('The XLSX file could not be opened.');
        }

        $shared = $this->sharedStrings($zip->getFromName('xl/sharedStrings.xml'));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (! is_string($sheet) || $sheet === '') {
            throw new PayrollRejected('The XLSX file has no first worksheet.');
        }

        $xml = simplexml_load_string($sheet);

        if ($xml === false) {
            throw new PayrollRejected('The XLSX worksheet could not be read.');
        }

        $grid = [];

        foreach ($xml->sheetData->row ?? [] as $row) {
            $line = [];

            foreach ($row->c ?? [] as $cell) {
                $reference = (string) $cell['r'];
                $column = $this->columnIndex($reference);
                $line[$column] = $this->cellValue($cell, $shared);
            }

            if ($line === []) {
                continue;
            }

            $width = max(array_keys($line)) + 1;
            $values = array_fill(0, $width, '');

            foreach ($line as $index => $value) {
                $values[$index] = $value;
            }

            if (! $this->blank($values)) {
                $grid[] = $values;
            }
        }

        if ($grid === []) {
            throw new PayrollRejected('The uploaded file is empty.');
        }

        $headers = array_shift($grid);

        return ['headers' => $this->cells($headers), 'rows' => $grid];
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(string|false $xml): array
    {
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $parsed = simplexml_load_string($xml);

        if ($parsed === false) {
            return [];
        }

        $strings = [];

        foreach ($parsed->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;

                continue;
            }

            $text = '';

            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    private function cellValue(\SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell['t'];

        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        $value = (string) ($cell->v ?? '');

        if ($type === 's') {
            return trim($shared[(int) $value] ?? '');
        }

        return trim($value);
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    /**
     * @param  list<string|null>  $cells
     * @return list<string>
     */
    private function cells(array $cells): array
    {
        return array_map(fn ($cell): string => trim((string) $cell), $cells);
    }

    /**
     * @param  list<string>  $cells
     */
    private function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
