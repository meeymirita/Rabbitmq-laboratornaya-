<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'status', 'priority'])]
class Order extends Model
{
    public function items() {
        return $this->hasMany(OrderItem::class);
    }
}
