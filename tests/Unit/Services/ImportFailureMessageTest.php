<?php

namespace Tests\Unit\Services;

use App\Services\Import\ImportFailureMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ImportFailureMessageTest extends TestCase
{
    public function test_known_file_guidance_is_preserved(): void
    {
        $message = 'That file is not a PDF.';

        $this->assertSame($message, ImportFailureMessage::forUser(new RuntimeException($message)));
    }

    public function test_internal_exception_details_are_not_shown_to_employee(): void
    {
        $internal = 'SQLSTATE connection failed at /var/www/app with secret document content';
        $visible = ImportFailureMessage::forUser(new RuntimeException($internal));

        $this->assertStringNotContainsString('SQLSTATE', $visible);
        $this->assertStringNotContainsString('/var/www', $visible);
        $this->assertStringNotContainsString('secret', $visible);
        $this->assertStringContainsString('try again', $visible);
        $this->assertSame($visible, ImportFailureMessage::fromStored($internal));
        $this->assertNull(ImportFailureMessage::fromStored(null));
    }
}
