<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'event_type', 'payload', 'occurred_at'])]
class OrderEvent extends Model
{
    public $timestamps = false;
    protected function casts(): array {
        return ['payload' => 'array'];
    }
}
