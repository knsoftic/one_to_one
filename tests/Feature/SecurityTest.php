<?php

namespace Tests\Feature;

use App\Http\Middleware\DevAutoLogin;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_pages_send_a_nonce_based_content_security_policy(): void
    {
        $response = $this->get('/login')->assertOk();

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);

        preg_match("/'nonce-([^']+)'/", $csp, $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $response->assertSee('nonce="'.$matches[1].'"', false);

        // No unsafe script sources.
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $csp);
    }

    public function test_csp_can_be_disabled(): void
    {
        config(['chat.security.csp' => false]);

        $this->get('/login')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_json_endpoints_are_protected_by_csrf(): void
    {
        // CSRF middleware is skipped in unit tests by default; assert it is part of the web stack.
        $this->assertContains(
            ValidateCsrfToken::class,
            app(Kernel::class)->getMiddlewareGroups()['web'],
        );
    }

    public function test_there_is_no_way_to_sign_in_without_credentials(): void
    {
        $user = User::factory()->create();

        $this->get('/chat')->assertRedirect(route('login'));
        $this->get('/chat', ['Host' => 'localhost'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse(class_exists(DevAutoLogin::class));
        $this->assertNotNull($user);
    }

    public function test_custom_error_pages_are_rendered(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go to chats');
    }

    public function test_passwords_are_hashed(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);

        $this->assertNotSame('Secret123', $user->getRawOriginal('password'));
        $this->assertTrue(password_verify('Secret123', $user->getRawOriginal('password')));
    }
}
