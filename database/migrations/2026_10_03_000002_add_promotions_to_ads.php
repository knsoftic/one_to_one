<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions (Y2) are ad campaigns owned by a user: the same serving, placements and stats as the
 * admin's house ads, plus a review state and a view budget bought with coins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $this->add('owner_id', fn () => $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete());
            $this->add('kind', fn () => $table->string('kind', 12)->default('house')->after('owner_id'));
            $this->add('target_type', fn () => $table->string('target_type', 20)->nullable()->after('kind'));
            $this->add('target_id', fn () => $table->unsignedBigInteger('target_id')->nullable()->after('target_type'));
            $this->add('client_token', fn () => $table->char('client_token', 36)->nullable()->unique()->after('target_id'));
            $this->add('review_status', fn () => $table->string('review_status', 10)->nullable()->after('client_token'));
            $this->add('review_note', fn () => $table->string('review_note', 200)->nullable()->after('review_status'));
            $this->add('reviewed_by', fn () => $table->foreignId('reviewed_by')->nullable()->after('review_note')->constrained('users')->nullOnDelete());
            $this->add('reviewed_at', fn () => $table->timestamp('reviewed_at')->nullable()->after('reviewed_by'));
            $this->add('coins_spent', fn () => $table->unsignedInteger('coins_spent')->default(0)->after('reviewed_at'));
            $this->add('coins_refunded', fn () => $table->unsignedInteger('coins_refunded')->default(0)->after('coins_spent'));
            $this->add('rate_per_1000', fn () => $table->unsignedInteger('rate_per_1000')->nullable()->after('coins_refunded'));
            // Null = unlimited (house ads).
            $this->add('view_budget', fn () => $table->unsignedInteger('view_budget')->nullable()->after('rate_per_1000'));
            $this->add('completed_at', fn () => $table->timestamp('completed_at')->nullable()->after('view_budget'));
            $this->add('refunded_at', fn () => $table->timestamp('refunded_at')->nullable()->after('completed_at'));
            $this->add('stop_reason', fn () => $table->string('stop_reason', 40)->nullable()->after('refunded_at'));
        });

        Schema::table('ad_campaigns', function (Blueprint $table) {
            foreach ([
                'ad_campaigns_owner_id_created_at_index' => ['owner_id', 'created_at'],
                'ad_campaigns_review_status_created_at_index' => ['review_status', 'created_at'],
                'ad_campaigns_kind_target_type_target_id_index' => ['kind', 'target_type', 'target_id'],
            ] as $name => $columns) {
                if (! $this->hasIndex($name)) {
                    $table->index($columns, $name);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropIndex('ad_campaigns_owner_id_created_at_index');
            $table->dropIndex('ad_campaigns_review_status_created_at_index');
            $table->dropIndex('ad_campaigns_kind_target_type_target_id_index');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'kind', 'target_type', 'target_id', 'client_token', 'review_status', 'review_note', 'reviewed_at',
                'coins_spent', 'coins_refunded', 'rate_per_1000', 'view_budget', 'completed_at', 'refunded_at', 'stop_reason',
            ]);
        });
    }

    private function add(string $column, callable $define): void
    {
        if (! Schema::hasColumn('ad_campaigns', $column)) {
            $define();
        }
    }

    private function hasIndex(string $index): bool
    {
        return (bool) Schema::getConnection()->selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['ad_campaigns', $index],
        );
    }
};
