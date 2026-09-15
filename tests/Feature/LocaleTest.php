<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * X1 — English / Urdu (right-to-left) app language.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_english_and_left_to_right_by_default(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr"', false)
            ->assertDontSee('data-i18n-pending', false)
            ->assertSee('اردو');
    }

    public function test_a_visitor_can_switch_the_sign_in_pages_to_urdu(): void
    {
        $this->from(route('login'))->post(route('locale.update'), ['locale' => 'ur'])
            ->assertRedirect(route('login'))
            ->assertCookie(Locales::COOKIE, 'ur');

        $this->withCookie(Locales::COOKIE, 'ur')->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="ur" dir="rtl"', false)
            ->assertSee('data-i18n-pending', false)
            ->assertSee('English');

        $this->post(route('locale.update'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->withCookie(Locales::COOKIE, 'xx')->get(route('login'))->assertSee('<html lang="en" dir="ltr"', false);
    }

    public function test_a_signed_in_person_keeps_their_language_on_every_device(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('locale.update'), ['locale' => 'ur'])
            ->assertOk()
            ->assertJson(['locale' => 'ur', 'dir' => 'rtl']);
        $this->assertSame('ur', $user->fresh()->locale);

        // Another browser without the cookie still gets Urdu.
        $this->actingAs($user->fresh())->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('<html lang="ur" dir="rtl"', false)
            ->assertSee('"locale":"ur","dir":"rtl"', false)
            ->assertSee('data-language-select', false);

        // The account choice wins over an old cookie.
        $this->actingAs($user->fresh())->withCookie(Locales::COOKIE, 'en')->get(route('chat.index'))
            ->assertSee('<html lang="ur" dir="rtl"', false);
    }

    public function test_admin_panel_and_legal_pages_stay_in_english(): void
    {
        $admin = User::factory()->create(['locale' => 'ur']);
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('<html lang="en" dir="ltr"', false);
        $this->actingAs($admin)->get(route('legal', 'privacy'))->assertOk()->assertDontSee('dir="rtl"', false);
    }

    public function test_urdu_dictionary_is_valid(): void
    {
        $dictionary = json_decode((string) file_get_contents(lang_path('ur.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertGreaterThan(1500, count($dictionary));
        foreach ($dictionary as $english => $urdu) {
            $this->assertIsString($urdu, $english);
            $this->assertNotSame('', trim($urdu), $english);
            preg_match_all('/\{\d+\}/', $english, $expected);
            preg_match_all('/\{\d+\}/', $urdu, $used);
            $this->assertSame([], array_diff($used[0], $expected[0]), "Unknown placeholder in the translation of \"$english\"");
        }
        foreach (['Settings', 'Type a message', 'Log out', 'App language', 'online'] as $key) {
            $this->assertArrayHasKey($key, $dictionary);
        }
    }
}
