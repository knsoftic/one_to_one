<?php

namespace Tests\Feature\Chat;

use App\Models\BlockedUser;
use App\Models\BusinessProfile;
use App\Models\ChatList;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\BusinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * X8 — business profile, away and greeting messages, quick replies and labels.
 */
class BusinessToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->shop, $this->customer] = User::factory()->count(2)->create();
    }

    public function test_business_profile_is_turned_on_saved_and_shown_to_others(): void
    {
        $this->actingAs($this->shop)->get(route('profile.edit', ['tab' => 'business']))
            ->assertOk()->assertSee('Turn on business account');

        $this->actingAs($this->shop)->put(route('business.profile'), [
            'category' => 'restaurant',
            'description' => '  Karahi and BBQ  ',
            'address' => 'Liberty Market, Lahore',
            'email' => 'orders@example.com',
            'website' => 'https://example.com',
            'hours_mode' => 'custom',
            'days' => [
                'mon' => ['open' => '1', 'from' => '12:00', 'to' => '23:00'],
                'sun' => ['open' => '0', 'from' => '09:00', 'to' => '17:00'],
            ],
        ])->assertRedirect(route('profile.edit', ['tab' => 'business']))->assertSessionHas('status', 'Business account turned on.');

        $profile = BusinessProfile::sole();
        $this->assertSame('Karahi and BBQ', $profile->description);
        $this->assertTrue($profile->day('mon')['open']);
        $this->assertFalse($profile->day('sun')['open']);
        $this->assertFalse($profile->day('tue')['open']);

        Carbon::setTestNow(Carbon::parse('next monday 13:00', config('app.timezone')));
        $this->actingAs($this->customer)->getJson(route('users.business', $this->shop))
            ->assertOk()
            ->assertJsonPath('business.category_label', 'Restaurant and food')
            ->assertJsonPath('business.website', 'https://example.com')
            ->assertJsonPath('business.hours.0.day', 'Monday')
            ->assertJsonPath('business.hours.0.from', '12:00')
            ->assertJsonPath('business.open_now', true);

        Carbon::setTestNow(Carbon::parse('next monday 23:30', config('app.timezone')));
        $this->actingAs($this->customer)->getJson(route('users.business', $this->shop))->assertJsonPath('business.open_now', false);

        // Normal accounts and blocked people have no business details.
        $this->actingAs($this->shop)->getJson(route('users.business', $this->customer))->assertOk()->assertJsonPath('business', null);
        BlockedUser::query()->create(['user_id' => $this->shop->id, 'blocked_user_id' => $this->customer->id]);
        $this->actingAs($this->customer)->getJson(route('users.business', $this->shop))->assertNotFound();

        $this->actingAs($this->shop)->get(route('profile.edit', ['tab' => 'business']))
            ->assertOk()->assertSee('Automatic messages')->assertSee('Quick replies');
    }

    public function test_business_profile_is_validated(): void
    {
        $this->actingAs($this->shop)->from(route('profile.edit', ['tab' => 'business']))
            ->put(route('business.profile'), ['category' => 'casino', 'hours_mode' => 'custom', 'website' => 'javascript:alert(1)', 'days' => ['mon' => ['from' => '25:00']]])
            ->assertSessionHasErrors(['category', 'website', 'days.mon.from'], null, 'business');

        $this->actingAs($this->shop)->put(route('business.messages'), ['away_schedule' => 'always', 'away_recipients' => 'everyone', 'greeting_recipients' => 'everyone'])
            ->assertNotFound();
        $this->assertDatabaseCount('business_profiles', 0);

        $this->business(['hours' => ['mode' => 'custom', 'days' => []]]);
        $this->actingAs($this->shop)->from(route('profile.edit', ['tab' => 'business']))->put(route('business.messages'), [
            'away_enabled' => '1', 'away_message' => '', 'away_schedule' => 'outside_hours', 'away_recipients' => 'everyone',
            'greeting_enabled' => '0', 'greeting_recipients' => 'everyone',
        ])->assertSessionHasErrors(['away_message'], null, 'businessMessages');

        $this->actingAs($this->shop)->from(route('profile.edit', ['tab' => 'business']))->put(route('business.messages'), [
            'away_enabled' => '1', 'away_message' => 'Closed', 'away_schedule' => 'outside_hours', 'away_recipients' => 'everyone',
            'greeting_recipients' => 'everyone',
        ])->assertSessionHasErrors(['away_schedule'], null, 'businessMessages');

        $this->actingAs($this->shop)->from(route('profile.edit', ['tab' => 'business']))->put(route('business.messages'), [
            'away_enabled' => '1', 'away_message' => 'On holiday', 'away_schedule' => 'custom', 'away_recipients' => 'everyone',
            'away_from' => '2026-10-10 10:00', 'away_until' => '2026-10-01 10:00', 'greeting_recipients' => 'everyone',
        ])->assertSessionHasErrors(['away_until'], null, 'businessMessages');
    }

    public function test_away_message_is_sent_once_a_day_and_never_between_two_businesses(): void
    {
        $this->business(['away_enabled' => true, 'away_message' => 'We are closed, back at 9.', 'away_schedule' => 'always']);
        $chat = Conversation::factory()->between($this->shop, $this->customer)->create();

        $this->actingAs($this->customer)->postJson("/conversations/{$chat->id}/messages", ['message' => 'Hello?'])->assertCreated();

        $reply = Message::query()->where('sender_id', $this->shop->id)->sole();
        $this->assertSame('We are closed, back at 9.', $reply->message);
        $this->assertSame('away', $reply->attachment_meta['auto_reply']);
        $this->actingAs($this->customer)->getJson("/conversations/{$chat->id}/messages")
            ->assertJsonFragment(['auto_reply' => 'away']);

        // A second message the same day gets no second away message.
        $this->actingAs($this->customer)->postJson("/conversations/{$chat->id}/messages", ['message' => 'Anyone there?'])->assertCreated();
        $this->assertSame(1, Message::query()->where('sender_id', $this->shop->id)->count());

        $this->travel(25)->hours();
        $this->assertSame('away', app(BusinessService::class)->autoReplyTo($this->incoming($chat, 'Tomorrow then')));

        // Two businesses with away messages don't answer each other forever.
        $other = User::factory()->create();
        BusinessProfile::query()->create(['user_id' => $other->id, 'category' => 'shop', 'away_enabled' => true, 'away_message' => 'Away too', 'away_schedule' => 'always']);
        $between = Conversation::factory()->between($this->shop, $other)->create();
        $this->actingAs($other)->postJson("/conversations/{$between->id}/messages", ['message' => 'Hi shop'])->assertCreated();
        $this->assertSame(1, $between->messages()->where('sender_id', $this->shop->id)->count());
        $this->assertSame(1, $between->messages()->where('sender_id', $other->id)->count());
    }

    public function test_away_message_follows_business_hours_custom_time_and_recipients(): void
    {
        $days = collect(BusinessProfile::DAYS)->map(fn () => ['open' => true, 'from' => '09:00', 'to' => '17:00'])->all();
        $profile = $this->business(['hours' => ['mode' => 'custom', 'days' => $days], 'away_enabled' => true, 'away_message' => 'Closed now', 'away_schedule' => 'outside_hours']);
        $chat = Conversation::factory()->between($this->shop, $this->customer)->create();
        $service = app(BusinessService::class);

        Carbon::setTestNow(Carbon::parse('2026-10-05 11:00', config('app.timezone')));
        $this->assertNull($service->autoReplyTo($this->incoming($chat, 'Open?')));

        Carbon::setTestNow(Carbon::parse('2026-10-05 20:00', config('app.timezone')));
        $this->assertSame('away', $service->autoReplyTo($this->incoming($chat, 'Open now?')));

        // Custom time: only between the dates.
        $profile->update(['away_schedule' => 'custom', 'away_from' => '2026-10-20 00:00', 'away_until' => '2026-10-25 00:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-15 20:00', config('app.timezone')));
        $this->assertNull($service->autoReplyTo($this->incoming($chat, 'Hello')));
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00', config('app.timezone')));
        $this->assertSame('away', $service->autoReplyTo($this->incoming($chat, 'Hello again')));

        // "People not in my contacts": saved contacts get nothing.
        $profile->update(['away_schedule' => 'always', 'away_recipients' => 'not_contacts']);
        Contact::query()->create(['user_id' => $this->shop->id, 'contact_user_id' => $this->customer->id, 'name' => 'Regular', 'phone' => (string) $this->customer->phone]);
        $this->travel(2)->days();
        $this->assertNull($service->autoReplyTo($this->incoming($chat, 'It is me')));
    }

    public function test_greeting_welcomes_new_customers_only(): void
    {
        $this->business(['greeting_enabled' => true, 'greeting_message' => 'Welcome! How can we help?']);
        $chat = Conversation::factory()->between($this->shop, $this->customer)->create();

        $this->actingAs($this->customer)->postJson("/conversations/{$chat->id}/messages", ['message' => 'Salam'])->assertCreated();
        $greeting = Message::query()->where('sender_id', $this->shop->id)->sole();
        $this->assertSame('greeting', $greeting->attachment_meta['auto_reply']);

        $service = app(BusinessService::class);
        $this->assertNull($service->autoReplyTo($this->incoming($chat, 'Price of karahi?')));

        // After a long quiet time the person is greeted again.
        $this->travel(BusinessProfile::GREETING_AFTER_DAYS + 1)->days();
        $this->assertSame('greeting', $service->autoReplyTo($this->incoming($chat, 'Back again')));

        // Blocked people and an off switch get nothing.
        $this->travel(BusinessProfile::GREETING_AFTER_DAYS + 1)->days();
        BlockedUser::query()->create(['user_id' => $this->shop->id, 'blocked_user_id' => $this->customer->id]);
        $this->assertNull($service->autoReplyTo($this->incoming($chat, 'Blocked?')));
    }

    public function test_quick_replies_are_private_and_validated(): void
    {
        $this->actingAs($this->shop)->postJson(route('quick-replies.store'), ['shortcut' => '/Thanks', 'message' => 'Thank you for your order!'])
            ->assertCreated()->assertJsonPath('shortcut', 'thanks');

        $this->actingAs($this->shop)->postJson(route('quick-replies.store'), ['shortcut' => 'thanks', 'message' => 'Again'])
            ->assertUnprocessable()->assertJsonValidationErrors('shortcut');
        $this->actingAs($this->shop)->postJson(route('quick-replies.store'), ['shortcut' => 'two words', 'message' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('shortcut');

        // Another person may use the same shortcut; nobody sees or edits someone else's.
        $this->actingAs($this->customer)->postJson(route('quick-replies.store'), ['shortcut' => 'thanks', 'message' => 'Mine'])->assertCreated();
        $reply = QuickReply::query()->where('user_id', $this->shop->id)->sole();
        $this->actingAs($this->customer)->getJson(route('quick-replies.index'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'Mine');
        $this->actingAs($this->customer)->putJson(route('quick-replies.update', $reply), ['shortcut' => 'hack', 'message' => 'x'])->assertNotFound();
        $this->actingAs($this->customer)->deleteJson(route('quick-replies.destroy', $reply))->assertNotFound();

        $this->actingAs($this->shop)->put(route('quick-replies.update', $reply), ['shortcut' => 'ty', 'message' => 'Thanks a lot'])
            ->assertRedirect(route('profile.edit', ['tab' => 'business']));
        $this->assertSame('ty', $reply->fresh()->shortcut);

        $this->actingAs($this->shop)->delete(route('quick-replies.destroy', $reply))->assertRedirect();
        $this->assertModelMissing($reply);
    }

    public function test_turning_business_off_keeps_quick_replies_and_chat_lists_take_a_label_colour(): void
    {
        $this->business();
        $this->shop->quickReplies()->create(['shortcut' => 'hi', 'message' => 'Hello']);
        $this->actingAs($this->shop)->delete(route('business.destroy'))->assertRedirect();
        $this->assertDatabaseCount('business_profiles', 0);
        $this->assertDatabaseCount('quick_replies', 1);

        $list = $this->actingAs($this->shop)->postJson('/chat-lists', ['name' => 'Paid', 'color' => 'green'])
            ->assertCreated()->assertJsonPath('color', 'green')->json();
        $this->actingAs($this->shop)->patchJson("/chat-lists/{$list['id']}", ['color' => 'purple-ish'])->assertUnprocessable();
        $this->actingAs($this->shop)->patchJson("/chat-lists/{$list['id']}", ['color' => null])->assertOk()->assertJsonPath('color', null);
        $this->assertNull(ChatList::sole()->color);
    }

    /** @param  array<string, mixed>  $attributes */
    private function business(array $attributes = []): BusinessProfile
    {
        return BusinessProfile::query()->create(['user_id' => $this->shop->id, 'category' => 'shop'] + $attributes);
    }

    private function incoming(Conversation $chat, string $text): Message
    {
        return Message::query()->create([
            'conversation_id' => $chat->id,
            'sender_id' => $this->customer->id,
            'receiver_id' => $this->shop->id,
            'message' => $text,
            'message_type' => Message::TYPE_TEXT,
            'sent_at' => now(),
        ]);
    }
}
