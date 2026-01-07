<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Area extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'order'
    ];

    protected $casts = [
        'order' => 'integer'
    ];

    /**
     * Relationship: 1 Area has many Restaurants
     */
    public function restaurants()
    {
        return $this->hasMany(Restaurant::class);
    }

    /**
     * Auto generate slug from name
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($area) {
            if (empty($area->slug)) {
                $area->slug = Str::slug($area->name);
            }
        });

        static::updating(function ($area) {
            if ($area->isDirty('name') && empty($area->slug)) {
                $area->slug = Str::slug($area->name);
            }
        });
    }
}