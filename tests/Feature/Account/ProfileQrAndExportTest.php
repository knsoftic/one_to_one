<?php

namespace Tests\Feature\Account;

use App\Models\BlockedUser;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A4 — Profile QR code, A5 — Download my account data.
 */
class ProfileQrAndExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_qr_code_opens_a_chat_and_can_be_reset(): void
    {
        $ayesha = User::factory()->create(['name' => 'Ayesha Khan', 'about' => 'Hey there']);
        $bilal = User::factory()->create();

        $this->assertNull($ayesha->qr_token);
        $code = $this->actingAs($ayesha)->getJson('/settings/qr')->assertOk()->json();
        $token = $ayesha->fresh()->qr_token;
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $token);
        $this->assertSame(url("/u/{$token}"), $code['url']);
        $this->actingAs($ayesha)->getJson('/settings/qr')->assertJsonPath('token', $token);
        $this->actingAs($ayesha)->get('/settings?tab=account')->assertSee("/u/{$token}")->assertSee('Reset QR code');

        // Bilal scans it.
        $this->actingAs($bilal)->get("/u/{$token}")->assertOk()->assertSee('"profileQr":{"token":"'.$token.'"}', false);
        $this->actingAs($bilal)->postJson('/qr/lookup', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('user.id', $ayesha->id)
            ->assertJsonPath('user.name', 'Ayesha Khan')
            ->assertJsonPath('user.about', 'Hey there')
            ->assertJsonPath('self', false)
            ->assertJsonMissingPath('user.email')
            ->assertJsonMissingPath('user.phone');
        $this->actingAs($ayesha)->postJson('/qr/lookup', ['token' => $token])->assertJsonPath('self', true);

        // Guests sign in first and come back.
        $this->post('/logout');
        $this->get("/u/{$token}")->assertRedirect('/login');

        // Reset: the old code stops working.
        $new = $this->actingAs($ayesha)->postJson('/settings/qr/reset')->assertOk()->json('token');
        $this->assertNotSame($token, $new);
        $this->actingAs($bilal)->postJson('/qr/lookup', ['token' => $token])->assertNotFound()->assertJsonPath('message', "This QR code isn't valid anymore. Ask for a new one.");
        $this->actingAs($bilal)->postJson('/qr/lookup', ['token' => 'short'])->assertUnprocessable();

        // Blocked by the owner, or the owner is suspended: the code looks old.
        BlockedUser::create(['user_id' => $ayesha->id, 'blocked_user_id' => $bilal->id]);
        $this->actingAs($bilal)->postJson('/qr/lookup', ['token' => $new])->assertNotFound();
        BlockedUser::query()->delete();
        $ayesha->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($bilal)->postJson('/qr/lookup', ['token' => $new])->assertNotFound();

        // Reset from the settings page.
        $this->actingAs($bilal)->post('/settings/qr/reset')->assertRedirect('/settings?tab=account#qr-code');
    }

    public function test_the_account_report_has_settings_and_memberships_but_no_messages(): void
    {
        $user = User::factory()->create(['name' => 'Ayesha Khan', 'username' => 'ayesha', 'phone' => '+923001234567', 'last_seen_privacy' => 'contacts']);
        $bilal = User::factory()->create(['name' => 'Bilal']);
        $sara = User::factory()->create(['name' => 'Sara']);
        Contact::create(['user_id' => $user->id, 'contact_user_id' => $bilal->id, 'name' => 'Bilal Cousin', 'phone' => '+923339876543']);
        BlockedUser::create(['user_id' => $user->id, 'blocked_user_id' => $sara->id]);
        $chat = Conversation::factory()->between($user, $bilal)->create();
        $secret = Message::factory()->inConversation($chat, $user)->create(['message' => 'the secret recipe']);
        $chat->forceFill(['last_message_id' => $secret->id])->save();
        $this->actingAs($user)->postJson('/groups', ['name' => 'Family', 'member_ids' => [$bilal->id]])->assertCreated();
        $this->actingAs($user)->postJson('/channels', ['name' => 'Recipes'])->assertCreated();

        $json = $this->actingAs($user)->get('/settings/export?format=json')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="account-ayesha-'.now()->format('Y-m-d').'.json"');
        $data = json_decode($json->getContent(), true);

        $this->assertSame('Ayesha Khan', $data['account']['name']);
        $this->assertSame('+923001234567', $data['account']['mobile_number']);
        $this->assertSame('contacts', $data['settings']['privacy']['last_seen']);
        $this->assertSame([['name' => 'Bilal Cousin', 'mobile_number' => '+923339876543', 'username' => $bilal->username]], $data['contacts']);
        $this->assertSame('Sara', $data['blocked'][0]['name']);
        $this->assertSame(['name' => 'Family', 'role' => 'admin', 'members' => 2, 'community' => null], array_diff_key($data['groups'][0], ['joined_at' => 1]));
        $this->assertSame('owner', $data['channels'][0]['role']);
        $this->assertSame(1, $data['activity']['one_to_one_chats']);
        $this->assertGreaterThanOrEqual(1, $data['activity']['messages_sent']);
        $this->assertStringNotContainsString('the secret recipe', $json->getContent());
        $this->assertStringNotContainsString($user->password, $json->getContent());

        $html = $this->actingAs($user)->get('/settings/export')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="account-ayesha-'.now()->format('Y-m-d').'.html"')
            ->assertSee('Account report')
            ->assertSee('@ayesha')
            ->assertSee('Bilal Cousin')
            ->assertSee('Family')
            ->getContent();
        $this->assertStringNotContainsString('the secret recipe', $html);

        $this->get('/settings/export')->assertOk();
        foreach (range(1, 8) as $i) {
            $this->actingAs($user)->get('/settings/export?format=json');
        }
        $this->actingAs($user)->get('/settings/export')->assertStatus(429);
    }
}
