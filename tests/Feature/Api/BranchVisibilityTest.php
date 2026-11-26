<?php

namespace Tests\Feature;


use App\Models\Business;
use App\Models\User;
use App\Support\ActiveBranch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A record must be written to the branch it will be read back from.
 *
 * `BranchScope` filters on `active_branch_id` first and falls back to `branch_id`.
 * Every write site did the reverse - `branch_id ?? active_branch_id` - so for a
 * user carrying both, the row was stamped with the home branch and then looked for
 * in the active one. It saved successfully and was never seen again.
 *
 * That is why a new expense did not appear on the expense screen or in its report,
 * and the same inversion sat under income, sale and purchase returns, and due
 * collections.
 */
class BranchVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  'home'|'active'|null  ...$which  Which real branches to attach.
     */
    private function user(bool $home, bool $active): User
    {
        $business = Business::factory()->create();

        // Real rows: both columns carry a foreign key to `branches`. Inserted
        // directly because `Branch::creating` reads business_id off the
        // authenticated user, and there is none yet at this point.
        $make = fn (string $name) => \Illuminate\Support\Facades\DB::table('branches')->insertGetId([
            'business_id' => $business->id,
            'name' => $name,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->homeBranchId = $home ? $make('Home') : null;
        $this->activeBranchId = $active ? $make('Active') : null;

        return User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
            'branch_id' => $this->homeBranchId,
            'active_branch_id' => $this->activeBranchId,
        ]);
    }

    private ?int $homeBranchId = null;
    private ?int $activeBranchId = null;

    public function test_the_active_branch_wins_when_a_user_has_both(): void
    {
        // The case that was broken: writes went to the home branch, reads looked
        // in the active one.
        $user = $this->user(home: true, active: true);

        $this->assertSame($this->activeBranchId, ActiveBranch::forWrite($user));
        $this->assertNotSame($this->homeBranchId, ActiveBranch::forWrite($user));
    }

    public function test_the_home_branch_is_used_when_there_is_no_active_one(): void
    {
        $user = $this->user(home: true, active: false);

        $this->assertSame($this->homeBranchId, ActiveBranch::forWrite($user));
    }

    public function test_the_active_branch_is_used_when_there_is_no_home_one(): void
    {
        $user = $this->user(home: false, active: true);

        $this->assertSame($this->activeBranchId, ActiveBranch::forWrite($user));
    }

    public function test_a_user_with_no_branch_at_all_stamps_nothing(): void
    {
        $user = $this->user(home: false, active: false);

        $this->assertNull(ActiveBranch::forWrite($user));
    }

    public function test_it_resolves_the_authenticated_user_when_none_is_given(): void
    {
        $this->actingAs($this->user(home: true, active: true));

        $this->assertSame($this->activeBranchId, ActiveBranch::forWrite());
    }

    public function test_it_is_null_for_a_guest_rather_than_throwing(): void
    {
        $this->assertNull(ActiveBranch::forWrite());
    }

    /**
     * The precedence here and in the scope must not drift apart again.
     */
    public function test_no_write_site_still_uses_the_inverted_order(): void
    {
        $hits = [];

        foreach (['app', 'Modules'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir)),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (str_contains($source, 'branch_id ?? auth()->user()->active_branch_id')
                    || str_contains($source, 'branch_id ?? $user->active_branch_id')) {
                    $hits[] = str_replace(base_path() . '/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "These write the home branch but the scope reads the active one:\n- "
                . implode("\n- ", $hits)
                . "\nUse App\\Support\\ActiveBranch::forWrite() instead.",
        );
    }
}
