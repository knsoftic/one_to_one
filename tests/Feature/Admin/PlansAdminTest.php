<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin → Plans and Subscriptions (Y2): creating and editing plans, what cannot be deleted,
 * granting and ending a plan for one person — all audited, all admin-only.
 */
class PlansAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function user(): User
    {
        return User::factory()->create(['phone' => '+92300'.str_pad((string) ++self::$seq, 7, '0', STR_PAD_LEFT)]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pro', 'slug' => 'pro-monthly', 'description' => 'Everything', 'period' => 'month',
            'price' => '499', 'currency' => 'pkr', 'price_usd' => '4.99',
            'ads_off' => '1', 'verified_badge' => '1', 'monthly_coins' => '100',
            'limits' => ['upload_mb' => '64', 'group_members' => '512', 'broadcast_recipients' => '', 'storage_mb' => '2048'],
            'play_product_id' => 'plan_pro_month', 'is_active' => '1', 'sort' => '2',
        ], $overrides);
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::query()->create(array_merge([
            'name' => 'Pro', 'slug' => 'pro-'.++self::$seq, 'period' => 'month', 'price_minor' => 49900, 'currency' => 'PKR',
            'ads_off' => true, 'verified_badge' => false, 'monthly_coins' => 0, 'is_active' => true,
        ], $attributes));
    }

    public function test_the_list_and_the_forms_render(): void
    {
        $plan = $this->plan(['name' => 'Gold', 'play_product_id' => 'plan_gold']);

        $this->actingAs($this->admin)->get(route('admin.plans'))->assertOk()
            ->assertSee('Gold')->assertSee('plan_gold')->assertSee('Rs 499')->assertSee('No ads')
            ->assertSee('Paid features are switched off');
        $this->actingAs($this->admin)->get(route('admin.plans.create'))->assertOk()->assertSee('New plan')->assertSee('data-plan-toggle="coins"', false);
        $this->actingAs($this->admin)->get(route('admin.plans.edit', $plan))->assertOk()->assertSee('Gold')->assertSee('Delete plan');
    }

    public function test_it_creates_a_plan_from_the_form_and_audits_it(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.plans.store'), $this->payload());

        $plan = Plan::query()->firstWhere('slug', 'pro-monthly');
        $this->assertNotNull($plan);
        $response->assertRedirect(route('admin.plans.edit', $plan));
        $this->assertSame(49900, $plan->price_minor);
        $this->assertSame('PKR', $plan->currency);
        $this->assertSame(499, $plan->price_usd_minor);
        $this->assertTrue($plan->ads_off);
        $this->assertTrue($plan->verified_badge);
        $this->assertSame(100, $plan->monthly_coins);
        $this->assertSame(['upload_mb' => 64, 'group_members' => 512, 'storage_mb' => 2048], $plan->limits); // blank limit dropped
        $this->assertSame('plan_pro_month', $plan->play_product_id);
        $this->assertTrue($plan->is_active);
        $this->assertSame(2, $plan->sort);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'plan.created', 'admin_id' => $this->admin->id, 'target_type' => 'Plan', 'target_id' => $plan->id]);
    }

    public function test_it_validates_the_form(): void
    {
        $this->plan(['slug' => 'taken', 'play_product_id' => 'plan_taken']);
        $post = fn (array $overrides) => $this->actingAs($this->admin)->post(route('admin.plans.store'), $this->payload($overrides));

        $post(['slug' => 'taken'])->assertSessionHasErrors('slug');
        $post(['slug' => 'Not A Slug'])->assertSessionHasErrors('slug');
        $post(['period' => 'week'])->assertSessionHasErrors('period');
        $post(['price' => '-1'])->assertSessionHasErrors('price');
        $post(['currency' => 'PK'])->assertSessionHasErrors('currency');
        $post(['currency' => '123'])->assertSessionHasErrors('currency');
        $post(['play_product_id' => 'plan_taken'])->assertSessionHasErrors('play_product_id');
        $post(['play_product_id' => 'Has Spaces'])->assertSessionHasErrors('play_product_id');
        $post(['limits' => ['upload_mb' => '0']])->assertSessionHasErrors('limits.upload_mb');
        $post(['limits' => ['group_members' => 'many']])->assertSessionHasErrors('limits.group_members');
        $post(['monthly_coins' => '-5'])->assertSessionHasErrors('monthly_coins');
        $post(['name' => ''])->assertSessionHasErrors('name');
        $this->assertSame(1, Plan::query()->count());

        // Unknown limit keys are ignored, not stored.
        $post(['slug' => 'fresh', 'play_product_id' => '', 'limits' => ['upload_mb' => '32', 'bogus' => '9']])->assertSessionHasNoErrors();
        $this->assertSame(['upload_mb' => 32], Plan::query()->firstWhere('slug', 'fresh')->limits);
        $this->assertNull(Plan::query()->firstWhere('slug', 'fresh')->play_product_id);
    }

    public function test_it_updates_a_plan_without_touching_running_subscriptions(): void
    {
        $plan = $this->plan();
        $user = $this->user();
        $sub = app(PlanService::class)->activate($user, $plan, 'manual');

        $this->actingAs($this->admin)->put(route('admin.plans.update', $plan), $this->payload([
            'slug' => $plan->slug, 'name' => 'Pro Max', 'ads_off' => '', 'monthly_coins' => '', 'limits' => [], 'is_active' => '', 'play_product_id' => '',
        ]))->assertRedirect(route('admin.plans.edit', $plan));

        $plan->refresh();
        $this->assertSame('Pro Max', $plan->name);
        $this->assertFalse($plan->ads_off);
        $this->assertSame(0, $plan->monthly_coins);
        $this->assertNull($plan->limits);
        $this->assertFalse($plan->is_active);
        $this->assertTrue($sub->fresh()->benefits['ads_off']);   // the snapshot is untouched
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'plan.updated', 'target_id' => $plan->id]);

        // Inactive plans are no longer purchasable, but the subscriber keeps their benefits.
        $this->assertCount(0, app(PlanService::class)->purchasable());
        $this->assertTrue(app(PlanService::class)->hasBenefit($user, 'ads_off'));
    }

    public function test_a_referenced_plan_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $used = $this->plan(['name' => 'Used']);
        $unused = $this->plan(['name' => 'Unused']);
        app(PlanService::class)->activate($this->user(), $used, 'manual');

        $this->actingAs($this->admin)->delete(route('admin.plans.destroy', $used))
            ->assertRedirect(route('admin.plans.edit', $used))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Deactivate it instead'));
        $this->assertDatabaseHas('plans', ['id' => $used->id]);
        $this->assertDatabaseMissing(AdminAuditLog::class, ['action' => 'plan.deleted']);
        $this->actingAs($this->admin)->get(route('admin.plans.edit', $used))->assertOk()->assertDontSee('Delete plan')->assertSee('cannot be deleted');

        $this->actingAs($this->admin)->delete(route('admin.plans.destroy', $unused))->assertRedirect(route('admin.plans'));
        $this->assertDatabaseMissing('plans', ['id' => $unused->id]);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'plan.deleted', 'admin_id' => $this->admin->id]);
    }

    public function test_the_subscriptions_list_shows_rows_and_filters(): void
    {
        $pro = $this->plan(['name' => 'Pro']);
        $plus = $this->plan(['name' => 'Plus']);
        $ali = $this->user();
        $sara = $this->user();
        app(PlanService::class)->activate($ali, $pro, 'manual');
        app(PlanService::class)->activate($sara, $plus, 'stripe');
        app(PlanService::class)->activate($sara, $pro, 'manual'); // queued behind Plus

        $this->actingAs($this->admin)->get(route('admin.subscriptions'))->assertOk()
            ->assertSee($ali->name)->assertSee($sara->name)->assertSee('Queued')->assertSee('Card (Stripe)')->assertSee('2 active · 1 queued');
        $this->actingAs($this->admin)->get(route('admin.subscriptions', ['status' => 'queued']))->assertOk()
            ->assertDontSee($ali->name)->assertSee($sara->name);
        $this->actingAs($this->admin)->get(route('admin.subscriptions', ['plan' => $plus->id]))->assertOk()
            ->assertDontSee($ali->name)->assertSee($sara->name);
        $this->actingAs($this->admin)->get(route('admin.subscriptions', ['status' => 'expired']))->assertOk()->assertSee('No subscriptions match');
    }

    public function test_an_admin_grants_and_ends_a_plan_with_audit_rows(): void
    {
        $plan = $this->plan(['monthly_coins' => 50]);
        $user = $this->user();

        $this->actingAs($this->admin)->from(route('admin.users.show', $user))
            ->post(route('admin.users.plan.grant', $user), ['plan_id' => $plan->id, 'days' => 14])
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHas('status');

        $sub = Subscription::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($sub);
        $this->assertSame('admin', $sub->source);
        $this->assertSame('active', $sub->status);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $sub->ends_at->timestamp, 5);
        $this->assertSame($plan->id, $user->fresh()->plan_id);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'subscription.granted', 'admin_id' => $this->admin->id, 'target_id' => $sub->id]);

        $this->actingAs($this->admin)->post(route('admin.users.plan.grant', $user), ['plan_id' => 999999, 'days' => 14])->assertSessionHasErrors('plan_id');
        $this->actingAs($this->admin)->post(route('admin.users.plan.grant', $user), ['plan_id' => $plan->id, 'days' => 0])->assertSessionHasErrors('days');

        $this->actingAs($this->admin)->delete(route('admin.users.plan.end', [$user, $sub]), [])->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->from(route('admin.subscriptions'))
            ->delete(route('admin.users.plan.end', [$user, $sub]), ['note' => 'Granted by mistake'])
            ->assertRedirect(route('admin.subscriptions'))
            ->assertSessionHas('status');

        $this->assertSame('revoked', $sub->fresh()->status);
        $this->assertSame($this->admin->id, $sub->fresh()->ended_by);
        $this->assertNull($user->fresh()->plan_id);
        $log = AdminAuditLog::query()->where('action', 'subscription.ended')->first();
        $this->assertNotNull($log);
        $this->assertSame($sub->id, (int) $log->target_id);
        $this->assertStringContainsString('Granted by mistake', $log->description);

        // Ending it again is refused politely; a subscription of another person is a 404.
        $this->actingAs($this->admin)->delete(route('admin.users.plan.end', [$user, $sub]), ['note' => 'again'])->assertSessionHas('error');
        $this->actingAs($this->admin)->delete(route('admin.users.plan.end', [$this->user(), $sub]), ['note' => 'x'])->assertNotFound();
    }

    public function test_non_admins_get_403_everywhere(): void
    {
        $plan = $this->plan();
        $me = $this->user();

        $this->actingAs($me)->get(route('admin.plans'))->assertForbidden();
        $this->actingAs($me)->get(route('admin.plans.create'))->assertForbidden();
        $this->actingAs($me)->post(route('admin.plans.store'), $this->payload())->assertForbidden();
        $this->actingAs($me)->put(route('admin.plans.update', $plan), $this->payload())->assertForbidden();
        $this->actingAs($me)->delete(route('admin.plans.destroy', $plan))->assertForbidden();
        $this->actingAs($me)->get(route('admin.subscriptions'))->assertForbidden();
        $this->actingAs($me)->post(route('admin.users.plan.grant', $me), ['plan_id' => $plan->id, 'days' => 30])->assertForbidden();
        $this->assertSame(0, Subscription::query()->count());
    }
}
