<?php

namespace Tests\Feature\Admin;

use App\Models\AdCampaign;
use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CoinService;
use App\Services\PromotionService;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Promotions (Y2): the review queue, the nav badge, and approve / reject / stop with
 * their audit rows.
 */
class PromotionsAdminTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        AppSetting::put(['paid_enabled' => true, 'promote_enabled' => true, 'ads_enabled' => true]);
        $this->admin = User::factory()->admin()->create();
    }

    private function user(int $coins = 1000): User
    {
        $user = User::factory()->create(['phone' => '+92300'.str_pad((string) ++self::$seq, 7, '0', STR_PAD_LEFT)]);
        app(CoinService::class)->credit($user, $coins, 'purchase', null, 'payment:test:'.$user->id);

        return $user;
    }

    private function promotion(User $owner, array $data = []): AdCampaign
    {
        $status = app(StatusService::class)->createText($owner, 'Promoted '.Str::random(4), 'teal', 0);

        return app(PromotionService::class)->create($owner, array_merge([
            'kind' => 'status', 'target_id' => $status->id, 'coins' => 100, 'client_token' => (string) Str::uuid(),
        ], $data));
    }

    public function test_the_queue_has_tabs_and_the_nav_counts_pending_reviews(): void
    {
        $owner = $this->user(5000);
        $pending = $this->promotion($owner, ['title' => 'Waiting one']);
        $running = app(PromotionService::class)->approve($this->admin, $this->promotion($owner, ['title' => 'Running one']));
        $rejected = app(PromotionService::class)->reject($this->admin, $this->promotion($owner, ['title' => 'Rejected one']), 'No');
        $stopped = app(PromotionService::class)->stop(app(PromotionService::class)->approve($this->admin, $this->promotion($owner, ['title' => 'Stopped one'])), $this->admin, 'admin');

        $this->actingAs($this->admin)->get(route('admin.promotions'))->assertOk()
            ->assertSee('Waiting one')->assertDontSee('Running one')->assertDontSee('Rejected one')
            ->assertSee('admin-nav-count', false)
            ->assertSeeInOrder(['Under review', 'Running', 'Finished', 'Rejected']);
        $this->actingAs($this->admin)->get(route('admin.promotions', ['tab' => 'active']))->assertOk()->assertSee('Running one')->assertDontSee('Waiting one');
        $this->actingAs($this->admin)->get(route('admin.promotions', ['tab' => 'completed']))->assertOk()->assertSee('Stopped one');
        $this->actingAs($this->admin)->get(route('admin.promotions', ['tab' => 'rejected']))->assertOk()->assertSee('Rejected one');
        $this->actingAs($this->admin)->get(route('admin.promotions', ['tab' => 'nope']))->assertOk()->assertSee('Waiting one');

        // The show page: card, owner, coins, actions.
        $this->actingAs($this->admin)->get(route('admin.promotions.show', $pending))->assertOk()
            ->assertSee('Waiting one')->assertSee($owner->name)->assertSee('Promoted · '.$owner->name)
            ->assertSee(route('admin.promotions.approve', $pending), false)
            ->assertSee(route('admin.promotions.reject', $pending), false);
        $this->actingAs($this->admin)->get(route('admin.promotions.show', $running))->assertOk()
            ->assertSee(route('admin.promotions.stop', $running), false)
            ->assertDontSee(route('admin.promotions.approve', $running), false);
        $this->actingAs($this->admin)->get(route('admin.promotions.show', $rejected))->assertOk()->assertSee('No');

        // A house ad is not a promotion.
        $house = AdCampaign::query()->create(['name' => 'House', 'status' => 'active', 'title' => 'Buy', 'cta_label' => 'Go', 'target_url' => 'https://example.com', 'per_user_daily_cap' => 3, 'weight' => 1]);
        $this->actingAs($this->admin)->get(route('admin.promotions.show', $house))->assertNotFound();

        // The badge disappears once nothing is pending.
        app(PromotionService::class)->approve($this->admin, $pending);
        $this->actingAs($this->admin)->get(route('admin.promotions'))->assertOk()->assertDontSee('admin-nav-count', false);
        $this->assertSame('stopped', $stopped->status);
    }

    public function test_approve_reject_and_stop_are_audited(): void
    {
        $owner = $this->user(5000);
        $balance = fn () => (int) Wallet::query()->find($owner->id)->balance;

        $approved = $this->promotion($owner);
        $this->actingAs($this->admin)->post(route('admin.promotions.approve', $approved), ['note' => 'Looks fine'])
            ->assertRedirect(route('admin.promotions.show', $approved));
        $this->assertSame('active', $approved->fresh()->status);
        $this->assertSame('Looks fine', $approved->fresh()->review_note);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'promotion.approved', 'admin_id' => $this->admin->id, 'target_type' => 'AdCampaign', 'target_id' => $approved->id]);

        $rejected = $this->promotion($owner);
        $before = $balance();
        $this->actingAs($this->admin)->from(route('admin.promotions.show', $rejected))->post(route('admin.promotions.reject', $rejected), ['note' => ''])
            ->assertSessionHasErrors('note');
        $this->assertSame('pending', $rejected->fresh()->status);
        $this->actingAs($this->admin)->post(route('admin.promotions.reject', $rejected), ['note' => 'Misleading'])
            ->assertRedirect(route('admin.promotions.show', $rejected));
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame($before + 100, $balance());
        $log = AdminAuditLog::query()->where('action', 'promotion.rejected')->where('target_id', $rejected->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(100, $log->meta['coins']);
        $this->assertSame('Misleading', $log->meta['note']);

        // Stop with the refund ticked: the unused share comes back.
        AdCampaign::query()->whereKey($approved->id)->update(['impressions' => 20]); // 2 coins used
        $before = $balance();
        $this->actingAs($this->admin)->post(route('admin.promotions.stop', $approved), ['refund' => '1'])
            ->assertRedirect(route('admin.promotions.show', $approved));
        $this->assertSame('stopped', $approved->fresh()->status);
        $this->assertSame('admin', $approved->fresh()->stop_reason);
        $this->assertSame(98, $approved->fresh()->coins_refunded);
        $this->assertSame($before + 98, $balance());
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'promotion.stopped', 'target_id' => $approved->id]);

        // Stop without the refund (policy breach): nothing back, ever.
        $breach = app(PromotionService::class)->approve($this->admin, $this->promotion($owner));
        $before = $balance();
        $this->actingAs($this->admin)->post(route('admin.promotions.stop', $breach), [])->assertRedirect();
        $this->assertSame('stopped', $breach->fresh()->status);
        $this->assertSame(0, $breach->fresh()->coins_refunded);
        $this->assertNotNull($breach->fresh()->refunded_at);
        $this->assertSame($before, $balance());
        $this->assertNull(app(PromotionService::class)->refund($breach->fresh(), full: true));
        $this->assertSame(0, CoinTransaction::query()->where('idempotency_key', "promo:{$breach->id}:refund")->count());
        $log = AdminAuditLog::query()->where('action', 'promotion.stopped')->where('target_id', $breach->id)->first();
        $this->assertFalse($log->meta['refund']);

        // Wrong state: a friendly error, no change.
        $this->actingAs($this->admin)->from(route('admin.promotions.show', $rejected))->post(route('admin.promotions.approve', $rejected))
            ->assertRedirect(route('admin.promotions.show', $rejected))->assertSessionHasErrors('promotion');
        $this->assertSame('rejected', $rejected->fresh()->status);
    }

    public function test_only_admins_get_in(): void
    {
        $owner = $this->user();
        $promo = $this->promotion($owner);

        $this->actingAs($owner)->get(route('admin.promotions'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.promotions.show', $promo))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.promotions.approve', $promo))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.promotions.reject', $promo), ['note' => 'x'])->assertForbidden();
        $this->actingAs($owner)->post(route('admin.promotions.stop', $promo))->assertForbidden();
        $this->assertSame('pending', $promo->fresh()->status);
    }
}
