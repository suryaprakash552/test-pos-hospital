<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `$request->all() + [server values]` must not reach a money-bearing write.
 *
 * It reads as "server values as defaults", and does the opposite: PHP's array
 * union keeps the LEFT operand, so the request wins every collision. Because the
 * columns underneath are fillable, that let a caller set `user_id`,
 * `business_id`, `totalDue`, `dueAmountAfterPay`, `sale_id` and every other
 * derived figure by simply naming them in the payload.
 *
 * The same line appeared independently in three places - the API and web due
 * collections and the purchase update - which is what makes it worth a guard
 * rather than three fixes. Server-derived values go on the LEFT of the union, or
 * the field is listed in `except()`.
 */
class MassAssignmentGuardTest extends TestCase
{
    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];

        foreach (['app', 'Modules'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir)),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    public function test_no_write_unions_the_whole_request_over_server_values(): void
    {
        $hits = [];

        foreach ($this->phpFiles() as $path) {
            $source = (string) file_get_contents($path);

            foreach (explode("\n", $source) as $number => $line) {
                // The comments explaining this very fault quote the pattern, so
                // an explanatory line must not count as an occurrence.
                if (str_starts_with(ltrim($line), '//') || str_starts_with(ltrim($line), '*')) {
                    continue;
                }

                if (preg_match('/\$request->all\(\)\s*\+/', $line)) {
                    $hits[] = str_replace(base_path() . '/', '', $path) . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "These union the whole request over the server's own values, and the "
                . "request wins:\n- " . implode("\n- ", $hits)
                . "\nList the derived fields in `except()`, or build the payload explicitly.",
        );
    }

    /**
     * Ownership is the session's to decide, never the payload's.
     *
     * `except()` lists are easy to write for money and easy to forget for
     * ownership - and a missing entry is silent, because the explicit value
     * underneath simply never applies.
     */
    public function test_writes_that_exclude_money_also_exclude_ownership(): void
    {
        $hits = [];

        foreach ($this->phpFiles() as $path) {
            $source = (string) file_get_contents($path);

            // Every `->except(...)` list that drops a money column. Those are the
            // writes building a record out of a request, and the only ones this
            // rule is about - a report that merely mentions `totalAmount` in a
            // query is not one of them.
            //
            // The capture excludes `;`: without it, a call with no `+` on its own
            // closing paren (a plain `->except(...);`) let the lazy match run
            // past the statement entirely, latching onto the next unrelated
            // `)+` anywhere later in the file - a `SUM(a + b)` in a report query,
            // say - and swallowing everything in between as the "except list".
            // That produced a false positive naming a value (like `totalAmount`)
            // that was never in any except() call at all.
            preg_match_all('/->except\(([^;]*?)\)\s*\+/s', $source, $matches);

            foreach ($matches[1] as $list) {
                if (! str_contains($list, "'totalAmount'")) {
                    continue;
                }

                foreach (['user_id', 'business_id'] as $field) {
                    if (! str_contains($list, "'{$field}'")) {
                        $hits[] = str_replace(base_path() . '/', '', $path)
                            . ": an except() list drops money but not '{$field}'";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "Ownership must be excluded alongside the money, or the explicit value "
                . "below the union never applies:\n- " . implode("\n- ", $hits),
        );
    }
}
