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
        'data_access',
        'is_active', // tambahkan ini jika belum ada
        'metadata'   // tambahkan ini jika belum ada
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
            'metadata' => 'array',
            'is_active' => 'boolean',
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
        return [
            'role' => $this->role,
            'divisi' => $this->divisi,
            'data_access' => $this->data_access,
        ];
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

    /**
     * ==================== CRSD HELPER METHODS ====================
     */

    /**
     * Get effective data access based on role and divisi
     */
    public function getEffectiveDataAccess()
    {
        // Jika sudah ada data_access di database, gunakan itu
        if (!empty($this->data_access)) {
            return $this->data_access;
        }

        // Default berdasarkan role dan divisi
        if ($this->role === 'superadmin') {
            return ['crsd1', 'crsd2'];
        } elseif ($this->role === 'admin') {
            if ($this->divisi === 'CRSD 1') {
                return ['crsd1'];
            } elseif ($this->divisi === 'CRSD 2') {
                return ['crsd2'];
            } elseif ($this->divisi === 'BOTH' || $this->divisi === 'ALL') {
                return ['crsd1', 'crsd2'];
            }
        }

        // Untuk user biasa, tidak ada data_access
        return [];
    }

    /**
     * Check if user has access to specific CRSD
     */
    public function hasCRSDAccess($crsdType)
    {
        $dataAccess = $this->getEffectiveDataAccess();
        return in_array($crsdType, $dataAccess);
    }

    /**
     * Check if user has multiple CRSD access
     */
    public function hasMultipleCRSDAccess()
    {
        $dataAccess = $this->getEffectiveDataAccess();
        $crsdAccess = array_filter($dataAccess, function($item) {
            return in_array($item, ['crsd1', 'crsd2']);
        });
        return count($crsdAccess) > 1;
    }

    /**
     * Get user's primary CRSD if single access
     */
    public function getPrimaryCRSD()
    {
        $dataAccess = $this->getEffectiveDataAccess();
        $crsdAccess = array_filter($dataAccess, function($item) {
            return in_array($item, ['crsd1', 'crsd2']);
        });
        
        if (count($crsdAccess) === 1) {
            return reset($crsdAccess);
        }
        
        return null;
    }

    /**
     * Get user's divisi name for display
     */
    public function getDivisiNameAttribute()
    {
        if ($this->divisi === 'CRSD 1') {
            return 'CRSD 1';
        } elseif ($this->divisi === 'CRSD 2') {
            return 'CRSD 2';
        } elseif ($this->divisi === 'BOTH' || $this->divisi === 'ALL') {
            return 'CRSD 1 & CRSD 2';
        }
        
        return $this->divisi ?? 'Tidak ada divisi';
    }

    /**
     * Check if user is active admin
     */
    public function isActiveAdmin()
    {
        return $this->is_active && in_array($this->role, ['admin', 'superadmin']);
    }

    /**
     * Check if user can access admin panel
     */
    public function canAccessAdmin()
    {
        return in_array($this->role, ['admin', 'superadmin']);
    }

    /**
     * Get user's orders with CRSD filter
     */
    public function getFilteredOrders()
    {
        // Untuk admin, filter berdasarkan data_access
        if ($this->role === 'admin') {
            $dataAccess = $this->getEffectiveDataAccess();
            
            if (empty($dataAccess)) {
                return collect(); // No access
            }
            
            // Jika punya akses ke kedua CRSD
            if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                return $this->orders()->whereHas('user', function($q) {
                    $q->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                });
            }
            
            // Jika hanya akses ke satu CRSD
            $crsdTypes = array_filter($dataAccess, function($item) {
                return in_array($item, ['crsd1', 'crsd2']);
            });
            
            if (count($crsdTypes) === 1) {
                $crsdType = reset($crsdTypes);
                $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
                
                return $this->orders()->whereHas('user', function($q) use ($divisiName) {
                    $q->where('divisi', $divisiName);
                });
            }
        }
        
        // Untuk superadmin atau user biasa
        return $this->orders();
    }

    /**
     * Scope untuk user berdasarkan role
     */
    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope untuk user admin
     */
    public function scopeAdmins($query)
    {
        return $query->whereIn('role', ['admin', 'superadmin']);
    }

    /**
     * Scope untuk user biasa
     */
    public function scopeRegularUsers($query)
    {
        return $query->where('role', 'user');
    }

    /**
     * Scope untuk user berdasarkan divisi
     */
    public function scopeByDivisi($query, $divisi)
    {
        return $query->where('divisi', $divisi);
    }

    /**
     * Scope untuk user aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope untuk user dengan akses CRSD tertentu
     */
    public function scopeWithCRSDAccess($query, $crsdType)
    {
        return $query->where(function($q) use ($crsdType) {
            // Check data_access field
            $q->whereJsonContains('data_access', $crsdType)
              ->orWhere(function($q2) use ($crsdType) {
                  // Or check divisi for admins
                  $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
                  $q2->where('role', 'admin')
                     ->where('divisi', $divisiName);
              })
              ->orWhere('role', 'superadmin'); // Superadmin has access to all
        });
    }
}