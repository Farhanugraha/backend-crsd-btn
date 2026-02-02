<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'divisi',
        'unit_kerja',
        'email_verified_at', 
        'role',
        'data_access'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'data_access' => 'array', 
        ];
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Orders::class);
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Accessor untuk data_access dengan fallback ke array kosong
     */
    public function getDataAccessAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) 
                ? $decoded 
                : [];
        }
        
        return is_array($value) ? $value : [];
    }

    /**
     * Mutator untuk data_access untuk memastikan selalu array atau null
     */
    public function setDataAccessAttribute($value)
    {
        if (is_array($value) && !empty($value)) {
            $this->attributes['data_access'] = json_encode(array_values(array_unique($value)));
        } elseif ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->attributes['data_access'] = null;
        } else {
            $this->attributes['data_access'] = $value;
        }
    }
}