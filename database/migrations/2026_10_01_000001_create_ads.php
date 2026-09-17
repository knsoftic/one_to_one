<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ads (Y1): a free app supported by ads. Everything targeting-related is opt-in — a user only
 * gets personalised ads (and has an ad profile) after turning "Personalised ads" on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null = not decided yet (show the one-time choice), true/false = the user's choice.
            $table->boolean('ads_personalised')->nullable()->after('read_receipts');
            $table->timestamp('ads_consent_at')->nullable()->after('ads_personalised');
        });

        // One row per user who turned personalised ads on; deleted when they turn it off.
        Schema::create('ad_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('country', 2)->nullable();          // ISO 3166-1 alpha-2, from the phone code
            $table->string('region', 80)->nullable();        // coarse: the device time zone's area
            $table->string('timezone', 64)->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('platform', 16)->nullable();      // android | web | ios
            $table->string('os_version', 24)->nullable();
            $table->string('app_version', 24)->nullable();
            $table->boolean('location_allowed')->default(false);
            $table->string('coarse_location', 24)->nullable(); // "lat,lng" rounded to ~11 km, only with permission
            // Optional, entered by the user in the ads settings (blank unless they choose to share).
            $table->string('gender', 8)->nullable();         // male | female
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->json('interests')->nullable();           // broad ad segments from app activity
            $table->timestamp('updated_at')->nullable();
        });

        // House ads created in the admin panel.
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status', 16)->default('draft');  // draft | active | paused
            $table->string('title', 80);
            $table->string('body', 200)->nullable();
            $table->string('image_path')->nullable();
            $table->string('cta_label', 24)->default('Learn more');
            $table->string('target_url', 600);
            $table->string('sponsor', 60)->nullable();       // shown as "Sponsored · <sponsor>"
            // Targeting (all optional; empty = everyone). Interests/age/gender need personalised consent.
            $table->json('countries')->nullable();           // ISO2 list
            $table->json('interests')->nullable();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->string('gender', 8)->nullable();         // male | female
            $table->boolean('personalised_only')->default(false);
            $table->unsignedInteger('per_user_daily_cap')->default(3);
            $table->unsignedSmallInteger('weight')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        // How often a house ad was shown to a user on a day: frequency capping and per-ad de-dup.
        Schema::create('ad_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->boolean('clicked')->default(false);

            $table->unique(['campaign_id', 'user_id', 'day']);
            $table->index(['user_id', 'day']);
        });

        // Daily totals per campaign, for the admin charts (kept small).
        Schema::create('ad_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);

            $table->unique(['campaign_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_stats');
        Schema::dropIfExists('ad_views');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('ad_profiles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ads_personalised', 'ads_consent_at']);
        });
    }
};
