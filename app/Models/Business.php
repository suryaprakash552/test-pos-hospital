<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\AffiliateAddon\App\Models\Affiliate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Business extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'plan_subscribe_id',
        'business_category_id',
        'companyName',
        'address',
        'phoneNumber',
        'upi_id',
        'upi_qr_image',
        'upi_qr_mode',
        'signature_image',
        'pictureUrl',
        'signature',
        'will_expire',
        'subscriptionDate',
        'remainingShopBalance',
        'shopOpeningBalance',
        'vat_name',
        'vat_no',
        'default_vat_type',
        'gst_effective_from',
        'books_closed_upto',
        'allow_gst_override',
        'place_of_supply',
        'gst_state',
        'gstin',
        'gst_registered',
        'affiliator_id',
        'email',
        'status'
    ];

    public function enrolled_plan()
    {
        return $this->belongsTo(PlanSubscribe::class, 'plan_subscribe_id');
    }

    public function category()
    {
        return $this->belongsTo(BusinessCategory::class, 'business_category_id');
    }

    public function user()
    {
        return $this->hasMany(User::class);
    }

    public function affiliator(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliator_id');
    }

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'plan_subscribe_id' => 'integer',
        'business_category_id' => 'integer',
        'gst_registered' => 'boolean',
        'allow_gst_override' => 'boolean',
        'gst_effective_from' => 'date',
        'books_closed_upto' => 'date',
        'remainingShopBalance' => 'double',
        'shopOpeningBalance' => 'double',
        'status' => 'integer',
    ];
}
