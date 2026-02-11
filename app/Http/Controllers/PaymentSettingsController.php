<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class PaymentSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // GET settings (superadmin only)
    public function getSettings()
    {
        // Cek apakah user adalah superadmin menggunakan Auth facade
        $user = Auth::user();
        if ($user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Superadmin access required.'
            ], 403);
        }

        $settings = PaymentSettings::getSettings();
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $settings->id,
                'qris_title' => $settings->qris_title,
                'qris_image' => $settings->qris_image,
                'qris_image_url' => $settings->qris_image_url,
                'qris_active' => $settings->qris_active,
                'bank_name' => $settings->bank_name,
                'account_number' => $settings->account_number,
                'account_name' => $settings->account_name,
                'bank_active' => $settings->bank_active,
                'active' => $settings->active,
                'created_at' => $settings->created_at,
                'updated_at' => $settings->updated_at,
            ],
        ]);
    }

    // UPDATE settings (superadmin only)
    public function update(Request $request)
    {
        // Cek apakah user adalah superadmin menggunakan Auth facade
        $user = Auth::user();
        if ($user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Superadmin access required.'
            ], 403);
        }

        $request->validate([
            'qris_title' => 'nullable|string|max:100',
            'qris_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'qris_active' => 'boolean',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'account_name' => 'nullable|string|max:100',
            'bank_active' => 'boolean',
            'active' => 'boolean',
        ]);
        
        $settings = PaymentSettings::getSettings();
        
        // Handle QRIS image upload
        if ($request->hasFile('qris_image')) {
            $file = $request->file('qris_image');
            $filename = 'qris_' . time() . '.' . $file->getClientOriginalExtension();
            
            // Hapus file lama jika ada
            if ($settings->qris_image) {
                Storage::disk('public')->delete('payment/qris/' . $settings->qris_image);
            }
            
            // Simpan file baru
            $file->storeAs('payment/qris', $filename, 'public');
            $settings->qris_image = $filename;
        }
        
        // Update other fields
        $settings->qris_title = $request->input('qris_title', $settings->qris_title);
        $settings->qris_active = $request->boolean('qris_active', $settings->qris_active);
        $settings->bank_name = $request->input('bank_name', $settings->bank_name);
        $settings->account_number = $request->input('account_number', $settings->account_number);
        $settings->account_name = $request->input('account_name', $settings->account_name);
        $settings->bank_active = $request->boolean('bank_active', $settings->bank_active);
        $settings->active = $request->boolean('active', $settings->active);
        
        $settings->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Pengaturan pembayaran berhasil diperbarui',
            'data' => $settings,
        ]);
    }

    // DELETE QRIS image (superadmin only)
    public function deleteQrisImage()
    {
        // Cek apakah user adalah superadmin menggunakan Auth facade
        $user = Auth::user();
        if ($user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Superadmin access required.'
            ], 403);
        }

        $settings = PaymentSettings::getSettings();
        
        if ($settings->qris_image) {
            Storage::disk('public')->delete('payment/qris/' . $settings->qris_image);
            $settings->qris_image = null;
            $settings->save();
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Gambar QRIS berhasil dihapus',
        ]);
    }

    // GET payment methods for checkout page (public)
    public function getPaymentMethods()
    {
        $settings = PaymentSettings::getSettings();
        
        return response()->json([
            'success' => true,
            'data' => [
                'active' => $settings->active,
                'methods' => $settings->getAvailableMethods(),
            ],
        ]);
    }

    // Upload QRIS image separately (superadmin only)
    public function uploadQrisImage(Request $request)
    {
        // Cek apakah user adalah superadmin menggunakan Auth facade
        $user = Auth::user();
        if ($user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Superadmin access required.'
            ], 403);
        }

        $request->validate([
            'qris_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);
        
        $settings = PaymentSettings::getSettings();
        $file = $request->file('qris_image');
        $filename = 'qris_' . time() . '.' . $file->getClientOriginalExtension();
        
        // Hapus file lama jika ada
        if ($settings->qris_image) {
            Storage::disk('public')->delete('payment/qris/' . $settings->qris_image);
        }
        
        // Simpan file baru
        $file->storeAs('payment/qris', $filename, 'public');
        $settings->qris_image = $filename;
        $settings->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Gambar QRIS berhasil diunggah',
            'data' => [
                'filename' => $filename,
                'image_url' => $settings->qris_image_url,
            ],
        ]);
    }
}