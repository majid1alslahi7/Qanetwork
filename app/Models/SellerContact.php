<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerContact extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'phone', 'email', 'address', 'notes', 'is_favorite'];

    protected $attributes = ['is_favorite' => false];

    protected function casts(): array
    {
        return ['is_favorite' => 'boolean'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
