<?php

namespace Tests\Feature\Chat;

use App\Models\BlockedUser;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * S1 — Text, photo and video status for 24 hours.
 */
class StatusTest extends TestCase
{
    use RefreshDatabase, StatusTestHelpers;

    private User $ayesha;

    private User $bilal;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('chat');

        [$this->ayesha, $this->bilal, $this->sara] = [
            User::factory()->create(['name' => 'Ayesha']),
            User::factory()->create(['name' => 'Bilal']),
            User::factory()->create(['name' => 'Sara']),
        ];
        $this->saveContact($this->ayesha, $this->bilal);
    }

    public function test_text_photo_and_video_updates_reach_my_contacts_for_24_hours(): void
    {
        $this->actingAs($this->ayesha)->postJson('/statuses', ['text' => '   '])->assertUnprocessable();
        $this->actingAs($this->ayesha)->postJson('/statuses', [])->assertJsonValidationErrors(['text' => 'Type something or choose a photo or video.']);

        $text = $this->actingAs($this->ayesha)->postJson('/statuses', ['text' => 'Eid Mubarak 🌙', 'background' => 'violet', 'font' => 3])
            ->assertCreated()
            ->assertJsonPath('type', 'text')
            ->assertJsonPath('text', 'Eid Mubarak 🌙')
            ->assertJsonPath('background', 'violet')
            ->assertJsonPath('font', 3)
            ->assertJsonPath('views_count', 0)
            ->assertJsonPath('privacy', 'contacts')
            ->json();
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, strtotime($text['expires_at']), 5);

        $photo = $this->actingAs($this->ayesha)->post('/statuses', ['attachment' => UploadedFile::fake()->image('beach.jpg', 1200, 900), 'caption' => 'Clifton beach'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('type', 'image')
            ->assertJsonPath('text', 'Clifton beach')
            ->json();
        $video = $this->actingAs($this->ayesha)->post('/statuses', [
            'attachment' => UploadedFile::fake()->create('clip.mp4', 900, 'video/mp4'),
            'thumbnail' => UploadedFile::fake()->image('poster.jpg', 640, 360),
            'duration' => 12.4,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('type', 'video')
            ->assertJsonPath('duration', 12.4)
            ->json();
        $this->assertNotNull($video['thumbnail_url']);
        $this->actingAs($this->ayesha)->post('/statuses', ['attachment' => UploadedFile::fake()->create('long.mp4', 900, 'video/mp4'), 'duration' => 75], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['duration' => 'Status videos can be up to 60 seconds long.']);

        // Bilal (in Ayesha's contacts) sees all three, oldest first; Sara doesn't.
        $this->actingAs($this->bilal)->getJson('/statuses')
            ->assertOk()
            ->assertJsonCount(0, 'mine')
            ->assertJsonPath('updates.0.user.id', $this->ayesha->id)
            ->assertJsonPath('updates.0.viewed', false)
            ->assertJsonCount(3, 'updates.0.statuses')
            ->assertJsonPath('updates.0.statuses.0.id', $text['id'])
            ->assertJsonMissingPath('updates.0.statuses.0.views_count');
        $this->actingAs($this->bilal)->get($photo['media_url'])->assertOk();
        $this->actingAs($this->sara)->getJson('/statuses')->assertJsonCount(0, 'updates');
        $this->actingAs($this->sara)->get($photo['media_url'])->assertNotFound();
        $this->actingAs($this->ayesha)->getJson('/statuses')->assertJsonCount(3, 'mine');

        // People Ayesha chats with one-to-one count as her contacts too.
        $this->chatBetween($this->sara, $this->ayesha);
        $this->actingAs($this->sara)->getJson('/statuses')->assertJsonPath('updates.0.user.id', $this->ayesha->id);

        // Blocking hides updates both ways.
        BlockedUser::create(['user_id' => $this->bilal->id, 'blocked_user_id' => $this->ayesha->id]);
        $this->actingAs($this->bilal)->getJson('/statuses')->assertJsonCount(0, 'updates');
        $this->actingAs($this->bilal)->get($photo['media_url'])->assertNotFound();

        // After 24 hours they are gone, files included.
        $stored = Status::find($photo['id'])->attachment;
        $this->travel(25)->hours();
        $this->actingAs($this->sara)->getJson('/statuses')->assertJsonCount(0, 'updates');
        $this->actingAs($this->ayesha)->getJson('/statuses')->assertJsonCount(0, 'mine');
        $this->artisan('chat:expire-statuses')->assertSuccessful();
        $this->assertSame(0, Status::count());
        Storage::disk('chat')->assertMissing($stored);
    }

    public function test_only_the_owner_deletes_an_update(): void
    {
        $id = $this->textStatus($this->ayesha);

        $this->actingAs($this->bilal)->deleteJson("/statuses/{$id}")->assertNotFound();
        $this->actingAs($this->ayesha)->deleteJson("/statuses/{$id}")->assertOk();
        $this->assertNull(Status::find($id));
        $this->actingAs($this->bilal)->getJson('/statuses')->assertJsonCount(0, 'updates');
    }
}
