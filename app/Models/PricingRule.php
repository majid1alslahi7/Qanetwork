<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PricingRule extends Model
{
    use HasUlids;

    protected $fillable = ['network_product_id', 'face_value', 'provider_amount', 'currency_code', 'created_by'];

    protected function casts(): array
    {
        return ['face_value' => 'decimal:4', 'provider_amount' => 'decimal:4', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            if ($rule->isDirty(['network_product_id', 'face_value', 'provider_amount', 'currency_code', 'created_by'])) {
                throw new LogicException('Published pricing values are immutable; publish a new rule.');
            }
        });
        static::deleting(fn () => throw new LogicException('Published pricing rules cannot be deleted.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(NetworkProduct::class, 'network_product_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }
}
