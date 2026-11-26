<?php

namespace App\Services;

/**
 * India's GST state codes.
 *
 * The first two digits of a GSTIN are the state the registration belongs to, so a
 * GSTIN and a stated state can contradict each other. §17 requires them to agree:
 * a return filed under a GSTIN whose state code disagrees with the address on the
 * invoice is rejected, and the rejection arrives long after the invoices were issued.
 *
 * Codes are from the GST state code list; union territories are included because a
 * branch can be registered in one.
 */
class GstStates
{
    /** @var array<string, string> code => canonical state name */
    public const CODES = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
        '97' => 'Other Territory',
    ];

    /**
     * Names that appear on real records but are not the canonical spelling.
     *
     * Kept small and explicit rather than fuzzy-matched: a wrong guess here would
     * silently accept a mismatched GSTIN, which is the failure this exists to catch.
     *
     * @var array<string, string> alias => canonical name
     */
    private const ALIASES = [
        'orissa'                             => 'Odisha',
        'pondicherry'                        => 'Puducherry',
        'uttaranchal'                        => 'Uttarakhand',
        'new delhi'                          => 'Delhi',
        'nct of delhi'                       => 'Delhi',
        'delhi ncr'                           => 'Delhi',
        'j&k'                                => 'Jammu and Kashmir',
        'jammu & kashmir'                    => 'Jammu and Kashmir',
        'dadra and nagar haveli'             => 'Dadra and Nagar Haveli and Daman and Diu',
        'daman and diu'                      => 'Dadra and Nagar Haveli and Daman and Diu',
        'andaman and nicobar'                => 'Andaman and Nicobar Islands',
        'tamilnadu'                          => 'Tamil Nadu',
    ];

    /** The code a state name maps to, or null when the name is not recognised. */
    public static function codeFor(?string $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        // A user may enter the code itself; accept it.
        $trimmed = trim($state);
        if (isset(self::CODES[$trimmed])) {
            return $trimmed;
        }
        if (preg_match('/^[0-9]$/', $trimmed) && isset(self::CODES['0' . $trimmed])) {
            return '0' . $trimmed;
        }

        $normalised = self::normalise($trimmed);
        $normalised = self::ALIASES[$normalised] ?? $normalised;

        foreach (self::CODES as $code => $name) {
            if (self::normalise($name) === self::normalise($normalised)) {
                return $code;
            }
        }

        return null;
    }

    /** The canonical state name for a code, or null when the code is unknown. */
    public static function nameFor(?string $code): ?string
    {
        return $code === null ? null : (self::CODES[trim($code)] ?? null);
    }

    /**
     * Whether a GSTIN's own state code agrees with the stated state.
     *
     * An unrecognised state name returns true rather than false: refusing to save a
     * branch because its state is spelled in a way this list does not carry would
     * block legitimate data, and the GSTIN format check already stands separately.
     */
    public static function matches(?string $gstin, ?string $state): bool
    {
        $gstinCode = GstConfiguration::stateCodeOf($gstin);
        $stateCode = self::codeFor($state);

        if ($gstinCode === null || $stateCode === null) {
            return true;
        }

        return $gstinCode === $stateCode;
    }

    /** Every state, as the client needs it for a picker. */
    public static function options(): array
    {
        $options = [];

        foreach (self::CODES as $code => $name) {
            $options[] = ['code' => $code, 'name' => $name];
        }

        return $options;
    }

    private static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['&', '.'], ['and', ''], $value);

        return preg_replace('/\s+/', ' ', $value);
    }
}
