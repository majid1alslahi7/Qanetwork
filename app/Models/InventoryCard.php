<?php

namespace App\Models;

use Database\Factories\InventoryCardFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryCard extends Model
{
    /** @use HasFactory<InventoryCardFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['network_id', 'network_product_id', 'fingerprint', 'expires_at'];

    protected $hidden = ['credentials_encrypted', 'fingerprint'];

    protected function casts(): array
    {
        return ['credentials_encrypted' => 'encrypted:array', 'allocated_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
