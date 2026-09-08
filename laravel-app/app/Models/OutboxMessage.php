<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['routing_key', 'payload', 'priority', 'status', 'published_at'])]
class OutboxMessage extends Model
{
    protected function casts(): array {
        return ['payload' => 'array', 'published_at' => 'datetime'];
    }
}
