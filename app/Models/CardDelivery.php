<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CardDelivery extends Model
{
    use HasUlids;

    protected $fillable = ['sale_id', 'requested_by', 'idempotency_key', 'channel', 'recipient_hint'];

    protected $hidden = ['recipient_encrypted', 'idempotency_key'];

    protected function casts(): array
    {
        return ['recipient_encrypted' => 'encrypted', 'attempt_count' => 'integer',
            'started_at' => 'datetime', 'submitted_at' => 'datetime', 'next_retry_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $delivery): void {
            if ($delivery->isDirty(['sale_id', 'requested_by', 'idempotency_key', 'channel', 'recipient_encrypted', 'recipient_hint'])) {
                throw new LogicException('Delivery identity and recipient cannot be changed.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Card deliveries cannot be deleted.');
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
