<?php

namespace Tests\Feature\Chat;

use App\Events\MessagesExpired;
use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\StarredMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M21 — Disappearing messages.
 */
class DisappearingMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('chat');
        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    public function test_turning_it_on_leaves_a_notice_and_new_messages_get_an_end_time(): void
    {
        $before = $this->send(['message' => 'Old message'])->json('expires_at');
        $this->assertNull($before);

        $this->setTimer(604800)
            ->assertOk()
            ->assertJsonPath('disappearing_seconds', 604800)
            ->assertJsonPath('message.type', 'system')
            ->assertJsonPath('message.expires_at', null)
            ->assertJsonPath('message.system.text', '⏱️ Disappearing messages turned on: new messages disappear after 7 days');

        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}")->assertJsonPath('disappearing_seconds', 604800);

        $this->freezeTime();
        $this->send(['message' => 'Secret'])->assertJsonPath('expires_at', now()->addDays(7)->toIso8601String());

        // Setting the same value again adds no second notice.
        $this->setTimer(604800)->assertJsonPath('message', null);

        $this->setTimer(0)->assertJsonPath('disappearing_seconds', null)->assertJsonPath('message.system.text', '⏱️ Disappearing messages turned off');
        $this->send(['message' => 'Normal again'])->assertJsonPath('expires_at', null);
    }

    public function test_notices_do_not_notify_the_other_person(): void
    {
        $this->setTimer(86400)->assertOk();

        $this->assertSame(0, $this->friend->fresh()->notifications()->count());
    }

    public function test_only_allowed_durations_and_participants_who_can_send(): void
    {
        $this->setTimer(3600)->assertJsonValidationErrors('seconds');
        $this->actingAs(User::factory()->create())->putJson("/conversations/{$this->conversation->id}/disappearing", ['seconds' => 86400])->assertNotFound();

        BlockedUser::query()->create(['user_id' => $this->friend->id, 'blocked_user_id' => $this->me->id]);
        $this->setTimer(86400)->assertForbidden();
    }

    public function test_expired_messages_are_removed_for_both_with_their_files(): void
    {
        Event::fake([MessagesExpired::class]);

        $keep = $this->send(['message' => 'Before timer'])->json('id');
        $this->setTimer(86400);
        $photo = $this->send(['attachment' => UploadedFile::fake()->image('p.jpg', 400, 300)])->json('id');
        $text = $this->send(['message' => 'Gone tomorrow'])->json('id');

        MessageReaction::query()->create(['message_id' => $text, 'user_id' => $this->friend->id, 'emoji' => '👍']);
        StarredMessage::query()->create(['message_id' => $text, 'user_id' => $this->friend->id]);
        $files = Storage::disk('chat')->allFiles();
        $this->assertNotEmpty($files);

        $this->travel(23)->hours();
        $this->artisan('chat:expire-messages')->expectsOutput('Removed 0 disappearing message(s).');

        $this->travel(2)->hours();
        $this->artisan('chat:expire-messages')->expectsOutput('Removed 2 disappearing message(s).');

        $this->assertModelMissing(Message::make(['id' => $photo]));
        $this->assertNull(Message::find($text));
        $this->assertNotNull(Message::find($keep));
        $this->assertSame(0, MessageReaction::count() + StarredMessage::count());
        $this->assertSame([], Storage::disk('chat')->allFiles());

        // The notice stays and becomes the last message.
        $notice = Message::where('message_type', 'system')->sole();
        $this->assertSame($notice->id, $this->conversation->fresh()->last_message_id);

        Event::assertDispatched(MessagesExpired::class, fn (MessagesExpired $event) => $event->broadcastWith() === ['conversation_id' => $this->conversation->id, 'ids' => [$photo, $text]]
            && count($event->broadcastOn()) === 2);
    }

    public function test_notices_cannot_be_forwarded_reacted_to_or_pinned(): void
    {
        $id = $this->setTimer(86400)->json('message.id');

        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();
        $this->actingAs($this->me)->postJson("/messages/{$id}/forward", ['conversation_ids' => [$other->id]])->assertForbidden();
        $this->actingAs($this->me)->putJson("/messages/{$id}/reaction", ['emoji' => '👍'])->assertForbidden();
        $this->actingAs($this->me)->putJson("/messages/{$id}/pin", ['duration' => 86400])->assertForbidden();
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->me)->post("/conversations/{$this->conversation->id}/messages", $payload, ['Accept' => 'application/json']);
    }

    private function setTimer(?int $seconds)
    {
        return $this->actingAs($this->me)->putJson("/conversations/{$this->conversation->id}/disappearing", ['seconds' => $seconds]);
    }
}
