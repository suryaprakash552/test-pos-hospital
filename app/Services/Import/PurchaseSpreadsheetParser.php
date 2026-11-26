<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

/** Converts the first worksheet to the same safe row shape as a reviewed CSV. */
class PurchaseSpreadsheetParser
{
    public function __construct(private readonly PurchaseCsvParser $csvParser)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function parse(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'purchase-sheet-');
        if ($path === false) {
            throw new \RuntimeException('Could not prepare the spreadsheet for review.');
        }

        try {
            file_put_contents($path, $contents, LOCK_EX);
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            $rows = $spreadsheet->getActiveSheet()->toArray('', true, true, false);
            $spreadsheet->disconnectWorksheets();

            $stream = fopen('php://temp', 'w+');
            foreach ($rows as $row) {
                fputcsv($stream, $row, ',', '"', '\\');
            }
            rewind($stream);
            $csv = stream_get_contents($stream) ?: '';
            fclose($stream);

            return $this->csvParser->parse($csv);
        } finally {
            @unlink($path);
        }
    }
}
