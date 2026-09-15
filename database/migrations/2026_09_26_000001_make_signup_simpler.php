<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simpler sign-up: the email is optional (name, mobile number and password are enough).
     * Integration settings (SMS, email, GIFs, calls) now live in app_settings, edited in the admin panel.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 191)->nullable(false)->change();
        });
    }
};
