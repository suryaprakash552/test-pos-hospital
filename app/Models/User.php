<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Modules\AffiliateAddon\App\Models\Affiliate;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'business_id',
        'branch_id',
        'active_branch_id',
        'name',
        'role',
        'email',
        'phone',
        'image',
        'lang',
        'password',
        'visibility',
        'provider',
        'provider_id',
        'is_verified',
        'remember_token',
        'email_verified_at',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */

    protected $casts = [
        'password' => 'hashed',
        'visibility' => 'json',
        'email_verified_at' => 'datetime',
        'business_id' => 'integer',
        'is_verified' => 'integer',
        'branch_id' => 'integer',
        'active_branch_id' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function active_branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, $this->branch_id ? 'branch_id' : 'active_branch_id')->withTrashed();
    }

    public function affiliate(): HasOne
    {
        return $this->hasOne(Affiliate::class);
    }

    /**
     * Split a permission key into [module, action].
     *
     * Keys are written both as "products.create" and "products-create"
     * across the app, so accept either separator. Returns null for keys
     * that carry no action segment rather than destructuring to null.
     *
     * Split at the *last* separator, not the first. Module names contain hyphens —
     * "vat-reports", "sale-return-reports", "transaction-history-reports" — and
     * splitting at the first one turned "vat-reports.read" into module "vat" with
     * action "reports.read", which matches nothing the app ever writes. The effect
     * was that ticking a hyphenated module in the roles screen granted nothing, and
     * only the owner (who short-circuits above) could reach it.
     *
     * A dot wins over a hyphen when both are present, because that is the form the
     * app sends: "<module-with-hyphens>.<action>".
     */
    protected function splitPermission(string $permission): ?array
    {
        foreach (['.', '-'] as $separator) {
            $at = strrpos($permission, $separator);

            if ($at !== false && $at > 0 && $at < strlen($permission) - 1) {
                return [substr($permission, 0, $at), substr($permission, $at + 1)];
            }
        }

        return null;
    }

    public function hasPermission(string $permission): bool
    {
        if (in_array($this->role, ['shop-owner', 'owner'], true)) return true;

        if (! $segments = $this->splitPermission($permission)) {
            return false;
        }

        [$module, $action] = $segments;

        return isset($this->visibility[$module][$action]) && $this->visibility[$module][$action] == "1";
    }

    public function hasAnyPermission(array $permissions)
    {
        if (in_array($this->role, ['shop-owner', 'owner'], true)) return true;

        $visibility = $this->visibility ?? [];
        foreach ($permissions as $permission) {
            if (! $segments = $this->splitPermission($permission)) {
                continue;
            }

            [$module, $action] = $segments;

            if (!empty($visibility[$module][$action]) && $visibility[$module][$action] == "1") {
                return true;
            }
        }

        return false;
    }

    public function accessToMultiBranch()
    {
        return moduleCheck('MultiBranchAddon') && !$this->branch_id && !$this->active_branch_id && multibranch_active() && branch_count();
    }
}
