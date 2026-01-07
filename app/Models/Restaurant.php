<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Restaurant extends Model
{
    use HasFactory;

    protected $fillable = [
        'area_id',
        'name',
        'description',
        'address',
        'is_open'
    ];

    protected $casts = [
        'is_open' => 'boolean'
    ];

    /**
     * Relationship: Restaurant belongs to Area
     */
    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * Relationship: Restaurant has many Menus
     */
    public function menus()
    {
        return $this->hasMany(Menu::class);
    }

    /**
     * Relationship: Restaurant has many Carts
     */
    public function carts()
    {
        return $this->hasMany(Cart::class);
    }
}