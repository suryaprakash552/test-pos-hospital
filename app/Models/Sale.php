<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sale extends Model
{
    use BelongsToBusiness, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        // §9: the loyalty terms as they stood at billing, copied onto the invoice
        // so a later edit to the package master cannot change a completed bill.
        'loyalty_subscription_id',
        'loyalty_package_name',
        'loyalty_eligible_amount',
        'loyalty_discount_amount',
        'loyalty_discount_type',
        'loyalty_discount_value',
        'loyalty_max_discount_amount',
        'loyalty_discount_mode',
        'loyalty_discount_by',
        'business_id',
        'customer_id',
        // Who this bill's medicine was for — see the migration note. Distinct
        // from the customer's own stored relationship, which does not change
        // per sale.
        'relationship',
        'doctor_id',
        'branch_id',
        'user_id',
        'discountAmount',
        'discount_percent',
        'discount_type',
        'rowDiscount',
        'shipping_charge',
        'dueAmount',
        'isPaid',
        'vat_amount',
        'vat_percent',
        'vat_id',
        'paidAmount',
        'cash_amount',
        'upi_amount',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'cess_amount',
        'is_inter_state',
        'gst_method',
        'place_of_supply',
        'upi_reference',
        'upi_confirmation_image',
        'upi_paid_at',
        'card_amount',
        'follow_up_date',
        'lossProfit',
        'totalAmount',
        'paymentType',
        'payment_type_id',
        'invoiceNumber',
        'saleDate',
        'image',
        'meta',
        'rounding_option',
        'rounding_amount',
        'actual_total_amount',
        'change_amount',
        'type',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function details()
{
    return $this->hasMany(SaleDetails::class, 'sale_id', 'id');
}

    /**
     * @deprecated Sales are keyed to customers - use customer().
     *
     * `sales.party_id` was renamed to `customer_id`, which left this relationship
     * pointing at a column that no longer exists, so every `$sale->party` silently
     * resolved to null and invoices/receipts rendered "Guest". Invoice, PDF and
     * receipt views still reference `$sale->party`, so it stays as an alias.
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function saleReturns()
    {
        return $this->hasMany(SaleReturn::class, 'sale_id');
    }

    public function vat(): BelongsTo
    {
        return $this->belongsTo(Vat::class);
    }

    public function payment_type(): BelongsTo
    {
        return $this->belongsTo(PaymentType::class);
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $user = auth()->user();
            $businessId = $model->business_id ?? $user?->business_id;

            if (blank($model->invoiceNumber) && $businessId) {
                $id = Sale::where('business_id', $businessId)->count() + 1;
                $model->invoiceNumber = 'S' . str_pad($id, 2, '0', STR_PAD_LEFT);
            }

            $model->branch_id ??= $user?->branch_id ?? $user?->active_branch_id;
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
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

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'business_id' => 'integer',
        'payment_type_id' => 'integer',
        'customer_id' => 'integer',
        'doctor_id' => 'integer',
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'vat_id' => 'integer',
        'discountAmount' => 'double',
        'dueAmount' => 'double',
        'rowDiscount' => 'double',
        'isPaid' => 'boolean',
        // `tinyint(1)` without a cast is serialised as 0/1, and a client that
        // reads it as a boolean fails the type check outright. This one reached
        // the home screen: "type 'int' is not a subtype of type 'bool?'" in
        // place of the latest transactions.
        'is_inter_state' => 'boolean',
        'vat_amount' => 'double',
        'vat_percent' => 'double',
        'paidAmount' => 'double',
        'cash_amount' => 'double',
        'upi_amount' => 'double',
        'card_amount' => 'double',
        'follow_up_date' => 'date',
        'change_amount' => 'double',
        'totalAmount' => 'double',
        'lossProfit' => 'double',
        'shipping_charge' => 'double',
        'rounding_amount' => 'double',
        'actual_total_amount' => 'double',
        'discount_percent' => 'double',
        'meta' => 'json',
    ];
}
