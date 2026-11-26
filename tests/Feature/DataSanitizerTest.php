<?php

namespace Tests\Feature;

use App\Helpers\DataSanitizer;
use Tests\TestCase;

/**
 * What gets stripped from a name before it is stored.
 *
 * The visible cases — tags, stray whitespace — were already handled. The gap was
 * characters that are not visible at all but change how the text reads: a
 * right-to-left override reverses what follows it, so a stored name and a printed
 * name can differ on a receipt with nothing on screen to explain why.
 */
class DataSanitizerTest extends TestCase
{
    public function test_tags_are_removed(): void
    {
        $this->assertSame(
            'alert(1)',
            DataSanitizer::sanitizeString('<script>alert(1)</script>'),
        );
    }

    public function test_whitespace_is_collapsed_and_trimmed(): void
    {
        $this->assertSame('Red Shirt', DataSanitizer::sanitizeString("  Red \n\t Shirt  "));
    }

    /** The case that got through: it reverses the display of what follows. */
    public function test_a_right_to_left_override_is_removed(): void
    {
        $this->assertSame(
            'Test evil',
            DataSanitizer::sanitizeString("Test \u{202E} evil"),
        );
    }

    public function test_every_bidi_control_is_removed(): void
    {
        foreach (["\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
                  "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"] as $control) {
            $this->assertSame(
                'AB',
                DataSanitizer::sanitizeString('A' . $control . 'B'),
                'bidi control U+' . strtoupper(bin2hex($control)) . ' must not survive',
            );
        }
    }

    /** Two names that look identical must not be storable as different rows. */
    public function test_zero_width_characters_are_removed(): void
    {
        foreach (["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"] as $control) {
            $this->assertSame('AB', DataSanitizer::sanitizeString('A' . $control . 'B'));
        }
    }

    public function test_ordinary_text_is_untouched(): void
    {
        $this->assertSame('Paracetamol 500mg', DataSanitizer::sanitizeString('Paracetamol 500mg'));
    }

    /** Scripts and emoji are legitimate in a shop's own names. */
    public function test_real_writing_systems_survive(): void
    {
        $this->assertSame('पैरासिटामोल', DataSanitizer::sanitizeString('पैरासिटामोल'));
        $this->assertSame('Café', DataSanitizer::sanitizeString('Café'));
        $this->assertSame('Test 🧪', DataSanitizer::sanitizeString('Test 🧪'));
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull(DataSanitizer::sanitizeString(null));
    }

    /** Malformed input must not blank the field outright. */
    public function test_invalid_utf8_does_not_discard_the_value(): void
    {
        $this->assertNotSame('', DataSanitizer::sanitizeString("Valid\xB1\x31text"));
    }
}
