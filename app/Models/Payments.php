<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payments extends Model
{
     protected $fillable = ['order_id', 'payment_method', 'payment_status', 'transaction_id'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Orders::class);
    }
}
