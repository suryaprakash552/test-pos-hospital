<?php

namespace App\Rules;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * An `exists` check confined to the authenticated tenant.
 *
 * A bare `exists:categories,id` accepts any id in the table, including another
 * shop's. That let a product be filed under a category belonging to someone else -
 * which corrupts that shop's reporting - and turned the endpoint into an
 * enumeration oracle, because a valid id from another tenant validated while a
 * made-up one did not.
 *
 * Use in place of the string rule:
 *
 *     'category_id' => ['nullable', 'integer', TenantOwned::in('categories')],
 */
final class TenantOwned
{
    /**
     * Rows of the given table belonging to the current business.
     *
     * @param  string  $table
     * @param  string  $column  The key being matched, almost always `id`.
     */
    public static function in(string $table, string $column = 'id'): Exists
    {
        $businessId = auth()->user()?->business_id;

        return Rule::exists($table, $column)->where(
            fn ($query) => $query->where('business_id', $businessId)
        );
    }
}
