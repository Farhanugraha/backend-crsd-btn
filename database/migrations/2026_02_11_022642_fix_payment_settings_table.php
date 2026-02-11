<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('payment_settings', 'qris_tittle')) {
            Schema::table('payment_settings', function (Blueprint $table) {
 
                $table->renameColumn('qris_tittle', 'qris_title');
            });
        
            DB::statement("ALTER TABLE payment_settings MODIFY qris_title VARCHAR(255) DEFAULT 'QRIS Pembayaran'");
        }
        if (Schema::hasColumn('payment_settings', 'qris_active')) {
            DB::statement("UPDATE payment_settings SET qris_active = 
                CASE 
                    WHEN qris_active = '1' OR LOWER(qris_active) = 'true' OR LOWER(qris_active) = 'on' THEN 1
                    ELSE 0
                END");
            Schema::table('payment_settings', function (Blueprint $table) {
                $table->boolean('qris_active')->default(true)->change();
            });
        }
        if (Schema::hasColumn('payment_settings', 'bank_active')) {
            DB::statement("UPDATE payment_settings SET bank_active = 
                CASE 
                    WHEN bank_active = '1' OR LOWER(bank_active) = 'true' OR LOWER(bank_active) = 'on' THEN 1
                    ELSE 0
                END");
            
            // Ubah tipe data ke boolean
            Schema::table('payment_settings', function (Blueprint $table) {
                $table->boolean('bank_active')->default(true)->change();
            });
        }
        
        // 4. SET DEFAULT VALUES untuk semua kolom boolean
        DB::statement("ALTER TABLE payment_settings 
            MODIFY qris_active TINYINT(1) DEFAULT 1,
            MODIFY bank_active TINYINT(1) DEFAULT 1,
            MODIFY active TINYINT(1) DEFAULT 1");
        
        // 5. TAMBAHKAN DEFAULT VALUE untuk qris_title jika belum ada
        DB::statement("ALTER TABLE payment_settings 
            MODIFY qris_title VARCHAR(255) DEFAULT 'QRIS Pembayaran'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback: ubah kembali ke varchar untuk qris_active
        if (Schema::hasColumn('payment_settings', 'qris_active')) {
            Schema::table('payment_settings', function (Blueprint $table) {
                $table->string('qris_active')->default('1')->change();
            });
        }
        
        // Rollback: ubah kembali ke varchar untuk bank_active
        if (Schema::hasColumn('payment_settings', 'bank_active')) {
            Schema::table('payment_settings', function (Blueprint $table) {
                $table->string('bank_active')->default('1')->change();
            });
        }
        
        // Rollback: rename kembali ke qris_tittle
        if (Schema::hasColumn('payment_settings', 'qris_title')) {
            Schema::table('payment_settings', function (Blueprint $table) {
                $table->renameColumn('qris_title', 'qris_tittle');
            });
        }
    }
};