<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename payment_notes ke notes
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('payment_notes', 'notes');
        });
    }

    /**
     * Reverse rename
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('notes', 'payment_notes');
        });
    }
};