<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings')->assertOk()->assertSee($user->username);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', [
            'name' => 'Updated Name',
            'username' => 'updated.user',
            'email' => 'UPDATED@example.com',
            'phone' => '+1 (555) 010-9999',
        ])->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('updated.user', $user->username);
        $this->assertSame('updated@example.com', $user->email);
        // The number changes only with "Change number" (A2).
        $this->assertNotSame('+15550109999', $user->phone);
    }

    public function test_profile_update_keeps_own_unique_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
        ])->assertSessionHasNoErrors();
    }

    public function test_profile_update_rejects_values_taken_by_others(): void
    {
        $other = User::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name,
            'username' => $other->username,
            'email' => $other->email,
            'phone' => $other->phone,
        ])->assertSessionHasErrorsIn('profile', ['username', 'email']);
    }

    public function test_profile_picture_can_be_replaced_and_removed(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'phone' => $user->phone,
            'profile_image' => UploadedFile::fake()->image('one.png', 300, 300),
        ]);
        $first = $user->fresh()->profile_image;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'phone' => $user->phone,
            'profile_image' => UploadedFile::fake()->image('two.jpg', 300, 300),
        ]);
        $second = $user->fresh()->profile_image;
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'phone' => $user->phone,
            'remove_profile_image' => '1',
        ]);
        $this->assertNull($user->fresh()->profile_image);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/password', [
            'current_password' => 'Password1',
            'password' => 'BrandNew123',
            'password_confirmation' => 'BrandNew123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('BrandNew123', $user->fresh()->password));
    }

    public function test_correct_current_password_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'BrandNew123',
            'password_confirmation' => 'BrandNew123',
        ])->assertSessionHasErrorsIn('password', 'current_password');
    }

    public function test_preferences_can_be_updated_via_ajax(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/settings/preferences', ['theme' => 'dark', 'notifications_enabled' => false])
            ->assertOk()
            ->assertJsonPath('preferences.theme', 'dark')
            ->assertJsonPath('preferences.notifications_enabled', false);

        $this->actingAs($user)->patchJson('/settings/preferences', ['theme' => 'neon'])->assertUnprocessable();
    }

    public function test_role_and_status_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', [
            'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'phone' => $user->phone,
            'role' => 'admin', 'status' => 'active', 'is_online' => 0,
        ]);

        $this->assertSame(User::ROLE_USER, $user->fresh()->role);
    }
}
