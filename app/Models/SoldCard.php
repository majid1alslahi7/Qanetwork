<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoldCard extends Model
{
    use HasFactory, HasUlids;

    /*
     * credentials_encrypted is intentionally NOT fillable
     * from HTTP request data.
     */
    protected $fillable = [
        'sale_id',
        'provider_card_reference',
        'sold_at',
    ];

    /*
     * Never expose encrypted card credentials during
     * normal model serialization.
     */
    protected $hidden = [
        'credentials_encrypted',
    ];

    protected function casts(): array
    {
        return [
            /*
             * Laravel encrypts the complete array using APP_KEY
             * before writing it to the database.
             */
            'credentials_encrypted' => 'encrypted:array',

            'sold_at' => 'datetime',
            'first_revealed_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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
