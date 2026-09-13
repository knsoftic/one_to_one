<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Sticker;
use App\Models\User;
use App\Support\SafeFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeWeb;
use Tests\TestCase;

/**
 * M17 — GIFs (files and optional GIF search) and personal stickers.
 */
class StickerAndGifTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('chat');
        Http::preventStrayRequests();

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    /* ------------------------------------------------------------------ */
    /* Stickers */
    /* ------------------------------------------------------------------ */

    public function test_a_photo_becomes_a_transparent_512px_sticker_in_my_stickers(): void
    {
        $sticker = $this->actingAs($this->me)
            ->post('/stickers', ['sticker' => UploadedFile::fake()->image('cat.png', 900, 600)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json();

        $stored = Sticker::sole();
        $info = getimagesize(Storage::disk('chat')->path($stored->path));
        $this->assertSame([512, 512, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);

        $this->actingAs($this->me)->getJson('/stickers')->assertExactJson(['data' => [$sticker]]);
        $this->actingAs($this->me)->get($sticker['url'])->assertOk()->assertHeader('Content-Type', 'image/webp');

        // Other people cannot see or delete it.
        $this->actingAs($this->friend)->get($sticker['url'])->assertNotFound();
        $this->actingAs($this->friend)->deleteJson("/stickers/{$stored->id}")->assertNotFound();

        // The same image is not stored twice.
        $this->actingAs($this->me)->post('/stickers', ['sticker' => UploadedFile::fake()->image('cat.png', 900, 600)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('id', $stored->id);
    }

    public function test_sending_a_sticker_copies_it_and_the_other_person_can_save_it(): void
    {
        $sticker = $this->makeSticker($this->me);

        $response = $this->send(['sticker_id' => $sticker->id])
            ->assertCreated()
            ->assertJsonPath('type', 'sticker')
            ->assertJsonPath('attachment.mime', 'image/webp')
            ->assertJsonPath('attachment.width', 512);

        $message = Message::sole();
        $this->assertNotSame($sticker->path, $message->attachment);
        $this->assertSame('💟 Sticker', $message->preview());

        // Deleting my sticker keeps the sent message intact.
        $this->actingAs($this->me)->deleteJson("/stickers/{$sticker->id}")->assertOk();
        Storage::disk('chat')->assertExists($message->attachment);

        $this->actingAs($this->friend)->postJson("/messages/{$response->json('id')}/sticker")->assertCreated();
        $this->assertSame(1, $this->friend->stickers()->count());
    }

    public function test_stickers_of_other_people_and_non_sticker_messages_are_refused(): void
    {
        $theirs = $this->makeSticker($this->friend);
        $this->send(['sticker_id' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrors('sticker_id');

        $photo = $this->send(['attachment' => UploadedFile::fake()->image('p.jpg')])->json('id');
        $this->actingAs($this->friend)->postJson("/messages/{$photo}/sticker")->assertUnprocessable();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson("/messages/{$photo}/sticker")->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* GIFs */
    /* ------------------------------------------------------------------ */

    public function test_gif_files_are_stored_unchanged_so_they_stay_animated(): void
    {
        $gif = self::gif();
        $path = tempnam(sys_get_temp_dir(), 'gif');
        file_put_contents($path, $gif);

        $this->send(['attachment' => new UploadedFile($path, 'dance.gif', null, null, true)])
            ->assertCreated()
            ->assertJsonPath('type', 'image')
            ->assertJsonPath('attachment.mime', 'image/gif')
            ->assertJsonPath('attachment.animated', true)
            ->assertJsonPath('attachment.thumbnail_url', null);

        $message = Message::sole();
        $this->assertSame($gif, Storage::disk('chat')->get($message->attachment));
        $this->assertSame('👾 GIF', $message->preview());
    }

    public function test_gif_search_is_off_without_a_key(): void
    {
        config(['chat.gifs.tenor_key' => null]);

        $this->actingAs($this->me)->getJson('/gifs?q=hello')->assertNotFound();
        $this->actingAs($this->me)->get('/chat')->assertOk()->assertDontSee('"gifs":', false);
    }

    public function test_gif_search_results_and_sending_a_gif_through_the_server(): void
    {
        config(['chat.gifs.tenor_key' => 'test-key']);

        Http::fake([
            'tenor.googleapis.com/v2/search*' => Http::response(['results' => [
                ['id' => '123', 'content_description' => 'Happy dance', 'media_formats' => ['tinygif' => ['url' => 'https://media.tenor.com/abc/tiny.gif', 'dims' => [220, 160]]]],
                ['id' => '666', 'media_formats' => ['tinygif' => ['url' => 'https://evil.example/x.gif']]],
            ], 'next' => 'CAgQ']),
            'tenor.googleapis.com/v2/posts*' => Http::response(['results' => [
                ['id' => '123', 'media_formats' => ['gif' => ['url' => 'https://media.tenor.com/abc/full.gif']]],
            ]]),
        ]);

        $web = (new FakeWeb)->host('media.tenor.com', '142.250.1.1')->respond('https://media.tenor.com/abc/full.gif', self::gif(), 'image/gif');
        $this->app->instance(SafeFetcher::class, $web);

        $this->actingAs($this->me)->getJson('/gifs?q=dance')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.id', '123')
            ->assertJsonPath('results.0.preview_url', 'https://media.tenor.com/abc/tiny.gif')
            ->assertJsonPath('next', 'CAgQ');

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'key=test-key') && str_contains($request->url(), 'q=dance'));

        $this->send(['gif_id' => '123', 'message' => 'yay'])
            ->assertCreated()
            ->assertJsonPath('type', 'image')
            ->assertJsonPath('body', 'yay')
            ->assertJsonPath('attachment.mime', 'image/gif')
            ->assertJsonPath('attachment.animated', true);

        $this->assertStringContainsString('https://media.tenor.com', $this->actingAs($this->me)->get('/chat')->headers->get('Content-Security-Policy'));
    }

    public function test_gifs_that_are_not_on_tenor_media_or_not_gifs_are_refused(): void
    {
        config(['chat.gifs.tenor_key' => 'test-key']);

        Http::fake([
            'tenor.googleapis.com/v2/posts?ids=bad*' => Http::response(['results' => [['media_formats' => ['gif' => ['url' => 'http://169.254.169.254/latest']]]]]),
            'tenor.googleapis.com/v2/posts?ids=fake*' => Http::response(['results' => [['media_formats' => ['gif' => ['url' => 'https://media.tenor.com/fake.gif']]]]]),
        ]);
        $this->app->instance(SafeFetcher::class, (new FakeWeb)->host('media.tenor.com')->respond('https://media.tenor.com/fake.gif', '<html>', 'image/gif'));

        $this->send(['gif_id' => 'bad'])->assertUnprocessable();
        $this->send(['gif_id' => 'fake'])->assertUnprocessable();
        $this->send(['gif_id' => '../../etc'])->assertJsonValidationErrors('gif_id');

        $this->assertSame(0, Message::count());
        $this->assertSame([], Storage::disk('chat')->allFiles());
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->me)
            ->post("/conversations/{$this->conversation->id}/messages", $payload, ['Accept' => 'application/json']);
    }

    private function makeSticker(User $user): Sticker
    {
        $this->actingAs($user)->post('/stickers', ['sticker' => UploadedFile::fake()->image('s.png', 300, 300)], ['Accept' => 'application/json'])->assertCreated();

        return $user->stickers()->latest('id')->firstOrFail();
    }

    /** A two-frame animated GIF. */
    private static function gif(): string
    {
        $frame = fn (int $color) => "\x21\xF9\x04\x04\x0A\x00\x00\x00" // graphic control: 100 ms delay
            ."\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00"                // image descriptor 2×2
            ."\x02\x02".chr(0x84 | $color)."\x51\x00";              // LZW data

        return "GIF89a\x02\x00\x02\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF"
            ."\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00"
            .$frame(0).$frame(1)
            ."\x3B";
    }
}
