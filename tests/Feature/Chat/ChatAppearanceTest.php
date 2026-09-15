<?php

namespace Tests\Feature\Chat;

use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D2 — chat wallpaper, D3 — font size.
 */
class ChatAppearanceTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('chat');

        $this->me = User::factory()->create();
        $this->conversation = Conversation::factory()->between($this->me, User::factory()->create())->create();
    }

    public function test_default_wallpaper_preset_photo_and_dimming(): void
    {
        $this->actingAs($this->me)->postJson('/settings/wallpaper', ['wallpaper' => 'ocean'])
            ->assertOk()
            ->assertJsonPath('wallpaper', ['key' => 'ocean', 'url' => null, 'dim' => 0]);
        $this->assertSame('ocean', $this->me->fresh()->wallpaper);

        $response = $this->postJson('/settings/wallpaper', ['wallpaper' => 'custom', 'photo' => UploadedFile::fake()->image('beach.png', 2400, 1600), 'dim' => 35])
            ->assertOk()
            ->assertJsonPath('wallpaper.key', 'custom')
            ->assertJsonPath('wallpaper.dim', 35);

        $user = $this->me->fresh();
        Storage::disk('chat')->assertExists($user->wallpaper_path);
        $this->assertStringStartsWith("wallpapers/{$user->id}/", $user->wallpaper_path);
        [$width] = getimagesizefromstring(Storage::disk('chat')->get($user->wallpaper_path));
        $this->assertSame(1920, $width);

        $url = $response->json('wallpaper.url');
        $this->assertStringStartsWith('/settings/wallpaper?v=', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Only the dimming changes: the photo stays.
        $this->postJson('/settings/wallpaper', ['wallpaper' => 'custom', 'dim' => 50])->assertOk()->assertJsonPath('wallpaper.url', $url);

        // Back to the default: the photo is deleted.
        $old = $user->wallpaper_path;
        $this->postJson('/settings/wallpaper', ['wallpaper' => 'default'])->assertOk()->assertJsonPath('wallpaper.key', 'default');
        Storage::disk('chat')->assertMissing($old);
        $this->assertNull($this->me->fresh()->wallpaper);
        $this->get('/settings/wallpaper')->assertNotFound();
    }

    public function test_wallpaper_validation(): void
    {
        $this->actingAs($this->me)->postJson('/settings/wallpaper', ['wallpaper' => 'neon'])->assertJsonValidationErrors('wallpaper');
        $this->postJson('/settings/wallpaper', ['wallpaper' => 'custom'])->assertJsonValidationErrors('photo');
        $this->postJson('/settings/wallpaper', ['wallpaper' => 'custom', 'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertJsonValidationErrors('photo');
        $this->postJson('/settings/wallpaper', ['wallpaper' => 'plain', 'dim' => 95])->assertJsonValidationErrors('dim');
    }

    public function test_a_chat_has_its_own_wallpaper_only_for_me(): void
    {
        $id = $this->conversation->id;

        $this->actingAs($this->me)->postJson("/conversations/{$id}/wallpaper", ['wallpaper' => 'rose'])
            ->assertOk()
            ->assertJsonPath('settings.wallpaper', ['key' => 'rose', 'url' => null]);

        $response = $this->postJson("/conversations/{$id}/wallpaper", ['wallpaper' => 'custom', 'photo' => UploadedFile::fake()->image('us.jpg', 800, 600)])
            ->assertOk()
            ->assertJsonPath('settings.wallpaper.key', 'custom');
        $url = $response->json('settings.wallpaper.url');
        $this->assertStringStartsWith("/conversations/{$id}/wallpaper?v=", $url);
        $this->get($url)->assertOk();

        // The other person keeps the default and can't load my photo.
        $friend = $this->conversation->otherParticipant($this->me);
        $this->actingAs($friend)->getJson("/conversations/{$id}")->assertJsonPath('settings.wallpaper', null);
        $this->get("/conversations/{$id}/wallpaper")->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson("/conversations/{$id}/wallpaper", ['wallpaper' => 'rose'])->assertNotFound();

        $path = ChatSetting::query()->where('user_id', $this->me->id)->value('wallpaper_path');
        $this->actingAs($this->me)->postJson("/conversations/{$id}/wallpaper", ['wallpaper' => 'default'])
            ->assertOk()
            ->assertJsonPath('settings.wallpaper', null);
        Storage::disk('chat')->assertMissing($path);
    }

    public function test_font_size_preference_and_page_attribute(): void
    {
        $this->actingAs($this->me)->patchJson('/settings/preferences', ['font_size' => 'large'])
            ->assertOk()
            ->assertJsonPath('preferences.font_size', 'large');
        $this->patchJson('/settings/preferences', ['font_size' => 'huge'])->assertJsonValidationErrors('font_size');

        $this->get('/settings?tab=chats')->assertOk()->assertSee('data-font-size="large"', false)->assertSee('Font size');
        $this->get('/chat')->assertOk()->assertSee('data-font-size="large"', false);
    }

    public function test_wallpaper_photos_go_with_the_account(): void
    {
        $this->actingAs($this->me)->postJson('/settings/wallpaper', ['wallpaper' => 'custom', 'photo' => UploadedFile::fake()->image('a.jpg', 400, 400)])->assertOk();
        $path = $this->me->fresh()->wallpaper_path;

        app(AccountDeletionService::class)->delete($this->me->fresh());

        Storage::disk('chat')->assertMissing($path);
    }
}
