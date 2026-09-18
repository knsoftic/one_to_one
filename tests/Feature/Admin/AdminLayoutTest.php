<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin panel layout: people search in the top bar, headline numbers with trends,
 * a person's numbers grouped by topic, and the App settings section menu.
 */
class AdminLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_page_has_the_people_search_and_the_sidebar_footer(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.reports'))->assertOk()
            ->assertSee('class="admin-topbar-search"', false)
            ->assertSee('action="'.route('admin.users').'"', false)
            ->assertSee('data-admin-search', false)
            ->assertSee('class="admin-sidebar-foot"', false)
            ->assertSee(route('admin.docs'), false);

        // On the people list the search keeps what was typed.
        $this->actingAs($admin)->get(route('admin.users', ['q' => 'awais']))->assertOk()->assertSee('name="q" value="awais"', false);
    }

    public function test_the_dashboard_leads_with_four_numbers_and_their_trend(): void
    {
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(4, substr_count($response->getContent(), 'class="kpi-card'));
        $this->assertSame(8, substr_count($response->getContent(), 'class="glance-item"'));
        $response->assertSee('class="kpi-spark"', false)
            ->assertSee('aria-label="Messages per day, last 30 days"', false)
            ->assertSeeInOrder(['Users', 'Online now', 'Messages', 'Open reports', 'Chats', 'Groups']);
    }

    public function test_a_persons_numbers_are_grouped_and_link_to_their_tabs(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.users.show', $user))->assertOk()
            ->assertSee('In numbers')
            ->assertSeeInOrder(['Messages', 'Chats &amp; groups', 'Calls', 'People &amp; devices'], false)
            ->assertSee('class="admin-number"', false)
            ->assertSee('href="'.route('admin.users.show', ['user' => $user, 'tab' => 'calls']).'"', false);
    }

    public function test_app_settings_has_a_section_menu_in_page_order(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.settings'))->assertOk()
            ->assertSee('data-settings-nav', false)
            ->assertSee('data-settings-form', false)
            ->assertSee('id="notice"', false)
            ->assertSee('data-settings-save', false);

        $html = $response->getContent();
        $order = ['brand', 'signup', 'sms', 'email', 'extras', 'ads', 'paid', 'legal', 'notice', 'turn', 'android', 'tests'];
        // Every menu link has its section, and the sections come in the menu's order.
        preg_match_all('/<a href="#([a-z]+)" class="admin-tab">/', $html, $links);
        $this->assertSame($order, $links[1]);
        $positions = array_map(fn ($id) => strpos($html, 'id="'.$id.'"'), $order);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }
}
