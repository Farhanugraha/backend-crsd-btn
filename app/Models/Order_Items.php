<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order_Items extends Model
{
    protected $fillable = ['order_id', 'menu_id', 'quantity', 'price'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Orders::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }
}
