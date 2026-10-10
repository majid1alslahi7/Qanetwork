<?php

namespace App\Services\Networks;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class ReadInventorySpreadsheetService
{
    /** @return list<array{username: string, password?: string}> */
    public function read(UploadedFile $file): array
    {
        if ($file->getSize() > 5 * 1024 * 1024) {
            $this->invalid('The import file exceeds 5 MB.');
        }
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $rows = match ($extension) {
            'csv' => $this->csv($file->getRealPath()),
            'xlsx' => $this->xlsx($file->getRealPath()),
            default => $this->invalid('Use an XLSX or UTF-8 CSV file.'),
        };
        $header = array_shift($rows);
        if ($header === null) {
            $this->invalid('The file is empty.');
        }
        $aliases = ['username' => 'username', 'اسم المستخدم' => 'username', 'اسم_المستخدم' => 'username', 'password' => 'password', 'كلمة المرور' => 'password', 'كلمة_المرور' => 'password'];
        $mapping = [];
        foreach ($header as $column => $label) {
            $key = $aliases[mb_strtolower(trim(ltrim($label, "\xEF\xBB\xBF")))] ?? null;
            if ($key === null || in_array($key, $mapping, true)) {
                $this->invalid('Use username and optional password columns without extra columns.');
            }
            $mapping[$column] = $key;
        }
        if (! in_array('username', $mapping, true) || count($mapping) > 2) {
            $this->invalid('A username column is required.');
        }
        $cards = [];
        foreach ($rows as $index => $row) {
            if (array_filter($row, fn (string $value): bool => $value !== '') === []) {
                continue;
            }
            $card = [];
            foreach ($row as $column => $value) {
                if (! isset($mapping[$column]) && $value !== '') {
                    $this->invalid('Unexpected column at row '.($index + 2).'.');
                }
            }
            foreach ($mapping as $column => $key) {
                $card[$key] = $row[$column] ?? '';
            }
            $cards[] = $card;
        }
        if ($cards === [] || count($cards) > 5000) {
            $this->invalid('Import between 1 and 5000 cards per file.');
        }

        return $cards;
    }

    /** @return list<array<int, string>> */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->invalid('The file could not be read.');
        }
        try {
            $sample = fgets($handle);
            rewind($handle);
            $delimiter = $sample !== false && substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
            $rows = [];
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if (count($rows) >= 5001 || count($row) > 2) {
                    $this->invalid('The file exceeds the row or column limit.');
                }
                $values = array_map(fn (?string $value): string => $value ?? '', $row);
                foreach ($values as $value) {
                    if (! mb_check_encoding($value, 'UTF-8')) {
                        $this->invalid('Save CSV files with UTF-8 encoding.');
                    }
                }
                $rows[] = $values;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array<int, string>> */
    private function xlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            $this->invalid('XLSX support is unavailable on this server. Use UTF-8 CSV.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('The XLSX file is invalid.');
        }
        try {
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false || preg_match('~(?:^|/)\.\.(?:/|$)|externalLinks|vbaProject~i', $entry['name'])) {
                    $this->invalid('Unsupported workbook content.');
                }
                $expanded += $entry['size'];
                if ($entry['size'] > 8 * 1024 * 1024 || $expanded > 20 * 1024 * 1024 || $zip->numFiles > 200) {
                    $this->invalid('The expanded workbook exceeds the import limits.');
                }
            }
            $workbook = $this->xml($zip->getFromName('xl/workbook.xml'));
            $relations = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
            if (count($workbook->sheets->sheet) !== 1) {
                $this->invalid('Use a workbook containing one worksheet.');
            }
            $relationId = (string) $workbook->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $sheetPath = null;
            foreach ($relations->Relationship as $relation) {
                if ((string) $relation['TargetMode'] === 'External') {
                    $this->invalid('External workbook links are not supported.');
                }
                if ((string) $relation['Id'] === $relationId && preg_match('~^/?(?:xl/)?worksheets/[A-Za-z0-9_-]+\.xml$~', (string) $relation['Target'])) {
                    $target = ltrim((string) $relation['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
            if ($sheetPath === null) {
                $this->invalid('The worksheet relationship is invalid.');
            }
            $shared = [];
            $strings = $zip->getFromName('xl/sharedStrings.xml');
            if ($strings !== false) {
                foreach ($this->xml($strings)->si as $item) {
                    $shared[] = $this->text($item);
                    if (count($shared) > 10002) {
                        $this->invalid('The workbook has too many shared strings.');
                    }
                }
            }
            $styles = [];
            $formats = [];
            $styleXml = $zip->getFromName('xl/styles.xml');
            if ($styleXml !== false) {
                $style = $this->xml($styleXml);
                foreach ($style->numFmts->numFmt ?? [] as $format) {
                    $formats[(int) $format['numFmtId']] = (string) $format['formatCode'];
                }
                foreach ($style->cellXfs->xf as $format) {
                    $styles[] = (int) $format['numFmtId'];
                }
            }
            $rows = [];
            foreach ($this->xml($zip->getFromName($sheetPath))->sheetData->row as $row) {
                if (count($rows) >= 5001) {
                    $this->invalid('The worksheet exceeds 5000 cards.');
                }
                $values = [];
                foreach ($row->c as $cell) {
                    if (! preg_match('/^([AB])[1-9][0-9]*$/', (string) $cell['r'], $matches) || isset($cell->f)) {
                        $this->invalid('Use only username/password columns without formulas.');
                    }
                    $column = $matches[1] === 'A' ? 0 : 1;
                    $type = (string) $cell['t'];
                    $value = (string) $cell->v;
                    if ($type === 's') {
                        if (! ctype_digit($value) || ! array_key_exists((int) $value, $shared)) {
                            $this->invalid('Invalid shared string reference.');
                        }
                        $value = $shared[(int) $value];
                    } elseif ($type === 'inlineStr') {
                        $value = $this->text($cell->is);
                    } elseif ($value !== '' && ($type === '' || $type === 'n')) {
                        if (! ctype_digit($value) || strlen($value) > 15) {
                            $this->invalid('Store long codes and passwords as Text in Excel to preserve them exactly.');
                        }
                        $formatId = $styles[(int) $cell['s']] ?? 0;
                        $format = $formats[$formatId] ?? null;
                        if ($format !== null && preg_match('/^0{1,255}$/', $format)) {
                            $value = str_pad($value, strlen($format), '0', STR_PAD_LEFT);
                        } elseif (! in_array($formatId, [0, 1, 49], true)) {
                            $this->invalid('Use plain Text cells for codes with special formatting.');
                        }
                    } elseif ($type !== 'str' && $value !== '') {
                        $this->invalid('Unsupported cell type. Store card credentials as Text.');
                    }
                    if (strlen($value) > 1024 || isset($values[$column])) {
                        $this->invalid('Invalid or repeated worksheet cell.');
                    }
                    $values[$column] = $value;
                }
                if ($values !== []) {
                    $rows[] = $values;
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function xml(string|false $contents): SimpleXMLElement
    {
        if ($contents === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $contents)) {
            $this->invalid('Invalid workbook XML.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                $this->invalid('Invalid workbook XML.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function text(SimpleXMLElement $node): string
    {
        $value = (string) $node->t;
        foreach ($node->r as $run) {
            $value .= (string) $run->t;
        }

        return $value;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
