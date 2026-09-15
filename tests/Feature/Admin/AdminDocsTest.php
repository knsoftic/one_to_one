<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\DocsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin → Guides: docs/PLAY-STORE.md and the other guides, live from the code.
 */
class AdminDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_the_index_of_guides(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.docs'))
            ->assertOk()
            ->assertSee('Google Play release guide')
            ->assertSee('Server deployment (aaPanel)')
            ->assertSee(route('admin.docs.show', 'play-store'))
            ->assertSee('Create the upload key');

        $this->actingAs($admin)->get(route('admin.settings'))->assertSee(route('admin.docs.show', 'play-store'));
    }

    public function test_the_play_store_guide_is_shown_with_its_table_of_contents(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.docs.show', 'play-store'))
            ->assertOk()
            ->assertSee('On this page')
            ->assertSee('href="#2-create-the-upload-key-you-once"', false)
            ->assertSee('<h2 id="2-create-the-upload-key-you-once">', false)
            ->assertSee('<div class="doc-table"><table>', false)
            ->assertSee('keytool -genkeypair', false)
            ->assertDontSee('<h1>', false);

        $this->actingAs($admin)->get(route('admin.docs.download', 'play-store'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertDownload('PLAY-STORE.md');

        $this->actingAs($admin)->get('/admin/docs/env')->assertNotFound();
        $this->actingAs($admin)->get('/admin/docs/..%2F.env')->assertNotFound();
    }

    public function test_only_admins_can_read_the_guides(): void
    {
        $this->get(route('admin.docs'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.docs.show', 'play-store'))->assertForbidden();
    }

    public function test_markdown_html_is_escaped_and_headings_get_unique_anchors(): void
    {
        $page = app(DocsService::class)->render("# Title\n\n## Setup\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1)) [site](https://example.com)\n\n## Setup\n\n### Step `one`");

        $this->assertStringNotContainsString('<script>', $page['html']);
        $this->assertStringNotContainsString('javascript:', $page['html']);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $page['html']);
        $this->assertSame(['setup', 'setup-2', 'step-one'], array_column($page['toc'], 'id'));
        $this->assertSame([2, 2, 3], array_column($page['toc'], 'level'));
    }
}
