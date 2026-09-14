<?php

namespace Tests\Feature\Account;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A2 — Change number.
 */
class ChangeNumberTest extends TestCase
{
    use FakesSms;
    use RefreshDatabase;

    private const SETTINGS = '/settings?tab=account#change-number';

    public function test_the_new_number_is_confirmed_by_sms_and_chats_stay(): void
    {
        $sms = $this->fakeSms();
        $user = User::factory()->create(['phone' => '+923001234567', 'password' => 'Password1']);
        $friend = User::factory()->create();
        $chat = Conversation::factory()->between($user, $friend)->create();
        Message::factory()->inConversation($chat, $friend)->create();

        $this->actingAs($user)->get('/settings?tab=account')->assertOk()->assertSee('Change number')->assertSee('+923001234567')->assertSee('Send code');

        $this->actingAs($user)->post('/settings/phone', ['phone' => '+92 321 7654321', 'current_password' => 'Password1'])
            ->assertRedirect(self::SETTINGS)
            ->assertSessionHas('status', 'We sent a 6-digit code to +923217654321.');
        $this->assertSame('+923217654321', $sms->sent[0]['to']);
        $this->assertStringContainsString('code to change your number', $sms->sent[0]['message']);
        $this->assertSame('+923001234567', $user->fresh()->phone, 'Nothing changes before the code.');

        $this->actingAs($user)->get('/settings')->assertOk()->assertSee('Enter the 6-digit code we sent to')->assertSee('+923217654321');

        $code = $sms->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->actingAs($user)->post('/settings/phone/code', ['code' => $wrong])->assertSessionHasErrors(['code' => 'That code is not correct.'], null, 'phone');

        $this->actingAs($user)->post('/settings/phone/code', ['code' => $code])
            ->assertRedirect(self::SETTINGS)
            ->assertSessionHas('status', 'Your number has been changed to +923217654321. Your chats, groups and contacts stay the same.');

        $user->refresh();
        $this->assertSame('+923217654321', $user->phone);
        $this->assertSame('217654321', $user->phone_suffix);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(1, $user->conversations()->count());
        $this->assertSame(1, Message::query()->where('conversation_id', $chat->id)->count());
        $this->actingAs($user)->get('/settings')->assertDontSee('Enter the 6-digit code we sent to')->assertSee('Verified by SMS');

        // The code can't be used again.
        $this->actingAs($user)->post('/settings/phone/code', ['code' => $code])->assertSessionHas('status', 'Enter your new number again.');
    }

    public function test_the_password_and_a_free_number_are_needed(): void
    {
        $this->fakeSms();
        $user = User::factory()->create(['phone' => '+923001234567', 'password' => 'Password1']);
        User::factory()->create(['phone' => '+923339876543']);

        $this->actingAs($user)->post('/settings/phone', ['phone' => '+923217654321', 'current_password' => 'wrong'])
            ->assertSessionHasErrors(['current_password'], null, 'phone');
        $this->actingAs($user)->post('/settings/phone', ['phone' => '', 'current_password' => 'Password1'])
            ->assertSessionHasErrors(['phone'], null, 'phone');
        $this->actingAs($user)->post('/settings/phone', ['phone' => '+923001234567', 'current_password' => 'Password1'])
            ->assertSessionHasErrors(['phone' => 'This is already your number.'], null, 'phone');
        // Someone else's number, written another way.
        $this->actingAs($user)->post('/settings/phone', ['phone' => '0092 333 9876543', 'current_password' => 'Password1'])
            ->assertSessionHasErrors(['phone' => 'This number is already used by another account.'], null, 'phone');
        $this->actingAs($user)->post('/settings/phone', ['phone' => 'abc', 'current_password' => 'Password1'])
            ->assertSessionHasErrors(['phone'], null, 'phone');

        $this->actingAs($user)->get('/settings')->assertOk();
        $this->assertSame('+923001234567', $user->fresh()->phone);
    }

    public function test_sending_again_cancelling_and_a_number_taken_meanwhile(): void
    {
        $sms = $this->fakeSms();
        $user = User::factory()->create(['phone' => '+923001234567', 'password' => 'Password1']);

        $this->actingAs($user)->post('/settings/phone', ['phone' => '+923217654321', 'current_password' => 'Password1']);
        $this->actingAs($user)->post('/settings/phone/resend')->assertSessionHasErrors(['code'], null, 'phone');
        $this->travel(61)->seconds();
        $this->actingAs($user)->post('/settings/phone/resend')->assertSessionHas('status', 'We sent a new code to +923217654321.');
        $this->assertCount(2, $sms->sent);

        $this->actingAs($user)->delete('/settings/phone')->assertSessionHas('status', 'Your number was not changed.');
        $this->actingAs($user)->get('/settings')->assertDontSee('Enter the 6-digit code we sent to');

        // Another account takes the number before the code is typed.
        $this->travel(61)->seconds();
        $this->actingAs($user)->post('/settings/phone', ['phone' => '+923217654321', 'current_password' => 'Password1']);
        User::factory()->create(['phone' => '+923217654321']);
        $this->actingAs($user)->post('/settings/phone/code', ['code' => $sms->lastCode()])
            ->assertSessionHasErrors(['phone' => 'This number is already used by another account.'], null, 'phone');
        $this->assertSame('+923001234567', $user->fresh()->phone);
    }

    public function test_without_sms_the_number_changes_with_the_password(): void
    {
        config(['services.sms.driver' => 'twilio', 'services.sms.twilio.sid' => null]);
        $user = User::factory()->create(['phone' => '+923001234567', 'phone_verified_at' => now(), 'password' => 'Password1']);

        $this->actingAs($user)->get('/settings?tab=account')->assertSee('Change number')->assertDontSee('Send code');
        $this->actingAs($user)->post('/settings/phone', ['phone' => '+923217654321', 'current_password' => 'Password1'])
            ->assertSessionHas('status', 'Your number has been changed to +923217654321.');

        $this->assertSame('+923217654321', $user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_the_profile_form_no_longer_changes_the_number(): void
    {
        $user = User::factory()->create(['phone' => '+923001234567']);

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'phone' => '+15550109999',
        ])->assertSessionHasNoErrors();

        $this->assertSame('+923001234567', $user->fresh()->phone);
    }
}
