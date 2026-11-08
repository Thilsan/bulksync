<?php

namespace App\Support;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * The rows of an Excel workbook's first sheet, as plain strings.
 *
 * An .xlsx file is a zip of XML, and PHP reads both, so this needs no package —
 * which matters here, because the server is deployed by copying files and a
 * new vendor/ directory does not travel. Streamed rather than loaded whole:
 * a 20 MB upload can unpack to a few hundred MB of sheet XML.
 *
 * Only what a SKU list needs: cell text and numbers, by column. Formulas give
 * their last computed value; formatting, dates and the other sheets are ignored.
 * The old binary .xls format is not an .xlsx and cannot be read this way.
 */
class XlsxReader
{
    /**
     * @return list<list<string>> each row's cells, gaps kept as ''
     * @throws RuntimeException when the file is not a readable workbook
     */
    public static function rows(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('not an Excel workbook');
        }

        try {
            $sheet = self::firstSheetPath($zip);

            if ($sheet === null || $zip->locateName($sheet) === false) {
                throw new RuntimeException('the workbook has no sheet');
            }

            $strings = self::sharedStrings($zip);

            return self::sheetRows($path, $sheet, $strings);
        } finally {
            $zip->close();
        }
    }

    /** The first sheet in the workbook's own order, not whichever file is named sheet1. */
    private static function firstSheetPath(ZipArchive $zip): ?string
    {
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $rels     = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $id)
            && preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($id[1], '/') . '"[^>]*>/', $rels, $rel)
            && preg_match('/\bTarget="([^"]+)"/', $rel[0], $target)) {
            $file = ltrim($target[1], '/');

            return str_starts_with($file, 'xl/') ? $file : 'xl/' . $file;
        }

        return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $xml = new XMLReader();
        $xml->XML((string) $zip->getFromName('xl/sharedStrings.xml'), null, LIBXML_NONET | LIBXML_COMPACT);

        $strings  = [];
        $current  = null;
        $phonetic = false;

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'si') {
                $current = '';
            } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'si') {
                $strings[] = $current;
                $current   = null;
            } elseif ($xml->localName === 'rPh' && !$xml->isEmptyElement) {
                // Phonetic hints carry a <t> of their own that is not part of the text.
                $phonetic = $xml->nodeType === XMLReader::ELEMENT;
            } elseif ($current !== null && !$phonetic && $xml->nodeType === XMLReader::ELEMENT && $xml->localName === 't') {
                // Rich text splits one string into several runs, each its own <t>.
                $current .= $xml->readString();
            }
        }

        $xml->close();

        return $strings;
    }

    /** @return list<list<string>> */
    private static function sheetRows(string $path, string $sheet, array $strings): array
    {
        $xml = new XMLReader();

        if (!$xml->open('zip://' . $path . '#' . $sheet, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('the sheet could not be read');
        }

        $rows = [];
        $row  = null;
        $col  = 0;

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'row') {
                $row = [];
                $col = 0;

                if ($xml->isEmptyElement) {
                    $rows[] = [];
                    $row    = null;
                }
            } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'row') {
                $rows[] = $row ?? [];
                $row    = null;
            } elseif ($row !== null && $xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'c') {
                // Empty cells are left out of the file, so a cell's place comes
                // from its reference ("C7"), not from how many came before it.
                $ref  = (string) $xml->getAttribute('r');
                $col  = $ref !== '' ? self::columnIndex($ref) : $col;
                $type = (string) $xml->getAttribute('t');

                $row  = array_pad($row, $col, '');
                $row[$col] = $xml->isEmptyElement ? '' : self::cellValue($xml, $type, $strings);
                $col++;
            }
        }

        $xml->close();

        return $rows;
    }

    /** Reads a <c> element's value and leaves the reader on its closing tag. */
    private static function cellValue(XMLReader $xml, string $type, array $strings): string
    {
        $depth = $xml->depth;
        $value = '';

        while ($xml->read() && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
            if ($xml->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($xml->localName === 'v') {
                $value = $xml->readString();
            } elseif ($xml->localName === 't' && $type === 'inlineStr') {
                $value .= $xml->readString();
            }
        }

        return match ($type) {
            's'         => (string) ($strings[(int) $value] ?? ''),
            'b'         => $value === '1' ? 'TRUE' : 'FALSE',
            'str', 'inlineStr', 'e' => $value,
            default     => self::number($value),
        };
    }

    /**
     * A number as it would be typed. Excel keeps a 13-digit barcode as
     * "6.291041500213E+12" and a whole number as "123.0" now and then; both
     * should come back the way the person entered them.
     */
    private static function number(string $value): string
    {
        if (!is_numeric($value)) {
            return $value;
        }

        if (stripos($value, 'e') !== false || str_contains($value, '.')) {
            $float = (float) $value;

            if (floor($float) === $float && abs($float) < 1e15) {
                return number_format($float, 0, '', '');
            }

            return rtrim(rtrim(sprintf('%.10F', $float), '0'), '.');
        }

        return $value;
    }

    /** "A1" → 0, "Z9" → 25, "AA3" → 26. */
    private static function columnIndex(string $ref): int
    {
        $letters = strtoupper((string) preg_replace('/\d+/', '', $ref));
        $index   = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
