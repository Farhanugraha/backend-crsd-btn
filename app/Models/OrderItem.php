<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $table = 'order_items';
    protected $fillable = ['order_id', 'menu_id', 'quantity', 'price','notes', 'is_checked'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Orders::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }
}
