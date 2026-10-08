<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkConnection extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'network_id',
        'name',
        'driver',
        'base_url',
        'config',
        'external_account_id',
        'is_primary',
        'is_enabled',
        'connect_timeout',
        'request_timeout',
    ];

    protected $hidden = [
        'credentials_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',

            /*
             * API credentials are encrypted using APP_KEY.
             * They must never be stored as plaintext.
             */
            'credentials_encrypted' => 'encrypted:array',

            'is_primary' => 'boolean',
            'is_enabled' => 'boolean',
            'consecutive_failures' => 'integer',
            'connect_timeout' => 'integer',
            'request_timeout' => 'integer',

            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function providerTransactions(): HasMany
    {
        return $this->hasMany(
            ProviderTransaction::class,
            'network_connection_id'
        );
    }

    public function setCredentials(array $credentials): void
    {
        $this->credentials_encrypted = $credentials;
    }

    public function credentials(): array
    {
        return $this->credentials_encrypted ?? [];
    }
}
