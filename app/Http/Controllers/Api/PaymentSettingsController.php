<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PaymentSettingsController extends Controller
{
    // GET settings (superadmin only)
    public function getSettings()
    {
        // Cek apakah user adalah superadmin
        $user = Auth::user();
        
        if ($user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Superadmin access required.',
                'user_role' => $user->role
            ], 403);
        }

        try {
            $settings = PaymentSettings::getSettings();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $settings->id,
                    'qris_title' => $settings->qris_title,
                    'qris_image' => $settings->qris_image,
                    'qris_image_url' => $settings->qris_image_url,
                    'qris_active' => (bool) $settings->qris_active,
                    'bank_name' => $settings->bank_name,
                    'account_number' => $settings->account_number,
                    'account_name' => $settings->account_name,
                    'bank_active' => (bool) $settings->bank_active,
                    'active' => (bool) $settings->active,
                    'created_at' => $settings->created_at,
                    'updated_at' => $settings->updated_at,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getSettings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get payment settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // UPDATE settings (superadmin only) - Handle both POST & PUT
    public function update(Request $request)
    {
        // Cek apakah user adalah superadmin
        $user = Auth::user();
        if ($user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Superadmin access required.'
            ], 403);
        }

        // Validasi
        $validated = $request->validate([
            'qris_title' => 'nullable|string|max:100',
            'qris_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'qris_active' => 'nullable|boolean',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'account_name' => 'nullable|string|max:100',
            'bank_active' => 'nullable|boolean',
            'active' => 'nullable|boolean',
        ]);

        try {
            // Gunakan transaction untuk consistency
            DB::beginTransaction();
            
            $settings = PaymentSettings::getSettings();
            
            Log::info('Current settings before update:', [
                'settings' => $settings->toArray(),
                'request_data' => $request->all()
            ]);
            
            // Handle QRIS image upload jika ada
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
            
            // Update fields lainnya dengan nilai default jika null
            $updateData = [];
            
            // QRIS fields
            $updateData['qris_title'] = $request->has('qris_title') 
                ? $request->qris_title 
                : $settings->qris_title;
            
            $updateData['qris_active'] = $request->has('qris_active') 
                ? $request->boolean('qris_active') 
                : $settings->qris_active;
            
            // Bank transfer fields - PASTIKAN SEMUA FIELD BANK ADA
            $updateData['bank_name'] = $request->has('bank_name') 
                ? $request->bank_name 
                : ($settings->bank_name ?: ''); // Default empty string jika null
            
            $updateData['account_number'] = $request->has('account_number') 
                ? $request->account_number 
                : ($settings->account_number ?: ''); // Default empty string jika null
            
            $updateData['account_name'] = $request->has('account_name') 
                ? $request->account_name 
                : ($settings->account_name ?: ''); // Default empty string jika null
            
            $updateData['bank_active'] = $request->has('bank_active') 
                ? $request->boolean('bank_active') 
                : $settings->bank_active;
            
            // Global active
            $updateData['active'] = $request->has('active') 
                ? $request->boolean('active') 
                : $settings->active;
            
            Log::info('Update data prepared:', $updateData);
            
            // Update database
            $settings->update($updateData);
            
            // Commit transaction
            DB::commit();
            
            // Refresh dari database
            $settings->refresh();
            
            return response()->json([
                'success' => true,
                'message' => 'Pengaturan pembayaran berhasil diperbarui',
                'data' => [
                    'id' => $settings->id,
                    'qris_title' => $settings->qris_title,
                    'qris_image' => $settings->qris_image,
                    'qris_image_url' => $settings->qris_image_url,
                    'qris_active' => (bool) $settings->qris_active,
                    'bank_name' => $settings->bank_name,
                    'account_number' => $settings->account_number,
                    'account_name' => $settings->account_name,
                    'bank_active' => (bool) $settings->bank_active,
                    'active' => (bool) $settings->active,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in update payment settings: ' . $e->getMessage());
            Log::error('Error trace:', ['trace' => $e->getTraceAsString()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pengaturan pembayaran',
                'error' => $e->getMessage(),
                'error_details' => env('APP_DEBUG') ? [
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'trace' => $e->getTrace()
                ] : null
            ], 500);
        }
    }

    // DELETE QRIS image (superadmin only)
    public function deleteQrisImage()
    {
        // Cek apakah user adalah superadmin
        $user = Auth::user();
        if ($user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Superadmin access required.'
            ], 403);
        }

        try {
            $settings = PaymentSettings::getSettings();
            
            if ($settings->qris_image) {
                Storage::disk('public')->delete('payment/qris/' . $settings->qris_image);
                $settings->qris_image = null;
                $settings->save();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Gambar QRIS berhasil dihapus',
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Tidak ada gambar QRIS untuk dihapus',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error deleting QRIS image: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus gambar QRIS',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // GET payment methods for checkout page (PUBLIC - NO AUTH)
    public function getPaymentMethods()
    {
        try {
            $settings = PaymentSettings::getSettings();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'active' => (bool) $settings->active,
                    'methods' => $settings->getAvailableMethods(),
                    'qris_available' => $settings->qrisAvailable(),
                    'bank_transfer_available' => $settings->bankTransferAvailable(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getPaymentMethods: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get payment methods',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Upload QRIS image separately (superadmin only) - Form Data
    public function uploadQrisImage(Request $request)
    {
        // Cek apakah user adalah superadmin
        $user = Auth::user();
        if ($user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Superadmin access required.'
            ], 403);
        }

        // Validasi
        $request->validate([
            'qris_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        try {
            $settings = PaymentSettings::getSettings();
            $file = $request->file('qris_image');
            $filename = 'qris_' . time() . '.' . $file->getClientOriginalExtension();
            
            // Hapus file lama jika ada
            if ($settings->qris_image) {
                Storage::disk('public')->delete('payment/qris/' . $settings->qris_image);
            }
            
            // Simpan file baru
            $file->storeAs('payment/qris', $filename, 'public');
            
            // Update database
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
            
        } catch (\Exception $e) {
            Log::error('Error uploading QRIS image: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengunggah gambar QRIS',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}