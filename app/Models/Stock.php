<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Stock extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'branch_id',
        'warehouse_id',
        'product_id',
        'batch_no',
        'productStock',
        'productPurchasePrice',
        'profit_percent',
        'productSalePrice',
        'productWholeSalePrice',
        'productDealerPrice',
        'conversionValue',
        'mfg_date',
        'expire_date'
    ];

    /**
     * Sent with every serialized batch so clients never have to derive these.
     */
    protected $appends = ['available_quantity', 'sold_quantity'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseDetails()
    {
        return $this->hasMany(PurchaseDetails::class, 'stock_id', 'id');
    }

    /**
     * Purchase lots for this batch that still have quantity left.
     *
     * `purchase_details.conversionQuantity` is the *remaining* quantity of a lot, not
     * the quantity originally bought — AcnooSaleController draws it down FIFO as items
     * are sold. So a depleted lot sits at 0 and only the lots above 0 are sellable.
     */
    public function availableLots()
    {
        return $this->hasMany(PurchaseDetails::class, 'stock_id', 'id')
            ->where('conversionQuantity', '>', 0);
    }

    /**
     * Quantity of this batch that can actually be sold.
     *
     * This is the same figure AcnooSaleController computes before accepting a sale
     * (`$purchases->sum('conversionQuantity')`), exposed so clients stop deriving their
     * own. The app previously read one lot's remainder while displaying the sum of all
     * of them, so a batch whose oldest lot had been drawn down to zero reported "out of
     * stock" while visibly holding stock.
     *
     * Prefers a `withSum('availableLots as available_quantity', ...)` value when the
     * query supplied one, then an already-loaded relation, and only queries as a last
     * resort — so eager-loaded list endpoints stay free of N+1.
     */
    public function getAvailableQuantityAttribute(): float
    {
        if (array_key_exists('available_quantity', $this->attributes)) {
            return (float) ($this->attributes['available_quantity'] ?? 0);
        }

        if ($this->relationLoaded('availableLots')) {
            return (float) $this->availableLots->sum('conversionQuantity');
        }

        if ($this->relationLoaded('purchaseDetails')) {
            return (float) $this->purchaseDetails
                ->where('conversionQuantity', '>', 0)
                ->sum('conversionQuantity');
        }

        return (float) $this->availableLots()->sum('conversionQuantity');
    }

    /**
     * Sale lines drawn from this batch.
     */
    public function saleDetails()
    {
        return $this->hasMany(SaleDetails::class, 'stock_id', 'id');
    }

    /**
     * How much of this batch has already been sold.
     *
     * Read straight from sales history rather than inferred from what is left, so it
     * stays correct even if a lot's remainder is adjusted by some other path.
     *
     * This is the floor for editing a purchase: a batch cannot be reduced below the
     * quantity that has already left the shelf, because those sales happened.
     */
    public function getSoldQuantityAttribute(): float
    {
        if (array_key_exists('sold_quantity', $this->attributes)) {
            return (float) ($this->attributes['sold_quantity'] ?? 0);
        }

        if ($this->relationLoaded('saleDetails')) {
            return (float) $this->saleDetails->sum('quantities');
        }

        return (float) $this->saleDetails()->sum('quantities');
    }

    protected static function booted()
    {
        static::addGlobalScope(new BranchScope);

        if (auth()->check() && auth()->user()->accessToMultiBranch()) {
            static::addGlobalScope('withBranch', function ($builder) {
                $builder->with('branch:id,name');
            });
        }
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $user = auth()->user();
            $model->branch_id ??= $user?->branch_id ?? $user?->active_branch_id;
        });
    }

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'business_id' => 'integer',
        'branch_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'productStock' => 'double',
        'productPurchasePrice' => 'double',
        'profit_percent' => 'double',
        'productSalePrice' => 'double',
        'productWholeSalePrice' => 'double',
        'productDealerPrice' => 'double',
        'conversionValue' => 'double',
        'mfg_date' => 'date',
        'expire_date' => 'date',
    ];
}
