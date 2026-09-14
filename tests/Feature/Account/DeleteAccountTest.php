<?php

namespace Tests\Feature\Account;

use App\Models\BlockedUser;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\Status;
use App\Models\Sticker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A3 — Delete my account.
 */
class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_password_and_the_tick_are_needed_and_admins_cannot(): void
    {
        $user = User::factory()->create(['password' => 'Password1']);

        $this->actingAs($user)->get('/settings?tab=account')->assertOk()->assertSee('Delete my account');
        $this->actingAs($user)->delete('/settings/account', ['current_password' => 'wrong', 'confirm' => '1'])
            ->assertSessionHasErrors(['current_password'], null, 'deleteAccount');
        $this->actingAs($user)->delete('/settings/account', ['current_password' => 'Password1'])
            ->assertSessionHasErrors(['confirm'], null, 'deleteAccount');
        $this->assertNotNull($user->fresh());

        $admin = User::factory()->create(['password' => 'Password1', 'role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/settings?tab=account')->assertSee("Administrator accounts can't be deleted from settings.", false);
        $this->actingAs($admin)->delete('/settings/account', ['current_password' => 'Password1', 'confirm' => '1'])
            ->assertSessionHasErrors(['current_password'], null, 'deleteAccount');
        $this->assertNotNull($admin->fresh());
    }

    public function test_deleting_leaves_groups_and_removes_the_account_and_its_data(): void
    {
        Storage::fake('chat');
        Storage::fake('public');
        $user = User::factory()->create(['name' => 'Ayesha', 'password' => 'Password1']);
        $bilal = User::factory()->create(['name' => 'Bilal']);
        $sara = User::factory()->create(['name' => 'Sara']);

        // One-to-one chat with a photo.
        $chat = Conversation::factory()->between($user, $bilal)->create();
        $this->actingAs($user)->post("/conversations/{$chat->id}/messages", ['attachment' => UploadedFile::fake()->image('photo.jpg', 300, 300)], ['Accept' => 'application/json'])->assertCreated();
        $photo = Message::query()->where('conversation_id', $chat->id)->sole();
        $untouched = Conversation::factory()->between($bilal, $sara)->create();
        Message::factory()->inConversation($untouched, $bilal)->create();

        // A group Ayesha made (she is its only admin) with a reply to her message.
        $family = $this->group($user, 'Family', [$bilal, $sara]);
        $hello = $this->actingAs($user)->postJson("/conversations/{$family->id}/messages", ['message' => 'Hello family'])->assertCreated()->json('id');
        $reply = $this->actingAs($sara)->postJson("/conversations/{$family->id}/messages", ['message' => 'Hi!', 'reply_to_id' => $hello])->assertCreated()->json('id');
        // Someone else's group she is in.
        $work = $this->group($bilal, 'Work', [$user, $sara]);

        // Channels: her own, and one she follows.
        $mine = $this->actingAs($user)->postJson('/channels', ['name' => 'Ayesha Recipes'])->assertCreated()->json('id');
        $theirs = $this->actingAs($bilal)->postJson('/channels', ['name' => 'Cricket Club'])->assertCreated()->json('id');
        $this->actingAs($user)->postJson("/channels/{$theirs}/follow")->assertOk();

        // Communities: one with Bilal in it, one only she is in.
        $society = Community::findOrFail($this->actingAs($user)->postJson('/communities', ['name' => 'Society', 'group_ids' => [$this->group($user, 'Block A', [$bilal])->id]])->assertCreated()->json('id'));
        $alone = Community::findOrFail($this->actingAs($user)->postJson('/communities', ['name' => 'Just me'])->assertCreated()->json('id'));

        $list = $this->actingAs($user)->postJson('/broadcasts', ['user_ids' => [$bilal->id, $sara->id]])->assertCreated()->json('id');
        Storage::disk('chat')->put('statuses/sunset.jpg', 'x');
        Status::query()->create(['user_id' => $user->id, 'type' => 'image', 'attachment' => 'statuses/sunset.jpg', 'expires_at' => now()->addDay()]);
        Storage::disk('chat')->put('stickers/cat.webp', 'x');
        Sticker::query()->create(['user_id' => $user->id, 'path' => 'stickers/cat.webp', 'hash' => sha1('cat')]);
        BlockedUser::create(['user_id' => $sara->id, 'blocked_user_id' => $user->id]);
        $files = array_filter([$photo->attachment, $photo->attachment_meta['thumbnail'] ?? null]);

        $this->actingAs($user)->delete('/settings/account', ['current_password' => 'Password1', 'confirm' => '1'])
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Your account has been deleted.');
        $this->assertGuest();

        $this->assertNull(User::find($user->id));
        $this->assertNull(Conversation::find($chat->id));
        foreach ([...$files, 'statuses/sunset.jpg', 'stickers/cat.webp'] as $file) {
            Storage::disk('chat')->assertMissing($file);
        }
        $this->assertSame(0, BlockedUser::count());
        $this->assertSame(0, Message::query()->where('sender_id', $user->id)->count());

        // Her group stays with Bilal (the longest member) as admin; Sara's reply stays.
        $family->refresh();
        $this->assertNull($family->ended_at);
        $this->assertSame([$bilal->id, $sara->id], $family->activeMembers()->orderBy('user_id')->pluck('user_id')->all());
        $this->assertSame(ConversationMember::ROLE_ADMIN, $family->memberFor($bilal)->role);
        $this->assertNull(Message::findOrFail($reply)->reply_to_id);
        $this->assertSame($reply, (int) $family->last_message_id);
        $this->assertSame([$bilal->id, $sara->id], $work->fresh()->activeMembers()->orderBy('user_id')->pluck('user_id')->all());

        $this->assertNull(Conversation::find($mine));
        $this->assertNotNull(Conversation::find($theirs));
        $this->assertNull(Conversation::find($list));

        $this->assertNotNull($society->fresh());
        $announcement = Conversation::query()->where('community_id', $society->id)->where('is_announcement', true)->sole();
        $this->assertSame(ConversationMember::ROLE_ADMIN, $announcement->memberFor($bilal)->role);
        $this->assertNull($alone->fresh());

        // Other people's chats are untouched.
        $this->assertNotNull($untouched->fresh());
        $this->assertSame(1, Message::query()->where('conversation_id', $untouched->id)->count());
        $this->actingAs($bilal)->getJson('/conversations')->assertOk();
    }

    /**
     * @param  list<User>  $members
     */
    private function group(User $owner, string $name, array $members): Conversation
    {
        return Conversation::findOrFail($this->actingAs($owner)->postJson('/groups', ['name' => $name, 'member_ids' => collect($members)->pluck('id')->all()])->assertCreated()->json('id'));
    }
}
