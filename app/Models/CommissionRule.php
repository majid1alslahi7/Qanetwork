<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CommissionRule extends Model
{
    use HasUlids;

    protected $fillable = ['pricing_rule_id', 'seller_id', 'seller_commission', 'created_by'];

    protected function casts(): array
    {
        return ['seller_commission' => 'decimal:4', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            if ($rule->isDirty(['pricing_rule_id', 'seller_id', 'seller_commission', 'created_by'])) {
                throw new LogicException('Published commission values are immutable; publish a new rule.');
            }
        });
        static::deleting(fn () => throw new LogicException('Published commission rules cannot be deleted.'));
    }

    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
