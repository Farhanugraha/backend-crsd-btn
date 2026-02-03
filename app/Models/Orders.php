<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Orders extends Model
{
    protected $table = 'orders';
    
    protected $fillable = [
        'user_id',
        'order_code', 
        'restaurant_id', 
        'total_price', 
        'status', 
        'order_status', 
        'notes',
        'created_at',
        'updated_at'
    ];

    // Casting untuk tipe data
    protected $casts = [
        'total_price' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payments::class, 'order_id', 'id');
    }
    
    /**
     * Relationship dengan Areas (jika ada pivot table order_areas)
     */
    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(
            Area::class, 
            'order_areas', // nama pivot table
            'order_id',    // foreign key di pivot table untuk order
            'area_id'      // foreign key di pivot table untuk area
        )->withTimestamps();
    }

    /**
     * Scope untuk filter berdasarkan status order
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('order_status', $status);
    }

    /**
     * Scope untuk filter berdasarkan status pembayaran
     */
    public function scopeByPaymentStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope untuk filter berdasarkan tanggal
     */
    public function scopeByDate($query, $date)
    {
        return $query->whereDate('created_at', $date);
    }

    /**
     * Scope untuk filter berdasarkan user divisi (CRSD)
     */
    public function scopeByUserDivisi($query, $divisi)
    {
        return $query->whereHas('user', function($q) use ($divisi) {
            $q->where('divisi', $divisi);
        });
    }

    /**
     * Accessor untuk mendapatkan CRSD type dari user divisi
     */
    public function getCrsdTypeAttribute()
    {
        if (!$this->user) {
            return 'crsd1'; // default
        }
        
        return $this->user->divisi === 'CRSD 2' ? 'crsd2' : 'crsd1';
    }

    /**
     * Format total price dengan rupiah
     */
    public function getFormattedTotalPriceAttribute()
    {
        return 'Rp ' . number_format($this->total_price, 0, ',', '.');
    }

    /**
     * Format tanggal order
     */
    public function getFormattedDateAttribute()
    {
        return $this->created_at->format('d M Y H:i');
    }
}