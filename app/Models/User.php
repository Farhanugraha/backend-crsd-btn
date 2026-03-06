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
    use HasFactory, Notifiable;

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
        'is_active',
        'metadata',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'data_access'       => 'array',
            'metadata'          => 'array',
            'is_active'         => 'boolean',
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

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role'        => $this->role,
            'divisi'      => $this->divisi,
            'data_access' => $this->data_access,
        ];
    }

    /**
     * Dikosongkan — verifikasi email ditangani via
     * App\Listeners\SendEmailVerificationNotification
     */
    public function sendEmailVerificationNotification(): void
    {
        //
    }

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

    public function getEffectiveDataAccess()
    {
        if (!empty($this->data_access)) {
            return $this->data_access;
        }

        if ($this->role === 'superadmin') {
            return ['crsd1', 'crsd2'];
        }

        if ($this->role === 'admin') {
            if ($this->divisi === 'CRSD 1') return ['crsd1'];
            if ($this->divisi === 'CRSD 2') return ['crsd2'];
            if (in_array($this->divisi, ['BOTH', 'ALL'])) return ['crsd1', 'crsd2'];
        }

        return [];
    }

    public function hasCRSDAccess($crsdType): bool
    {
        return in_array($crsdType, $this->getEffectiveDataAccess());
    }

    public function hasMultipleCRSDAccess(): bool
    {
        $crsdAccess = array_filter($this->getEffectiveDataAccess(), fn($item) => in_array($item, ['crsd1', 'crsd2']));
        return count($crsdAccess) > 1;
    }

    public function getPrimaryCRSD(): ?string
    {
        $crsdAccess = array_filter($this->getEffectiveDataAccess(), fn($item) => in_array($item, ['crsd1', 'crsd2']));
        return count($crsdAccess) === 1 ? reset($crsdAccess) : null;
    }

    public function getDivisiNameAttribute(): string
    {
        return match($this->divisi) {
            'CRSD 1'         => 'CRSD 1',
            'CRSD 2'         => 'CRSD 2',
            'BOTH', 'ALL'    => 'CRSD 1 & CRSD 2',
            default          => $this->divisi ?? 'Tidak ada divisi',
        };
    }

    public function isActiveAdmin(): bool
    {
        return $this->is_active && in_array($this->role, ['admin', 'superadmin']);
    }

    public function canAccessAdmin(): bool
    {
        return in_array($this->role, ['admin', 'superadmin']);
    }

    public function getFilteredOrders()
    {
        if ($this->role === 'admin') {
            $dataAccess = $this->getEffectiveDataAccess();

            if (empty($dataAccess)) {
                return collect();
            }

            $hasCrsd1 = in_array('crsd1', $dataAccess);
            $hasCrsd2 = in_array('crsd2', $dataAccess);

            if ($hasCrsd1 && $hasCrsd2) {
                return $this->orders()->whereHas('user', fn($q) => $q->whereIn('divisi', ['CRSD 1', 'CRSD 2']));
            }

            $divisiName = $hasCrsd1 ? 'CRSD 1' : 'CRSD 2';
            return $this->orders()->whereHas('user', fn($q) => $q->where('divisi', $divisiName));
        }

        return $this->orders();
    }

    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function scopeAdmins($query)
    {
        return $query->whereIn('role', ['admin', 'superadmin']);
    }

    public function scopeRegularUsers($query)
    {
        return $query->where('role', 'user');
    }

    public function scopeByDivisi($query, $divisi)
    {
        return $query->where('divisi', $divisi);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithCRSDAccess($query, $crsdType)
    {
        return $query->where(function ($q) use ($crsdType) {
            $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
            $q->whereJsonContains('data_access', $crsdType)
              ->orWhere(fn($q2) => $q2->where('role', 'admin')->where('divisi', $divisiName))
              ->orWhere('role', 'superadmin');
        });
    }
}