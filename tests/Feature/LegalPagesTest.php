<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * X5 — pages Google Play asks for, filled from App settings.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_legal_pages_open_for_everyone_and_are_linked(): void
    {
        foreach (['/privacy' => 'Privacy policy', '/terms' => 'Terms of service', '/child-safety' => 'Child safety standards', '/delete-account' => 'Delete your account'] as $url => $title) {
            $this->get($url)->assertOk()->assertSee($title);
        }

        $this->get('/privacy')->assertSee('not end-to-end encrypted', false);
        $this->get('/nope-page')->assertNotFound();
        $this->get('/login')->assertSee(route('legal', 'privacy'))->assertSee(route('legal', 'delete-account'));
        $this->get('/register')->assertSee('By creating an account you agree to the');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings')->assertSee(route('legal', 'child-safety'));
        $this->get('/delete-account')->assertSee('Open Settings → Account');
    }

    public function test_admin_sets_the_operator_and_contact_email(): void
    {
        Cache::flush();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('Legal pages');
        $this->put('/admin/settings', ['registration_open' => 1, 'legal_owner' => 'Hunario Pvt Ltd', 'legal_email' => 'safety@hunario.com', 'legal_country' => 'Pakistan', 'legal_updated' => '1 October 2026'])
            ->assertSessionHasNoErrors();

        $this->get('/child-safety')->assertSee('Hunario Pvt Ltd')->assertSee('safety@hunario.com')->assertSee('Last updated 1 October 2026');
        $this->put('/admin/settings', ['legal_email' => 'not-an-email'])->assertSessionHasErrors('legal_email');
    }
}
