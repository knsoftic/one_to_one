<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M12 — Send videos (poster frame, duration, streaming playback).
 */
class VideoMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('chat');

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    public function test_a_video_is_stored_with_its_poster_duration_and_caption(): void
    {
        $response = $this->send([
            'attachment' => $this->mp4('beach trip.mp4'),
            'thumbnail' => UploadedFile::fake()->image('poster.jpg', 1280, 720),
            'duration' => '42.37',
            'message' => 'Sea view 🌊',
        ])->assertCreated()
            ->assertJsonPath('type', 'video')
            ->assertJsonPath('body', 'Sea view 🌊')
            ->assertJsonPath('attachment.name', 'beach trip.mp4')
            ->assertJsonPath('attachment.mime', 'video/mp4')
            ->assertJsonPath('attachment.duration', 42.4)
            ->assertJsonPath('attachment.width', 480)
            ->assertJsonPath('attachment.height', 270);

        $message = Message::sole();
        Storage::disk('chat')->assertExists($message->attachment);
        Storage::disk('chat')->assertExists($message->attachment_meta['thumbnail']);
        $this->assertStringEndsWith('.mp4', $message->attachment);
        $this->assertNotNull($response->json('attachment.thumbnail_url'));
        $this->assertSame('🎥 Sea view 🌊', $message->preview());

        $this->actingAs($this->friend)->get($response->json('attachment.thumbnail_url'))->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_videos_play_inline_with_seeking_for_participants_only(): void
    {
        $url = $this->send(['attachment' => $this->mp4()])->assertCreated()->json('attachment.url');

        $this->actingAs($this->friend)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Accept-Ranges', 'bytes')
            // Never cached by shared proxies.
            ->assertHeader('Cache-Control', 'max-age=86400, private');
        $this->assertStringStartsWith('inline', $this->actingAs($this->friend)->get($url)->headers->get('Content-Disposition'));

        $this->actingAs($this->friend)->get($url, ['Range' => 'bytes=0-9'])
            ->assertStatus(206)
            ->assertHeader('Content-Length', '10');

        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_a_video_without_a_poster_still_sends(): void
    {
        $this->send(['attachment' => $this->mp4('clip.mp4')])
            ->assertCreated()
            ->assertJsonPath('type', 'video')
            ->assertJsonPath('attachment.thumbnail_url', null)
            ->assertJsonPath('attachment.width', null);

        $this->assertSame('🎥 Video', Message::sole()->preview());
    }

    public function test_disguised_oversized_and_invalid_videos_are_rejected(): void
    {
        $fake = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($fake, '<?php echo "not a video";');
        $this->send(['attachment' => new UploadedFile($fake, 'movie.mp4', null, null, true)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachment' => 'Only MP4, WEBM, MOV and 3GP videos can be sent.']);

        config(['chat.uploads.video.max_kb' => 1]);
        $this->send(['attachment' => $this->mp4('big.mp4', 4096)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachment' => 'Videos may not be larger than 0 MB.']);
        config(['chat.uploads.video.max_kb' => 16384]);

        $this->send(['attachment' => $this->mp4(), 'thumbnail' => UploadedFile::fake()->create('poster.jpg', 10, 'text/plain')])
            ->assertJsonValidationErrors('thumbnail');
        $this->send(['attachment' => $this->mp4(), 'duration' => 999999])->assertJsonValidationErrors('duration');

        $this->assertSame(0, Message::count());
    }

    public function test_forwarding_copies_the_video_and_poster(): void
    {
        $id = $this->send(['attachment' => $this->mp4(), 'thumbnail' => UploadedFile::fake()->image('poster.png', 640, 360)])->json('id');
        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();

        $copy = $this->actingAs($this->me)->postJson("/messages/{$id}/forward", ['conversation_ids' => [$other->id]])
            ->assertCreated()
            ->assertJsonPath('data.0.type', 'video')
            ->json('data.0.id');

        $original = Message::find($id);
        $forwarded = Message::find($copy);
        $this->assertNotSame($original->attachment, $forwarded->attachment);
        Storage::disk('chat')->assertExists([$forwarded->attachment, $forwarded->attachment_meta['thumbnail']]);
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->me)
            ->post("/conversations/{$this->conversation->id}/messages", $payload, ['Accept' => 'application/json']);
    }

    /**
     * A tiny MP4 container ("ftyp" + "mdat" boxes): detected as video/mp4 from its content.
     */
    private function mp4(string $name = 'video.mp4', int $bytes = 256): UploadedFile
    {
        $ftyp = pack('N', 24).'ftypmp42'.pack('N', 0).'mp42isom';
        $mdat = pack('N', 8 + $bytes).'mdat'.str_repeat("\0", $bytes);

        $path = tempnam(sys_get_temp_dir(), 'mp4');
        file_put_contents($path, $ftyp.$mdat);

        return new UploadedFile($path, $name, null, null, true);
    }
}
