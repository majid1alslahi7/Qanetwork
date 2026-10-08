<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    protected $fillable = ['actor_id', 'event_type', 'subject_type', 'subject_id', 'before', 'after'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Audit events cannot be changed.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Audit events cannot be deleted.');
        });
    }
}
