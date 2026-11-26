<?php

namespace App\Services\Import;

use Throwable;

final class ImportFailureMessage
{
    /** Never send arbitrary exception text (SQL, paths, or document data) to the app. */
    public static function forUser(Throwable $error): string
    {
        return self::fromStored($error->getMessage());
    }

    /** Also protects batches saved before this guard was deployed. */
    public static function fromStored(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        // Previously saved batches refer to a photo feature that is disabled.
        if (in_array($message, [
            'This PDF could not be read. If it is a scan rather than a digital bill, use Scan a Bill Picture instead.',
            'No product rows were found. If this is a scanned bill rather than a digital one, use Scan a Bill Picture instead.',
        ], true)) {
            return 'Image-only PDFs are not supported yet. Use a text PDF, Excel or CSV.';
        }

        foreach ([
            'The uploaded file could not be read.',
            'Could not prepare the spreadsheet for review.',
            'That file is not a PDF.',
            'No named products were found. Remove empty rows or use a text PDF, Excel or CSV.',
            'No product rows were found. Image-only PDFs are not supported yet. Use a text PDF, Excel or CSV.',
            'This PDF could not be read. If it is a scan rather than a digital bill, image recognition is not available yet. Use a text PDF, Excel or CSV.',
            'This PDF could not be read. If it is a scan rather than a digital bill, use Scan a Bill Picture instead.',
            'No product rows were found. If this is a scanned bill rather than a digital one, use Scan a Bill Picture instead.',
            'No product rows could be read from that picture. Try again with the bill flat, well lit, and filling the frame.',
            'No product rows were found. Check that the file has a header row naming the product, quantity and rate columns.',
        ] as $safeMessage) {
            if ($message === $safeMessage) {
                return $safeMessage;
            }
        }

        return 'This document could not be imported. Check its format and try again, or use another import method.';
    }
}
