<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PaymentSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'qris_title',
        'qris_image',
        'qris_active',
        'bank_name',
        'account_number',
        'account_name',
        'bank_active',
        'active',
    ];

    protected $casts = [
        'qris_active' => 'boolean',
        'bank_active' => 'boolean',
        'active' => 'boolean',
    ];

    // Singleton - hanya 1 record untuk settings
    public static function getSettings()
    {
        return self::firstOrCreate([], [
            'qris_title' => 'QRIS Pembayaran',
            'qris_active' => true,
            'bank_active' => true,
            'active' => true,
        ]);
    }

    // Accessor untuk QRIS URL
    public function getQrisImageUrlAttribute()
    {
        if (!$this->qris_image) {
            return null;
        }
        
        return asset('storage/payment/qris/' . $this->qris_image);
    }

    // Method untuk upload QRIS image
    public function uploadQrisImage(UploadedFile $file)
    {
        // Hapus file lama jika ada
        $this->deleteQrisImage();
        
        // Generate nama file unik
        $filename = 'qris_' . time() . '.' . $file->getClientOriginalExtension();
        
        // Simpan file
        $file->storeAs('payment/qris', $filename, 'public');
        
        // Update database
        $this->update(['qris_image' => $filename]);
        
        return $filename;
    }

    // Method untuk hapus QRIS image
    public function deleteQrisImage()
    {
        if ($this->qris_image) {
            Storage::disk('public')->delete('payment/qris/' . $this->qris_image);
            $this->update(['qris_image' => null]);
        }
        
        return true;
    }

    // Helper untuk cek apakah metode tersedia
    public function qrisAvailable()
    {
        return $this->active && $this->qris_active && $this->qris_image;
    }

    public function bankTransferAvailable()
    {
        return $this->active && $this->bank_active && 
               $this->bank_name && $this->account_number && $this->account_name;
    }

    // Get available payment methods for checkout
    public function getAvailableMethods()
    {
        $methods = [];
        
        if ($this->qrisAvailable()) {
            $methods['qris'] = [
                'id' => 'qris',
                'type' => 'qris',
                'title' => $this->qris_title,
                'image_url' => $this->qris_image_url,
            ];
        }
        
        if ($this->bankTransferAvailable()) {
            $methods['bank_transfer'] = [
                'id' => 'bank_transfer',
                'type' => 'bank_transfer',
                'bank_name' => $this->bank_name,
                'account_number' => $this->account_number,
                'account_name' => $this->account_name,
            ];
        }
        
        return $methods;
    }
}