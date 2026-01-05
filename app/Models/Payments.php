<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payments extends Model
{
    protected $table = 'payments';
    
    protected $fillable = [
        'order_id', 
        'payment_method', 
        'payment_status', 
        'transaction_id',
        'proof_image',      
        'payment_notes',    
        'paid_at'           
    ];

    protected $casts = [
        'paid_at' => 'datetime'
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Orders::class);
    }
}