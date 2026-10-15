<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OperationalMessage extends Model
{
    use HasUlids;

    protected $fillable = ['event_key', 'kind', 'recipient', 'body', 'status'];

    protected $hidden = ['recipient', 'body'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'body' => 'encrypted', 'started_at' => 'datetime'];
    }
}
