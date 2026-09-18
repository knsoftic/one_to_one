<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid features (Y2): plans and subscriptions, the coin wallet and its ledger, payments through
 * manual transfer / Stripe / PayPal / Google Play, and refer-and-earn. Money is always in integer
 * minor units (PKR 499.00 = 49900); coins are integers. Every step checks itself so a run that
 * stopped half way can simply be run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60);
                $table->string('slug', 40)->unique();
                $table->string('description', 200)->nullable();
                $table->string('period', 8)->default('month');
                $table->unsignedInteger('price_minor');
                $table->char('currency', 3);
                $table->unsignedInteger('price_usd_minor')->nullable();
                $table->boolean('ads_off')->default(false);
                $table->boolean('verified_badge')->default(false);
                $table->unsignedInteger('monthly_coins')->default(0);
                $table->json('limits')->nullable();
                $table->string('play_product_id', 80)->nullable()->unique();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('coin_packs')) {
            Schema::create('coin_packs', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60);
                $table->unsignedInteger('coins');
                $table->unsignedInteger('bonus_coins')->default(0);
                $table->unsignedInteger('price_minor');
                $table->char('currency', 3);
                $table->unsignedInteger('price_usd_minor')->nullable();
                $table->string('play_product_id', 80)->nullable()->unique();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        // Payments come before subscriptions: a subscription points at the payment that bought it.
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->char('client_token', 36)->nullable()->unique();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('purpose', 8);
                $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('coin_pack_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->string('gateway', 8);
                $table->string('status', 10)->default('pending');
                $table->string('platform', 8)->default('web');
                $table->unsignedInteger('amount_minor');
                $table->char('currency', 3);
                $table->unsignedInteger('coins')->nullable();
                $table->string('gateway_ref', 191)->nullable();
                $table->string('gateway_capture_ref', 191)->nullable();
                $table->string('manual_method', 12)->nullable();
                $table->string('proof_path')->nullable();
                $table->string('proof_ref', 64)->nullable();
                $table->string('proof_note', 160)->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 200)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('fulfilled_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->string('refund_ref', 191)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                // The Play token-replay and Stripe/PayPal duplicate guard.
                $table->unique(['gateway', 'gateway_ref']);
                $table->index(['user_id', 'status']);
                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained()->restrictOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status', 10)->default('active');
                $table->string('source', 8)->default('admin');
                $table->json('benefits');
                // dateTime, not timestamp: MariaDB gives a second NOT NULL timestamp an invalid default.
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->unsignedSmallInteger('coins_granted_periods')->default(0);
                $table->timestamp('reminded_at')->nullable();
                $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('end_reason', 60)->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['status', 'ends_at']);
                $table->index(['status', 'starts_at']);
            });
        }

        if (! $this->hasForeign('payments', 'payments_subscription_id_foreign')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
                $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
                // Unsigned: a race that slips past the PHP check fails at the database, never below zero.
                $table->unsignedInteger('balance')->default(0);
                $table->unsignedInteger('withdrawable')->default(0);
                $table->unsignedInteger('earned_total')->default(0);
                $table->unsignedInteger('purchased_total')->default(0);
                $table->unsignedInteger('spent_total')->default(0);
                $table->boolean('frozen')->default(false);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('coin_transactions')) {
            Schema::create('coin_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type', 24);
                $table->integer('amount');
                $table->integer('withdrawable_delta')->default(0);
                $table->unsignedInteger('balance_after');
                $table->unsignedInteger('withdrawable_after');
                $table->string('reference_type', 40)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('idempotency_key', 80)->unique();
                $table->string('note', 160)->nullable();
                $table->json('meta')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->index(['user_id', 'created_at']);
                $table->index(['reference_type', 'reference_id']);
            });
        }

        if (! Schema::hasTable('payment_events')) {
            Schema::create('payment_events', function (Blueprint $table) {
                $table->id();
                $table->string('gateway', 8);
                $table->string('event_id', 191);
                $table->string('type', 80);
                $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
                $table->json('payload')->nullable();
                // Null = received but not applied yet (a previous attempt threw and was retried).
                $table->timestamp('processed_at')->nullable();
                $table->string('error', 300)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['gateway', 'event_id']);
            });
        }

        if (! Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
                // One reward per referred account, ever.
                $table->foreignId('referred_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->string('code', 12);
                $table->string('status', 10)->default('pending');
                $table->string('void_reason', 40)->nullable();
                $table->unsignedInteger('referrer_coins')->default(0);
                $table->unsignedInteger('referred_coins')->default(0);
                $table->char('ip_hash', 64)->nullable();
                $table->timestamp('rewarded_at')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['referrer_id', 'status']);
                $table->index(['ip_hash', 'created_at']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_code')) {
                $table->char('referral_code', 8)->nullable()->unique()->after('birth_date');
            }
            if (! Schema::hasColumn('users', 'referred_by')) {
                $table->foreignId('referred_by')->nullable()->after('referral_code')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'plan_id')) {
                $table->foreignId('plan_id')->nullable()->after('referred_by')->constrained('plans')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'plan_until')) {
                $table->timestamp('plan_until')->nullable()->after('plan_id')->index();
            }
            if (! Schema::hasColumn('users', 'verified_until')) {
                $table->timestamp('verified_until')->nullable()->after('plan_until');
            }
            if (! Schema::hasColumn('users', 'verified_source')) {
                $table->string('verified_source', 8)->nullable()->after('verified_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['referred_by', 'plan_id'] as $fk) {
                if (Schema::hasColumn('users', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            $table->dropColumn(array_values(array_filter(
                ['referral_code', 'plan_until', 'verified_until', 'verified_source'],
                fn ($c) => Schema::hasColumn('users', $c),
            )));
        });

        Schema::dropIfExists('referrals');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('coin_transactions');
        Schema::dropIfExists('wallets');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
        });
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('coin_packs');
        Schema::dropIfExists('plans');
    }

    private function hasForeign(string $table, string $name): bool
    {
        return (bool) Schema::getConnection()->selectOne(
            'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? LIMIT 1',
            [$table, $name],
        );
    }
};
