<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Tests\TestCase;

/**
 * The letter-to-permission map must have no repeated keys.
 *
 * It is a single array literal, so a repeated key keeps only the last value and
 * says nothing. Two had crept in:
 *
 *  - `'m' => 'item-override'` was replaced by `'m' => 'manual-discount'`, which
 *    made the GST item override unreachable; and
 *  - `'d' => 'delete'` was replaced by `'d' => 'modify-dates'`, which is far
 *    worse: every module lists 'd' for delete, so the seeder created
 *    `<module>-modify-dates` for all of them and `<module>-delete` never existed
 *    at all. Deleting a product was refused while creating and updating worked,
 *    because 'c' and 'u' happened not to collide.
 *
 * The duplicate is invisible in review - the two lines sit forty apart and both
 * look correct - so the check is here rather than left to a reader.
 */
class PermissionMapTest extends TestCase
{
    /** @return array<string, string> */
    private function rawPairs(): array
    {
        $source = (string) file_get_contents(
            base_path('database/seeders/PermissionSeeder.php'),
        );

        $start = strpos($source, 'private function permissionMap()');
        $this->assertNotFalse($start, 'permissionMap() has moved or been renamed.');

        $body = substr($source, $start);
        $body = substr($body, 0, strpos($body, '])'));

        // The comments explaining this very fault quote the offending pairs, so
        // scanning the raw source would match the explanation rather than code.
        $body = preg_replace('!//.*!', '', $body) ?? $body;

        preg_match_all("/'([a-z])'\s*=>\s*'([a-z-]+)'/", $body, $matches, PREG_SET_ORDER);

        return $matches;
    }

    public function test_no_letter_is_used_twice(): void
    {
        $seen = [];
        $duplicates = [];

        foreach ($this->rawPairs() as [$_, $letter, $permission]) {
            if (isset($seen[$letter])) {
                $duplicates[] = "'{$letter}' is both '{$seen[$letter]}' and '{$permission}'";
            }

            $seen[$letter] = $permission;
        }

        $this->assertSame(
            [],
            $duplicates,
            "A repeated letter silently discards the earlier permission:\n- "
                . implode("\n- ", $duplicates),
        );
    }

    public function test_delete_still_resolves_to_delete(): void
    {
        $map = (new PermissionSeeder())->exposedPermissionMap();

        $this->assertSame(
            'delete',
            $map->get('d'),
            "Nearly every module lists 'd' for delete. If it resolves to anything "
                . 'else, no module has a delete permission at all.',
        );
    }

    public function test_the_core_verbs_are_intact(): void
    {
        $map = (new PermissionSeeder())->exposedPermissionMap();

        $this->assertSame('create', $map->get('c'));
        $this->assertSame('read', $map->get('r'));
        $this->assertSame('update', $map->get('u'));
    }

    /**
     * Every letter a module asks for has to exist, or the seeder creates a
     * permission named `<module>-` with nothing after it.
     */
    public function test_every_letter_a_module_uses_is_defined(): void
    {
        $source = (string) file_get_contents(
            base_path('database/seeders/PermissionSeeder.php'),
        );

        $map = (new PermissionSeeder())->exposedPermissionMap();

        // Only the module list, not the map itself: several of the map's own
        // values ('create', 'read') are made entirely of letters that appear in
        // the class below, so scanning the whole file reads them as letter-lists.
        $modules = substr($source, 0, strpos($source, 'private function permissionMap'));
        $modules = preg_replace('!//.*!', '', $modules) ?? $modules;

        preg_match_all("/'[a-z0-9-]+'\s*=>\s*'([rcudobstimangxkvyef]+(?:,[rcudobstimangxkvyef]+)*)',/", $modules, $matches);

        $unknown = [];
        foreach ($matches[1] as $letters) {
            foreach (explode(',', $letters) as $letter) {
                if ($map->get($letter) === null) {
                    $unknown[] = $letter;
                }
            }
        }

        $this->assertSame([], array_unique($unknown));
    }
}
