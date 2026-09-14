<?php

namespace Tests\Feature\Privacy;

use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6 — Report user: people report, admins review.
 */
class ReportUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_report_sends_the_last_messages_and_can_block(): void
    {
        $me = User::factory()->create();
        $spammer = User::factory()->create(['name' => 'Cheap Loans']);
        $chat = Conversation::factory()->between($me, $spammer)->create();
        foreach (range(1, 7) as $i) {
            Message::factory()->inConversation($chat, $spammer)->create(['message' => "Loan offer {$i}"]);
        }
        Message::factory()->inConversation($chat, $me)->create(['message' => 'Stop please']);

        $this->actingAs($me)->postJson("/users/{$spammer->id}/report", [])->assertJsonValidationErrors(['reason' => 'Choose why you are reporting.']);
        $this->actingAs($me)->postJson("/users/{$me->id}/report", ['reason' => 'spam'])->assertUnprocessable();

        $this->actingAs($me)->postJson("/users/{$spammer->id}/report", ['reason' => 'spam', 'details' => 'Sends loan ads every hour', 'conversation_id' => $chat->id, 'block' => true])
            ->assertCreated()
            ->assertJsonPath('evidence_count', 5)
            ->assertJsonPath('blocked', true);

        $report = UserReport::sole();
        $this->assertSame(['Loan offer 3', 'Loan offer 4', 'Loan offer 5', 'Loan offer 6', 'Loan offer 7'], array_column($report->evidence, 'preview'));
        $this->assertTrue(BlockedUser::query()->where('user_id', $me->id)->where('blocked_user_id', $spammer->id)->exists());

        // Reporting again the same day updates the open report; someone else's chat is never attached.
        $other = Conversation::factory()->between($spammer, User::factory()->create())->create();
        $this->actingAs($me)->postJson("/users/{$spammer->id}/report", ['reason' => 'fake', 'conversation_id' => $other->id])->assertCreated()->assertJsonPath('evidence_count', 0);
        $this->assertSame(1, UserReport::count());
        $this->assertSame('fake', UserReport::sole()->reason);
        $this->assertTrue(UserReport::sole()->blocked);
    }

    public function test_admins_review_reports_and_others_cannot(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $me = User::factory()->create(['name' => 'Reporter Rehan']);
        $abuser = User::factory()->create(['name' => 'Rude Rashid']);
        $report = UserReport::create(['reporter_id' => $me->id, 'reported_user_id' => $abuser->id, 'reason' => 'abuse', 'details' => 'Keeps insulting me', 'evidence' => [['id' => 1, 'type' => 'text', 'preview' => 'You are useless', 'created_at' => now()->toIso8601String()]]]);

        $this->actingAs($me)->get('/admin/reports')->assertForbidden();

        $this->actingAs($admin)->get('/admin/reports')->assertOk()->assertSee('Rude Rashid')->assertSee('Abusive or harassing');
        $this->actingAs($admin)->get("/admin/reports/{$report->id}")->assertOk()->assertSee('Keeps insulting me')->assertSee('You are useless')->assertSee('Reporter Rehan');

        $this->actingAs($admin)->patch("/admin/reports/{$report->id}", ['status' => 'reviewed', 'admin_note' => 'Suspended for a week'])
            ->assertRedirect("/admin/reports/{$report->id}");
        $report->refresh();
        $this->assertSame('reviewed', $report->status);
        $this->assertSame($admin->id, $report->reviewed_by);
        $this->assertSame('Suspended for a week', $report->admin_note);

        $this->actingAs($admin)->get('/admin/reports')->assertSee('No open reports');
        $this->actingAs($admin)->get('/admin/reports?status=reviewed')->assertSee('Rude Rashid');
    }
}
