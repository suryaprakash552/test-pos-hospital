<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DueCollect extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'business_id',
        'party_id',
        'customer_id',
        'user_id',
        'branch_id',
        'sale_id',
        'purchase_id',
        'invoiceNumber',
        'totalDue',
        'dueAmountAfterPay',
        'payDueAmount',
        'cash_amount',
        'upi_amount',
        'card_amount',
        'paymentType',
        'payment_type_id',
        'paymentDate',
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

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $id = DueCollect::where('business_id', auth()->user()?->business_id ?? 1)->count() + 1;
            $model->invoiceNumber = "D" . str_pad($id, 2, '0', STR_PAD_LEFT);
            $model->branch_id = \App\Support\ActiveBranch::forWrite();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function payment_type(): BelongsTo
    {
        return $this->belongsTo(PaymentType::class);
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'business_id' => 'integer',
        'payment_type_id' => 'integer',
        'user_id' => 'integer',
        'party_id' => 'integer',
        'customer_id' => 'integer',
        'sale_id' => 'integer',
        'purchase_id' => 'integer',
        'branch_id' => 'integer',
        'payDueAmount' => 'double',
        'totalDue' => 'double',
        'dueAmountAfterPay' => 'double',
        'cash_amount' => 'double',
        'upi_amount' => 'double',
        'card_amount' => 'double',
    ];
}
