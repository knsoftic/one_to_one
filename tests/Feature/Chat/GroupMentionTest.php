<?php

namespace Tests\Feature\Chat;

use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G4 — @mentions in group chats.
 */
class GroupMentionTest extends TestCase
{
    use RefreshDatabase;

    private User $ayesha;

    private User $bilal;

    private User $sara;

    private Conversation $group;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->ayesha, $this->bilal, $this->sara] = [
            User::factory()->create(['name' => 'Ayesha']),
            User::factory()->create(['name' => 'Bilal Ahmed']),
            User::factory()->create(['name' => 'Sara']),
        ];
        $id = $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Family', 'member_ids' => [$this->bilal->id, $this->sara->id]])->json('id');
        $this->group = Conversation::findOrFail($id);
    }

    public function test_mentions_are_kept_only_for_people_in_the_group_named_in_the_text(): void
    {
        $stranger = User::factory()->create(['name' => 'Stranger']);

        $this->actingAs($this->ayesha)->postJson("/conversations/{$this->group->id}/messages", [
            'message' => '@Bilal Bhai are you coming? @Sara too',
            'mentions' => [
                ['id' => $this->bilal->id, 'name' => 'Bilal Bhai'],
                ['id' => $this->sara->id, 'name' => 'Sara'],
                ['id' => $stranger->id, 'name' => 'Stranger'],
                ['id' => $this->ayesha->id, 'name' => 'Ayesha'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('mentions', [
                ['id' => $this->bilal->id, 'name' => 'Bilal Bhai'],
                ['id' => $this->sara->id, 'name' => 'Sara'],
            ]);

        // A mention whose name is not in the text is dropped.
        $this->actingAs($this->ayesha)->postJson("/conversations/{$this->group->id}/messages", [
            'message' => 'Nobody here',
            'mentions' => [['id' => $this->bilal->id, 'name' => 'Bilal Bhai']],
        ])->assertCreated()->assertJsonMissingPath('mentions');
    }

    public function test_mentioned_people_see_an_at_badge_and_are_notified_in_muted_groups(): void
    {
        ChatSetting::create(['conversation_id' => $this->group->id, 'user_id' => $this->bilal->id, 'muted_until' => ChatSetting::MUTE_ALWAYS_UNTIL]);
        ChatSetting::create(['conversation_id' => $this->group->id, 'user_id' => $this->sara->id, 'muted_until' => ChatSetting::MUTE_ALWAYS_UNTIL]);

        $this->actingAs($this->ayesha)->postJson("/conversations/{$this->group->id}/messages", [
            'message' => 'Dinner at 8, @Bilal Ahmed',
            'mentions' => [['id' => $this->bilal->id, 'name' => 'Bilal Ahmed']],
        ])->assertCreated();

        $this->assertSame('Ayesha mentioned you in Family', $this->bilal->notifications()->where('data->body', 'Dinner at 8, @Bilal Ahmed')->sole()->data['title']);
        $this->assertSame(0, $this->sara->notifications()->where('data->body', 'Dinner at 8, @Bilal Ahmed')->count());

        $this->actingAs($this->bilal)->getJson('/conversations')->assertJsonPath('0.unread_mentions', 1);
        $this->actingAs($this->sara)->getJson('/conversations')->assertJsonPath('0.unread_mentions', 0)->assertJsonPath('0.unread_count', 1);

        $this->actingAs($this->bilal)->postJson("/conversations/{$this->group->id}/seen")->assertOk();
        $this->actingAs($this->bilal)->getJson('/conversations')->assertJsonPath('0.unread_mentions', 0);

        // A reply reaches the person replied to in a muted group, like a mention.
        $mine = $this->actingAs($this->sara)->postJson("/conversations/{$this->group->id}/messages", ['message' => 'I will bring dessert'])->json('id');
        $this->actingAs($this->ayesha)->postJson("/conversations/{$this->group->id}/messages", ['message' => 'Great!', 'reply_to_id' => $mine])->assertCreated();
        $this->assertSame(1, $this->sara->notifications()->where('data->body', 'Great!')->count());
    }
}
