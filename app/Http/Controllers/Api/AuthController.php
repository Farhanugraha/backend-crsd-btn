<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Auth\Events\Registered;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:255|regex:/^[a-zA-Z\s]+$/',
            'email'      => 'required|email|max:255|unique:users',
            'password'   => [
                'required',
                'string',
                'min:6',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*?&]/',
            ],
            'phone'      => 'nullable|string|max:20|regex:/^[0-9\s\-+()]{7,20}$/',
            'divisi'     => 'nullable|string|max:255',
            'unit_kerja' => 'nullable|string|max:100',
        ], [
            'name.regex'          => 'Nama hanya boleh mengandung huruf dan spasi',
            'password.regex'      => 'Password harus mengandung huruf besar, huruf kecil, angka, dan simbol (@$!%*?&)',
            'password.confirmed'  => 'Konfirmasi password tidak cocok',
            'password.min'        => 'Password minimal 6 karakter',
            'email.unique'        => 'Email sudah terdaftar',
            'phone.regex'         => 'Nomor telepon tidak valid (7-20 digit)',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $user = User::create([
                'name'       => $request->name,
                'email'      => $request->email,
                'password'   => Hash::make($request->password),
                'phone'      => $request->phone,
                'divisi'     => $request->divisi,
                'unit_kerja' => $request->unit_kerja,
                'role'       => 'user',
            ]);

            event(new Registered($user));

            return response()->json([
                'success' => true,
                'message' => 'Registrasi berhasil. Silakan cek email untuk verifikasi.'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Register error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat registrasi',
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $credentials = $request->only('email', 'password');

            if (!$token = JWTAuth::attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email atau password salah'
                ], 401);
            }

            $user = JWTAuth::user();

            if (!$user->hasVerifiedEmail()) {
                JWTAuth::invalidate(JWTAuth::getToken());

                return response()->json([
                    'success' => false,
                    'message' => 'Silakan verifikasi email terlebih dahulu'
                ], 403);
            }

            return response()->json([
                'success'    => true,
                'token'      => $token,
                'token_type' => 'bearer',
                'expires_in' => (int) config('jwt.ttl') * 60,
                'user'       => $user
            ], 200);

        } catch (JWTException $e) {
            Log::error('Login JWT error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat token JWT',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat login',
            ], 500);
        }
    }

    public function logout()
    {
        try {
            $token = JWTAuth::getToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token tidak ditemukan'
                ], 401);
            }

            JWTAuth::invalidate($token);

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil'
            ], 200);

        } catch (JWTException $e) {
            Log::error('Logout JWT error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal logout',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat logout',
            ], 500);
        }
    }

    public function me()
    {
        try {
            $user = $this->getAuthenticatedUser();

            return response()->json([
                'success' => true,
                'user'    => $user
            ], 200);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token tidak valid atau expired',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Me error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan',
            ], 500);
        }
    }

    public function session()
    {
        try {
            $user = $this->getAuthenticatedUser();

            return response()->json([
                'success' => true,
                'data'    => [
                    'user'            => $user,
                    'isAuthenticated' => true,
                    'tokenValid'      => true
                ]
            ], 200);

        } catch (JWTException $e) {
            return response()->json([
                'success'         => false,
                'message'         => 'Token tidak valid atau expired',
                'isAuthenticated' => false
            ], 401);
        } catch (\Exception $e) {
            Log::error('Session error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan',
            ], 500);
        }
    }

    public function refresh()
    {
        try {
            $token = JWTAuth::getToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token tidak ditemukan'
                ], 401);
            }

            $newToken = JWTAuth::refresh($token);

            return response()->json([
                'success'    => true,
                'token'      => $newToken,
                'token_type' => 'bearer',
                'expires_in' => (int) config('jwt.ttl') * 60,
            ], 200);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal refresh token',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Refresh token error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat refresh token',
            ], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = $this->getAuthenticatedUser();

            $validator = Validator::make($request->all(), [
                'name'       => 'sometimes|string|max:255|regex:/^[a-zA-Z\s]+$/',
                'email'      => 'sometimes|email|max:255|unique:users,email,' . $user->id,
                'phone'      => 'nullable|string|max:20|regex:/^[0-9\s\-+()]{7,20}$/',
                'divisi'     => 'nullable|string|max:255',
                'unit_kerja' => 'nullable|string|max:100',
                'password'   => [
                    'sometimes',
                    'min:6',
                    'confirmed',
                    'regex:/[A-Z]/',
                    'regex:/[a-z]/',
                    'regex:/[0-9]/',
                    'regex:/[@$!%*?&]/',
                ],
            ], [
                'name.regex'     => 'Nama hanya boleh mengandung huruf dan spasi',
                'password.regex' => 'Password harus mengandung huruf besar, huruf kecil, angka, dan simbol (@$!%*?&)',
                'phone.regex'    => 'Nomor telepon tidak valid (7-20 digit)',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors'  => $validator->errors()
                ], 422);
            }

            $dataToUpdate = $request->only([
                'name', 'phone', 'divisi', 'unit_kerja'
            ]);

            // Jika email berubah, reset verifikasi dan kirim ulang email
            if ($request->filled('email') && $request->email !== $user->email) {
                $dataToUpdate['email']              = $request->email;
                $dataToUpdate['email_verified_at']  = null;
                $user->update($dataToUpdate);
                event(new Registered($user->fresh()));

                return response()->json([
                    'success' => true,
                    'message' => 'Profil berhasil diperbarui. Silakan verifikasi email baru Anda.',
                    'user'    => $user->fresh()
                ], 200);
            }

            if ($request->filled('password')) {
                $dataToUpdate['password'] = Hash::make($request->password);
            }

            $user->update($dataToUpdate);

            return response()->json([
                'success' => true,
                'message' => 'Profil berhasil diperbarui',
                'user'    => $user->fresh()
            ], 200);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token tidak valid atau expired',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Update profile error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat update profil',
            ], 500);
        }
    }

    private function getAuthenticatedUser(): User
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            throw new JWTException('User tidak ditemukan');
        }

        return $user;
    }
}