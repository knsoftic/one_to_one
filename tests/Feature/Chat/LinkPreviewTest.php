<?php

namespace Tests\Feature\Chat;

use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Models\User;
use App\Services\LinkPreviewService;
use App\Support\SafeFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeWeb;
use Tests\TestCase;

/**
 * M11 — Link previews (title, description, site and image under a link).
 */
class LinkPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const ARTICLE = 'https://news.example.com/article';

    private User $me;

    private User $friend;

    private Conversation $conversation;

    private FakeWeb $web;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('chat');

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();

        $this->web = (new FakeWeb)
            ->host('news.example.com', '93.184.216.34')
            ->host('cdn.example.com', '151.101.1.1')
            ->page(self::ARTICLE, '
                <meta charset="utf-8">
                <title>Fallback title</title>
                <meta property="og:title" content="Rain expected &amp; cooler weekend">
                <meta property="og:description" content="Forecast for Lahore and Karachi.">
                <meta property="og:site_name" content="Example News">
                <meta property="og:image" content="//cdn.example.com/rain.png">')
            ->respond('https://cdn.example.com/rain.png', FakeWeb::png(640, 360), 'image/png');

        $this->app->instance(SafeFetcher::class, $this->web);
    }

    public function test_composer_preview_is_fetched_once_and_cached_with_a_safe_image(): void
    {
        $this->actingAs($this->me)->postJson('/link-preview', ['url' => self::ARTICLE])
            ->assertOk()
            ->assertJsonPath('data.url', self::ARTICLE)
            ->assertJsonPath('data.title', 'Rain expected & cooler weekend')
            ->assertJsonPath('data.description', 'Forecast for Lahore and Karachi.')
            ->assertJsonPath('data.site_name', 'Example News')
            ->assertJsonPath('data.domain', 'news.example.com')
            ->assertJsonPath('data.image_width', 480)
            ->assertJsonPath('data.image_height', 270);

        $this->actingAs($this->friend)->postJson('/link-preview', ['url' => self::ARTICLE])->assertJsonPath('data.title', 'Rain expected & cooler weekend');
        $this->assertCount(2, $this->web->requests, 'page and image are fetched only once');

        $preview = LinkPreview::sole();
        Storage::disk('chat')->assertExists($preview->image);

        $this->actingAs($this->friend)->get("/link-previews/{$preview->id}/image")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_cached_preview_is_included_when_sending_and_kept_when_forwarding(): void
    {
        $this->app->make(LinkPreviewService::class)->preview(self::ARTICLE);

        $sent = $this->send('Dekho: '.self::ARTICLE.' kal barish')
            ->assertCreated()
            ->assertJsonPath('link_preview.title', 'Rain expected & cooler weekend');

        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.0.link_preview.site_name', 'Example News');

        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();
        $this->actingAs($this->me)->postJson("/messages/{$sent->json('id')}/forward", ['conversation_ids' => [$other->id]])
            ->assertCreated()
            ->assertJsonPath('data.0.link_preview.title', 'Rain expected & cooler weekend');
    }

    public function test_an_uncached_link_is_previewed_after_sending_and_broadcast(): void
    {
        Event::fake([MessageUpdated::class]);

        $response = $this->send('Read '.self::ARTICLE)->assertCreated()->assertJsonPath('link_preview', null);

        $message = Message::with('linkPreview')->find($response->json('id'));
        $this->assertSame('Rain expected & cooler weekend', $message->linkPreview?->title);

        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event) => $event->message->is($message)
            && $event->broadcastWith()['message']['link_preview']['title'] === 'Rain expected & cooler weekend');
    }

    public function test_removed_previews_private_links_and_links_in_code_are_not_fetched(): void
    {
        $this->send('No card please '.self::ARTICLE, ['link_preview' => false])->assertCreated()->assertJsonPath('link_preview', null);
        $this->send('Router: http://192.168.1.1/admin')->assertCreated();
        $this->send('Run `curl '.self::ARTICLE.'`')->assertCreated();

        $this->actingAs($this->me)->postJson('/link-preview', ['url' => 'http://localhost/secret'])->assertOk()->assertJsonPath('data', null);
        $this->actingAs($this->me)->postJson('/link-preview', ['url' => 'http://169.254.169.254/latest/meta-data/'])->assertJsonPath('data', null);

        $this->assertSame([], $this->web->requests);
        $this->assertSame(0, Message::whereNotNull('link_preview_id')->count());
    }

    public function test_pages_without_details_are_retried_only_after_an_hour(): void
    {
        $this->web->page('https://news.example.com/empty', '<meta charset="utf-8">');

        $link = ['url' => 'https://news.example.com/empty'];
        $this->actingAs($this->me)->postJson('/link-preview', $link)->assertJsonPath('data', null);
        $this->actingAs($this->me)->postJson('/link-preview', $link)->assertJsonPath('data', null);
        $this->assertCount(1, $this->web->requests);
        $this->assertTrue(LinkPreview::sole()->failed);

        $this->travel(61)->minutes();
        $this->actingAs($this->me)->postJson('/link-preview', $link);
        $this->assertCount(2, $this->web->requests);
    }

    public function test_editing_to_another_link_replaces_the_preview_and_deleting_removes_it(): void
    {
        $this->web->page('https://news.example.com/sports', '<title>Cricket score</title>');
        $service = $this->app->make(LinkPreviewService::class);
        $service->preview(self::ARTICLE);
        $service->preview('https://news.example.com/sports');

        $id = $this->send('Look '.self::ARTICLE)->json('id');

        $this->actingAs($this->me)->patchJson("/messages/{$id}", ['message' => 'Sorry, this one: https://news.example.com/sports'])
            ->assertOk()
            ->assertJsonPath('link_preview.title', 'Cricket score');

        $this->actingAs($this->me)->patchJson("/messages/{$id}", ['message' => 'No link now'])
            ->assertJsonPath('link_preview', null);

        $this->actingAs($this->me)->patchJson("/messages/{$id}", ['message' => 'Back: '.self::ARTICLE]);
        $this->actingAs($this->me)->deleteJson("/messages/{$id}", ['scope' => 'everyone'])
            ->assertOk()
            ->assertJsonPath('message.link_preview', null);

        $this->assertNull(Message::find($id)->link_preview_id);
    }

    public function test_page_metadata_falls_back_to_plain_html_and_other_charsets(): void
    {
        $service = $this->app->make(LinkPreviewService::class);
        $html = mb_convert_encoding('<html><head><title>  Café   menu </title><meta name="description" content="Crème brûlée"><link rel="image_src" href="/img/menu.jpg"></head></html>', 'ISO-8859-1', 'UTF-8');

        $this->assertSame(
            ['title' => 'Café menu', 'description' => 'Crème brûlée', 'site_name' => null, 'image' => '/img/menu.jpg'],
            $service->parse($html, 'text/html; charset=ISO-8859-1'),
        );
    }

    public function test_unused_previews_and_their_images_are_pruned(): void
    {
        $service = $this->app->make(LinkPreviewService::class);
        $unused = $service->preview(self::ARTICLE);
        $this->web->page('https://news.example.com/sports', '<title>Cricket score</title>');
        $used = $service->preview('https://news.example.com/sports');
        Message::factory()->inConversation($this->conversation, $this->me)->create(['link_preview_id' => $used->id]);

        $this->travel(31)->days();
        $this->artisan('model:prune', ['--model' => [LinkPreview::class]])->assertSuccessful();

        $this->assertModelMissing($unused);
        $this->assertModelExists($used);
        Storage::disk('chat')->assertMissing($unused->image);
    }

    private function send(string $text, array $extra = [])
    {
        return $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/messages", ['message' => $text] + $extra);
    }
}
