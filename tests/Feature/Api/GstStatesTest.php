<?php

namespace Tests\Feature\Api;

use App\Services\GstStates;
use Tests\TestCase;

/**
 * The GSTIN/state agreement check.
 *
 * The consequence of getting this wrong runs in one direction: a false *pass* lets a
 * mismatched registration through and the filing is rejected months later, while a
 * false *fail* blocks a branch from being saved at all. So an unrecognised state name
 * passes rather than fails — the GSTIN format check stands on its own regardless.
 */
class GstStatesTest extends TestCase
{
    public function test_a_state_maps_to_its_code(): void
    {
        $this->assertSame('29', GstStates::codeFor('Karnataka'));
        $this->assertSame('27', GstStates::codeFor('Maharashtra'));
        $this->assertSame('07', GstStates::codeFor('Delhi'));
    }

    public function test_matching_ignores_case_and_spacing(): void
    {
        $this->assertSame('29', GstStates::codeFor('  karnataka '));
        $this->assertSame('33', GstStates::codeFor('TAMIL NADU'));
        $this->assertSame('33', GstStates::codeFor('Tamilnadu'));
    }

    /** Records carry the older names for years after a rename. */
    public function test_former_names_still_resolve(): void
    {
        $this->assertSame('21', GstStates::codeFor('Orissa'));
        $this->assertSame('34', GstStates::codeFor('Pondicherry'));
        $this->assertSame('05', GstStates::codeFor('Uttaranchal'));
    }

    public function test_an_ampersand_reads_as_and(): void
    {
        $this->assertSame('01', GstStates::codeFor('Jammu & Kashmir'));
    }

    public function test_a_code_entered_directly_is_accepted(): void
    {
        $this->assertSame('29', GstStates::codeFor('29'));
        $this->assertSame('07', GstStates::codeFor('7'));
    }

    public function test_an_unknown_name_has_no_code(): void
    {
        $this->assertNull(GstStates::codeFor('Atlantis'));
        $this->assertNull(GstStates::codeFor(''));
        $this->assertNull(GstStates::codeFor(null));
    }

    public function test_a_code_maps_back_to_its_name(): void
    {
        $this->assertSame('Karnataka', GstStates::nameFor('29'));
        $this->assertNull(GstStates::nameFor('99'));
    }

    public function test_an_agreeing_gstin_and_state_match(): void
    {
        $this->assertTrue(GstStates::matches('29ABCDE1234F1Z5', 'Karnataka'));
    }

    /** The failure this exists to catch. */
    public function test_a_gstin_from_another_state_does_not_match(): void
    {
        $this->assertFalse(GstStates::matches('29ABCDE1234F1Z5', 'Maharashtra'));
    }

    public function test_an_unknown_state_is_not_treated_as_a_mismatch(): void
    {
        // Refusing here would block a branch whose state is merely spelled in a way
        // this list does not carry.
        $this->assertTrue(GstStates::matches('29ABCDE1234F1Z5', 'Some New Territory'));
    }

    public function test_a_missing_gstin_is_not_a_mismatch(): void
    {
        $this->assertTrue(GstStates::matches(null, 'Karnataka'));
        $this->assertTrue(GstStates::matches('nonsense', 'Karnataka'));
    }

    public function test_every_code_is_two_digits_and_named(): void
    {
        foreach (GstStates::CODES as $code => $name) {
            $this->assertMatchesRegularExpression('/^[0-9]{2}$/', $code);
            $this->assertNotSame('', trim($name));
        }
    }

    public function test_options_are_offered_for_a_picker(): void
    {
        $options = GstStates::options();

        $this->assertNotEmpty($options);
        $this->assertArrayHasKey('code', $options[0]);
        $this->assertArrayHasKey('name', $options[0]);
    }
}
