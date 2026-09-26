<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderTransaction extends Model
{
    use HasFactory, HasUlids;

    /*
     * Provider transactions must be written through
     * the provider transaction service.
     */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
            'request_started_at' => 'datetime',
            'attempt_count' => 'integer',
            'reconciliation_attempt_count' => 'integer',
            'last_reconciliation_at' => 'datetime',
            'manual_review_required_at' => 'datetime',
            'request_started_at' => 'datetime',
            'response_received_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(
            NetworkConnection::class,
            'network_connection_id'
        );
    }
}
