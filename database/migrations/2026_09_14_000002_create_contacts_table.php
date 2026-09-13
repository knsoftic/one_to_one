<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's saved contacts that are registered on the app (matched from
     * their phone book). Numbers of people who are not registered are never stored.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('contact_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);   // name as saved in the owner's phone book
            $table->string('phone', 20);   // number as saved (normalised)
            $table->timestamps();

            $table->unique(['user_id', 'contact_user_id']);
            $table->index('contact_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
