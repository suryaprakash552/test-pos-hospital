<?php

namespace App\Support;

use App\Models\User;

/**
 * Which branch a new record belongs to.
 *
 * This has to agree with `App\Models\Scopes\BranchScope`, which is what decides
 * whether the record is ever seen again. The scope prefers `active_branch_id` and
 * falls back to `branch_id`; every write site did the opposite, preferring
 * `branch_id`.
 *
 * For anyone with only one of the two the orders agree, which is why this went
 * unnoticed. For a shop-owner who has a home branch and has switched into another
 * one, they disagree: the row is written to the home branch and read back from the
 * active one, so it saves successfully and is then invisible. That is the whole of
 * "the new expense does not appear", and the same fault sat under income, sale and
 * purchase returns, and due collections.
 */
final class ActiveBranch
{
    /**
     * The branch id to stamp on a new record, in the order the scope reads it.
     */
    public static function forWrite(?User $user = null): ?int
    {
        $user ??= auth()->user();

        if (! $user) {
            return null;
        }

        $branchId = $user->active_branch_id ?? $user->branch_id;

        return $branchId !== null ? (int) $branchId : null;
    }
}
