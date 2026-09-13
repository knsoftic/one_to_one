<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many times a message travelled by forwarding: 0 = original,
     * 1–4 = "Forwarded", 5+ = "Forwarded many times" (like WhatsApp).
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedSmallInteger('forward_count')->default(0)->after('reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('forward_count');
        });
    }
};
