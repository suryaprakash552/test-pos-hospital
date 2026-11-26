<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Expense extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'expense_category_id',
        'business_id',
        'branch_id',
        'user_id',
        'amount',
        'upi_amount',
        'card_amount',
        'expanseFor',
        'paymentType',
        'payment_type_id',
        'referenceNo',
        'note',
        'expenseDate',
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
            $model->branch_id = \App\Support\ActiveBranch::forWrite();
        });
    }

    /**
     * What an expense actually cost, across every tender.
     *
     * `amount` holds only the cash share; UPI and card sit in their own
     * columns. `->sum('amount')` therefore under-reports a bill paid any other
     * way — a card-only expense counted as zero. Use `Expense::query()->...
     * ->sum(Expense::tenderTotalExpression())` wherever a total is needed.
     */
    public static function tenderTotalExpression(): \Illuminate\Database\Query\Expression
    {
        return \Illuminate\Support\Facades\DB::raw(
            'COALESCE(amount, 0) + COALESCE(upi_amount, 0) + COALESCE(card_amount, 0)'
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function payment_type(): BelongsTo
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'upi_amount' => 'decimal:2',
        'card_amount' => 'decimal:2',
        'user_id' => 'integer',
        'business_id' => 'integer',
        'payment_type_id' => 'integer',
        'expense_category_id' => 'integer',
        'branch_id' => 'integer',
        'expenseDate' => 'date',
    ];
}
