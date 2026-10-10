<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerDeposit extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'seller_id',
        'seller_wallet_id',
        'reference_no',
        'amount',
        'currency_code',
        'payment_method',
        'external_reference',
        'receipt_path',
        'seller_notes',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(
            SellerWallet::class,
            'seller_wallet_id'
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by'
        );
    }
}
