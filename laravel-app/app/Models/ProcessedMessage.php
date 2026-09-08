<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['message_id', 'consumer', 'processed_at'])]
class ProcessedMessage extends Model
{
    public $timestamps = false;
}
