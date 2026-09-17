<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ads (Y1), second pass: ads now show to everyone (the admin decides, not the user), the data we
 * use comes from the phone's own permissions like contacts and camera do, gender/age move to the
 * user's own profile, and every ad is booked against named placements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Ads are no longer something the user switches on or off.
            $this->drop($table, 'users', ['ads_personalised', 'ads_consent_at']);
            // Profile details (Settings → Profile), also used to choose ads.
            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 10)->nullable()->after('about');
            }
            if (! Schema::hasColumn('users', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('about');
            }
        });

        Schema::table('ad_profiles', function (Blueprint $table) {
            $this->drop($table, 'ad_profiles', ['gender', 'birth_year']);
            $this->rename($table, 'ad_profiles', 'interests', 'segments');
            $this->add($table, 'ad_profiles', 'city', fn () => $table->string('city', 64)->nullable()->after('region'));
            $this->add($table, 'ad_profiles', 'device_model', fn () => $table->string('device_model', 64)->nullable()->after('os_version'));
            $this->add($table, 'ad_profiles', 'ip', fn () => $table->string('ip', 45)->nullable()->after('app_version'));
            $this->add($table, 'ad_profiles', 'ip_country', fn () => $table->char('ip_country', 2)->nullable()->after('ip'));
            $this->add($table, 'ad_profiles', 'location_at', fn () => $table->timestamp('location_at')->nullable()->after('coarse_location'));
            $this->add($table, 'ad_profiles', 'opens', fn () => $table->unsignedInteger('opens')->default(0)->after('location_at'));
            $this->add($table, 'ad_profiles', 'last_open_at', fn () => $table->timestamp('last_open_at')->nullable()->after('opens'));
        });

        Schema::table('ad_campaigns', function (Blueprint $table) {
            $this->drop($table, 'ad_campaigns', ['personalised_only']);
            $this->rename($table, 'ad_campaigns', 'interests', 'segments');
            // Where this ad may appear; empty means every placement that is switched on.
            $this->add($table, 'ad_campaigns', 'placements', fn () => $table->json('placements')->nullable()->after('gender'));
        });

        Schema::table('ad_views', function (Blueprint $table) {
            $this->add($table, 'ad_views', 'placement', fn () => $table->string('placement', 24)->default('chat_list')->after('user_id'));
            // One row per ad, person, day AND placement. The wider key goes in first: the old one
            // is holding up the campaign_id foreign key until something else can.
            if (! $this->hasIndex('ad_views', 'ad_views_campaign_id_user_id_day_placement_unique')) {
                $table->unique(['campaign_id', 'user_id', 'day', 'placement']);
            }
            if ($this->hasIndex('ad_views', 'ad_views_campaign_id_user_id_day_unique')) {
                $table->dropUnique('ad_views_campaign_id_user_id_day_unique');
            }
        });

        // Views and taps per placement, so the admin can see where an ad works.
        if (! Schema::hasTable('ad_placement_stats')) {
            Schema::create('ad_placement_stats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
                $table->string('placement', 24);
                $table->unsignedInteger('impressions')->default(0);
                $table->unsignedInteger('clicks')->default(0);
                $table->unique(['campaign_id', 'placement']);
            });
        }
    }

    /* Each step checks itself, so a run that stopped half way can simply be run again. */

    private function add(Blueprint $table, string $on, string $column, callable $define): void
    {
        if (! Schema::hasColumn($on, $column)) {
            $define();
        }
    }

    private function drop(Blueprint $table, string $on, array $columns): void
    {
        $existing = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($on, $c)));
        if ($existing) {
            $table->dropColumn($existing);
        }
    }

    private function rename(Blueprint $table, string $on, string $from, string $to): void
    {
        if (Schema::hasColumn($on, $from) && ! Schema::hasColumn($on, $to)) {
            $table->renameColumn($from, $to);
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return (bool) Schema::getConnection()->selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_placement_stats');

        Schema::table('ad_views', function (Blueprint $table) {
            $table->dropUnique('ad_views_campaign_id_user_id_day_placement_unique');
            $table->dropColumn('placement');
            $table->unique(['campaign_id', 'user_id', 'day']);
        });

        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropColumn('placements');
            $table->renameColumn('segments', 'interests');
            $table->boolean('personalised_only')->default(false);
        });

        Schema::table('ad_profiles', function (Blueprint $table) {
            $table->dropColumn(['city', 'device_model', 'ip', 'ip_country', 'location_at', 'opens', 'last_open_at']);
            $table->renameColumn('segments', 'interests');
            $table->string('gender', 10)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gender', 'birth_date']);
            $table->boolean('ads_personalised')->nullable();
            $table->timestamp('ads_consent_at')->nullable();
        });
    }
};
