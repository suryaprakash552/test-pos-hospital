<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Purchase extends Model
{
    use BelongsToBusiness, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'party_id',
        'business_id',
        'branch_id',
        'discountAmount',
        'discount_percent',
        'discount_type',
        'rowDiscount',
        'shipping_charge',
        'dueAmount',
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
        'totalAmount',
        'invoiceNumber',
        'reference_invoice_no',
        'vat_id',
        'vat_amount',
        'vat_percent',
        'isPaid',
        'paymentType',
        'payment_type_id',
        'purchaseDate',
        'change_amount',
    ];

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

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function vat(): BelongsTo
    {
        return $this->belongsTo(Vat::class);
    }

    public function details()
    {
        return $this->hasMany(PurchaseDetails::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function purchaseReturns()
    {
        return $this->hasMany(PurchaseReturn::class, 'purchase_id');
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
                $id = Purchase::where('business_id', $businessId)->count() + 1;
                $model->invoiceNumber = 'P' . str_pad($id, 2, '0', STR_PAD_LEFT);
            }

            $model->branch_id ??= $user?->branch_id ?? $user?->active_branch_id;
        });
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'party_id' => 'integer',
        'payment_type_id' => 'integer',
        'business_id' => 'integer',
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'vat_id' => 'integer',
        'isPaid' => 'boolean',
        // `tinyint(1)` without a cast is serialised as 0/1, and a client that
        // reads it as a boolean fails the type check outright. This one reached
        // the home screen: "type 'int' is not a subtype of type 'bool?'" in
        // place of the latest transactions.
        'is_inter_state' => 'boolean',
        'discountAmount' => 'double',
        'rowDiscount' => 'double',
        'dueAmount' => 'double',
        'paidAmount' => 'double',
        'cash_amount' => 'double',
        'upi_amount' => 'double',
        'card_amount' => 'double',
        'follow_up_date' => 'date',
        'change_amount' => 'double',
        'totalAmount' => 'double',
        'vat_amount' => 'double',
        'vat_percent' => 'double',
        'discount_percent' => 'double',
        'shipping_charge' => 'double',
    ];
}
