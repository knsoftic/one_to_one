<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * X8 — business tools: business profile with hours, away and greeting messages,
 * quick replies, and colours on chat lists so they work as labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('description', 512)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('website', 255)->nullable();
            // {mode: always|appointment|custom, days: {mon: {open, from, to}, …}}
            $table->json('hours')->nullable();

            $table->boolean('away_enabled')->default(false);
            $table->string('away_message', 1000)->nullable();
            $table->string('away_schedule', 20)->default('always'); // always | outside_hours | custom
            $table->timestamp('away_from')->nullable();
            $table->timestamp('away_until')->nullable();
            $table->string('away_recipients', 20)->default('everyone'); // everyone | not_contacts

            $table->boolean('greeting_enabled')->default(false);
            $table->string('greeting_message', 1000)->nullable();
            $table->string('greeting_recipients', 20)->default('everyone');

            $table->timestamps();
        });

        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('shortcut', 24);
            $table->string('message', 1000);
            $table->timestamps();

            $table->unique(['user_id', 'shortcut']);
        });

        Schema::table('chat_lists', function (Blueprint $table) {
            $table->string('color', 10)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('chat_lists', function (Blueprint $table) {
            $table->dropColumn('color');
        });
        Schema::dropIfExists('quick_replies');
        Schema::dropIfExists('business_profiles');
    }
};
