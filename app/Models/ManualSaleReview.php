<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ManualSaleReview extends Model
{
    use HasUlids;

    protected $fillable = ['sale_id', 'requested_by', 'idempotency_key', 'reason', 'sale_status_before'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected static function booted(): void
    {
        static::updating(function (self $review): void {
            if ($review->isDirty(['sale_id', 'requested_by', 'idempotency_key', 'reason', 'sale_status_before'])) {
                throw new LogicException('Manual review requests cannot be changed after submission.');
            }
        });
        static::deleting(fn () => throw new LogicException('Manual review records cannot be deleted.'));
    }
}
