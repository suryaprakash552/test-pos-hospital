<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ModuleCheckTest extends TestCase
{
    public function test_missing_module_name_is_handled_without_calling_repository(): void
    {
        $this->assertFalse(moduleCheck(null));
        $this->assertFalse(moduleCheck(''));
        $this->assertFalse(moduleCheck('   '));
    }
}
