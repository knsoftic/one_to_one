<?php

namespace Tests\Feature\Money;

use App\Models\AppSetting;
use App\Models\CoinTransaction;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\MoneyNotification;
use App\Services\BadgeService;
use App\Services\CoinService;
use App\Services\GroupService;
use App\Services\LimitService;
use App\Services\PlanService;
use App\Services\StorageUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Plans & premium (Y2): activation, renewal, queued plans, expiry, monthly coins and the limits a
 * plan raises.
 */
class PlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['phone' => '+92300'.str_pad((string) ++self::$seq, 7, '0', STR_PAD_LEFT)], $attributes));
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::query()->create(array_merge([
            'name' => 'Pro', 'slug' => 'pro-'.++self::$seq, 'period' => 'month', 'price_minor' => 49900, 'currency' => 'PKR',
            'ads_off' => true, 'verified_badge' => true, 'monthly_coins' => 100,
            'limits' => ['upload_mb' => 64, 'group_members' => 512], 'is_active' => true, 'sort' => 0,
        ], $attributes));
    }

    private function plans(): PlanService
    {
        return app(PlanService::class);
    }

    private function balance(User $user): int
    {
        return (int) app(CoinService::class)->wallet($user)->balance;
    }

    /* ------------------------------------------------------------------ */
    /* Activation */
    /* ------------------------------------------------------------------ */

    public function test_activate_snapshots_the_benefits_and_mirrors_the_plan_on_the_user(): void
    {
        $user = $this->user();
        $plan = $this->plan();

        $sub = $this->plans()->activate($user, $plan, 'manual');

        $this->assertSame('active', $sub->status);
        $this->assertSame('manual', $sub->source);
        $this->assertTrue($sub->benefits['ads_off']);
        $this->assertTrue($sub->benefits['verified_badge']);
        $this->assertSame(100, $sub->benefits['monthly_coins']);
        $this->assertSame(['upload_mb' => 64, 'group_members' => 512], $sub->benefits['limits']);
        $this->assertEqualsWithDelta(now()->addMonthsNoOverflow(1)->timestamp, $sub->ends_at->timestamp, 5);

        $user->refresh();
        $this->assertSame($plan->id, $user->plan_id);
        $this->assertSame($sub->ends_at->timestamp, $user->plan_until->timestamp);
        $this->assertTrue($this->plans()->hasBenefit($user, 'ads_off'));
        $this->assertTrue(app(BadgeService::class)->isVerified($user));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id, 'type' => MoneyNotification::class]);
    }

    public function test_period_zero_coins_are_granted_once(): void
    {
        $user = $this->user();
        $sub = $this->plans()->activate($user, $this->plan(), 'manual');

        $this->assertSame(100, $this->balance($user));
        $this->assertSame(1, $sub->fresh()->coins_granted_periods);
        $this->assertSame(1, CoinTransaction::query()->where('idempotency_key', "plan-coins:{$sub->id}:0")->count());

        // The daily command finds nothing more to grant on the same day.
        $this->assertSame(0, $this->plans()->grantMonthlyCoins());
        $this->assertSame(100, $this->balance($user));
    }

    public function test_renewing_the_same_plan_extends_from_the_current_end(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $first = $this->plans()->activate($user, $plan, 'manual');
        $end = $first->ends_at->copy();

        $again = $this->plans()->activate($user, $plan, 'stripe');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Subscription::query()->where('user_id', $user->id)->count());
        $this->assertSame($end->copy()->addMonthsNoOverflow(1)->timestamp, $again->ends_at->timestamp);
        $this->assertSame($again->ends_at->timestamp, $user->fresh()->plan_until->timestamp);
        // A renewal does not hand out a second period 0.
        $this->assertSame(100, $this->balance($user));
    }

    public function test_a_different_plan_is_queued_and_starts_when_the_current_one_expires(): void
    {
        $user = $this->user();
        $pro = $this->plan(['name' => 'Pro']);
        $plus = $this->plan(['name' => 'Plus', 'monthly_coins' => 250, 'ads_off' => false]);

        $current = $this->plans()->activate($user, $pro, 'manual');
        $queued = $this->plans()->activate($user, $plus, 'manual');

        $this->assertSame('queued', $queued->status);
        $this->assertSame($current->ends_at->timestamp, $queued->starts_at->timestamp);
        $this->assertSame($queued->starts_at->copy()->addMonthsNoOverflow(1)->timestamp, $queued->ends_at->timestamp);
        $this->assertSame($pro->id, $user->fresh()->plan_id);
        $this->assertSame(100, $this->balance($user)); // queued plans grant nothing yet

        $this->travel(32)->days();
        $this->artisan('chat:expire-plans')->assertSuccessful();

        $this->assertSame('expired', $current->fresh()->status);
        $this->assertSame('active', $queued->fresh()->status);
        $user->refresh();
        $this->assertSame($plus->id, $user->plan_id);
        $this->assertSame($queued->fresh()->ends_at->timestamp, $user->plan_until->timestamp);
        $this->assertSame(350, $this->balance($user)); // Plus period 0
        $this->assertFalse($this->plans()->hasBenefit($user, 'ads_off'));
    }

    public function test_activation_is_idempotent_per_payment(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $payment = Payment::query()->create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'purpose' => 'plan', 'plan_id' => $plan->id,
            'gateway' => 'manual', 'status' => 'paid', 'platform' => 'web', 'amount_minor' => 49900, 'currency' => 'PKR',
        ]);

        $first = $this->plans()->activate($user, $plan, 'manual', $payment);
        $second = $this->plans()->activate($user, $plan, 'manual', $payment);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->ends_at->timestamp, $second->ends_at->timestamp);
        $this->assertSame($first->id, $payment->fresh()->subscription_id);
        $this->assertSame(100, $this->balance($user));
    }

    /* ------------------------------------------------------------------ */
    /* Expiry */
    /* ------------------------------------------------------------------ */

    public function test_expiry_clears_the_benefits_keeps_a_coin_badge_and_drops_the_plan_badge(): void
    {
        $user = $this->user(['verified_until' => now()->addDays(60), 'verified_source' => 'coins']);
        $this->plans()->activate($user, $this->plan(), 'manual');
        $this->assertSame('plan', app(BadgeService::class)->state($user->fresh())['source']);

        $other = $this->user();
        $this->plans()->activate($other, $this->plan(), 'manual');

        $this->travel(32)->days();
        $this->artisan('chat:expire-plans')->assertSuccessful();

        $user->refresh();
        $other->refresh();
        $this->assertNull($user->plan_id);
        $this->assertNull($user->plan_until);
        $this->assertFalse($this->plans()->hasBenefit($user, 'ads_off'));
        $this->assertSame(0, Subscription::query()->where('status', 'active')->count());

        $badge = app(BadgeService::class);
        $this->assertTrue($badge->isVerified($user));          // the coin badge outlives the plan
        $this->assertSame('coins', $badge->state($user)['source']);
        $this->assertFalse($badge->isVerified($other));        // the plan badge went with the plan
        $this->assertSame(2, DatabaseNotification::query()->where('data->type', 'plan_expired')->count());
    }

    public function test_an_admin_revoke_ends_the_plan_now_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->user();
        $sub = $this->plans()->grant($admin, $user, $this->plan(), 30);

        $this->assertSame('active', $sub->status);
        $this->assertSame('admin', $sub->source);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'subscription.granted', 'admin_id' => $admin->id, 'target_id' => $sub->id]);

        $this->plans()->revoke($sub, 'admin', $admin, 'chargeback');

        $this->assertSame('revoked', $sub->fresh()->status);
        $this->assertSame($admin->id, $sub->fresh()->ended_by);
        $this->assertNull($user->fresh()->plan_id);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'subscription.ended', 'admin_id' => $admin->id, 'target_id' => $sub->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Monthly coins */
    /* ------------------------------------------------------------------ */

    public function test_monthly_coins_run_twice_write_one_row_and_catch_up_after_missed_days(): void
    {
        $user = $this->user();
        $sub = $this->plans()->activate($user, $this->plan(['period' => 'year']), 'manual');
        $this->assertSame(100, $this->balance($user));

        $this->travel(1)->month();
        $this->travel(3)->days();
        $this->assertSame(1, $this->plans()->grantMonthlyCoins());
        $this->assertSame(0, $this->plans()->grantMonthlyCoins());   // same day again: nothing new
        $this->artisan('chat:plan-coins')->assertSuccessful();
        $this->assertSame(200, $this->balance($user));
        $this->assertSame(2, $sub->fresh()->coins_granted_periods);

        // The command did not run for two months: both are caught up in one go, each with its own key.
        $this->travel(2)->months();
        $this->assertSame(2, $this->plans()->grantMonthlyCoins());
        $this->assertSame(400, $this->balance($user));
        $this->assertSame(4, $sub->fresh()->coins_granted_periods);
        foreach ([0, 1, 2, 3] as $n) {
            $this->assertSame(1, CoinTransaction::query()->where('idempotency_key', "plan-coins:{$sub->id}:{$n}")->count());
        }
        $this->assertStringContainsString('month 4', CoinTransaction::query()->where('idempotency_key', "plan-coins:{$sub->id}:3")->value('note'));
    }

    public function test_a_yearly_plan_gets_twelve_grants(): void
    {
        $user = $this->user();
        $sub = $this->plans()->activate($user, $this->plan(['period' => 'year']), 'manual');

        $this->travel(11)->months();
        $this->travel(5)->days();
        $this->assertSame(11, $this->plans()->grantMonthlyCoins());
        $this->assertSame(12, $sub->fresh()->coins_granted_periods);
        $this->assertSame(1200, $this->balance($user));

        // Still inside the year, but every period is paid out.
        $this->travel(20)->days();
        $this->assertSame(0, $this->plans()->grantMonthlyCoins());
        $this->assertSame(1200, $this->balance($user));
    }

    public function test_the_ending_reminder_goes_out_once(): void
    {
        $user = $this->user();
        $this->plans()->activate($user, $this->plan(), 'manual');

        $this->assertSame(0, $this->plans()->remindEnding());
        $this->travel(29)->days();
        $this->assertSame(1, $this->plans()->remindEnding());
        $this->assertSame(0, $this->plans()->remindEnding());
        $this->assertSame(1, DatabaseNotification::query()->where('data->type', 'plan_ending')->count());
    }

    /* ------------------------------------------------------------------ */
    /* Snapshots and limits */
    /* ------------------------------------------------------------------ */

    public function test_editing_a_plan_leaves_a_running_subscription_unchanged(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $this->plans()->activate($user, $plan, 'manual');

        $plan->update(['ads_off' => false, 'monthly_coins' => 0, 'limits' => ['upload_mb' => 8]]);

        $benefits = app(PlanService::class)->benefits($user->fresh());
        $this->assertTrue($benefits['ads_off']);
        $this->assertSame(100, $benefits['monthly_coins']);
        $this->assertSame(64, $benefits['limits']['upload_mb']);
    }

    public function test_limit_service_returns_plan_limits_and_app_defaults(): void
    {
        config(['chat.uploads.image.max_kb' => 10240, 'chat.uploads.video.max_kb' => 16384, 'chat.uploads.document.max_kb' => 20480,
            'chat.groups.max_members' => 256, 'chat.storage.default_mb' => 0]);
        $limits = app(LimitService::class);
        $free = $this->user();
        $paid = $this->user();
        $this->plans()->activate($paid, $this->plan(['limits' => ['upload_mb' => 64, 'group_members' => 100, 'storage_mb' => 2048]]), 'manual');

        $this->assertSame(['upload_mb' => 20, 'group_members' => 256, 'broadcast_recipients' => 256, 'storage_mb' => 0], $limits->for($free));
        $this->assertSame(20480, $limits->uploadKb($free, 'document'));
        $this->assertSame(10240, $limits->uploadKb($free, 'image'));

        $this->assertSame(64, $limits->for($paid)['upload_mb']);
        $this->assertSame(256, $limits->for($paid)['group_members']);      // a plan never lowers a limit
        $this->assertSame(2048, $limits->storageMb($paid));
        $this->assertSame(65536, $limits->uploadKb($paid, 'image'));
        $this->assertSame(65536, $limits->uploadKb($paid, 'document'));
    }

    public function test_a_group_over_the_creators_limit_is_refused(): void
    {
        config(['chat.groups.max_members' => 3]);
        $groups = app(GroupService::class);
        $free = $this->user();
        $paid = $this->user();
        $this->plans()->activate($paid, $this->plan(['limits' => ['group_members' => 10]]), 'manual');
        $members = collect(range(1, 3))->map(fn () => $this->user()->id)->all();

        $this->assertSame(3, $groups->maxMembers($free));
        $this->assertSame(10, $groups->maxMembers($paid));

        try {
            $groups->create($free, 'Too big', $members);
            $this->fail('The group should have been refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('up to 3 people', $e->getMessage());
        }

        $group = $groups->create($paid, 'Big enough', $members);
        $this->assertSame(4, $group->activeMembers()->count());
        // The creator's plan sets the size, whoever adds people later.
        $this->assertSame(10, $groups->maxMembers($group->creator));
    }

    public function test_a_plan_raises_the_upload_limit_and_the_storage_quota_is_enforced(): void
    {
        Storage::fake('chat');
        config(['chat.uploads.document.max_kb' => 1, 'chat.storage.default_mb' => 0]);
        AppSetting::put(['paid_enabled' => true]);

        $free = $this->user();
        $paid = $this->user();
        $friend = $this->user();
        $this->plans()->activate($paid, $this->plan(['limits' => ['upload_mb' => 1, 'storage_mb' => 1]]), 'manual');

        $chats = [
            $free->id => Conversation::factory()->between($free, $friend)->create(),
            $paid->id => Conversation::factory()->between($paid, $friend)->create(),
        ];
        $send = function (User $as, User $with, UploadedFile $file) use ($chats) {
            return $this->actingAs($as)->post("/conversations/{$chats[$as->id]->id}/messages", ['attachment' => $file], ['Accept' => 'application/json']);
        };
        $pdf = function () {
            $path = tempnam(sys_get_temp_dir(), 'upl');
            file_put_contents($path, "%PDF-1.4\n%".str_repeat('x', 3000)."\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

            return new UploadedFile($path, 'report.pdf', null, null, true);
        };

        // ~3 KB: over the 1 KB app limit, within the plan's 1 MB.
        $send($free, $friend, $pdf())->assertStatus(422)->assertJsonValidationErrors('attachment');
        $send($paid, $friend, $pdf())->assertCreated();

        // Storage quota (the plan's 1 MB): already full ⇒ refused, with a clear message.
        $this->mock(StorageUsageService::class)->shouldReceive('usedBytes')->andReturn(1024 * 1024);
        $send($paid, $friend, $pdf())->assertStatus(422)
            ->assertJsonValidationErrors(['attachment' => 'used your 1 MB of storage']);
        // No quota for the free plan (0 = unlimited): only the size limit applies.
        config(['chat.uploads.document.max_kb' => 64]);
        $send($free, $friend, $pdf())->assertCreated();
    }
}
