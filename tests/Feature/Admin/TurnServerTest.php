<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AppConfigService;
use App\Services\IceServerService;
use App\Services\TurnServerService;
use App\Support\Stun\TurnProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Admin → App settings → Call server (TURN): the app's own coturn server, saved by
 * scripts/setup-turn.sh (chat:turn-server) and checked with a real relay request.
 */
class TurnServerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = '5f4d3c2b1a0998877665544332211000aabbccddeeff';

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    /** The fake server's static-auth-secret ("open": no password needed). */
    private string $secret = self::SECRET;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            $pid = proc_get_status($this->server)['pid'];
            PHP_OS_FAMILY === 'Windows' ? exec('taskkill /F /T /PID '.$pid.' 2>NUL') : proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    /** Starts tests/Fixtures/fake-turn-server.php (UDP + TCP) with the shared secret. */
    private function startFakeTurnServer(): int
    {
        $this->port = $this->freePort();
        $this->server = proc_open([PHP_BINARY, base_path('tests/Fixtures/fake-turn-server.php'), (string) $this->port, $this->secret], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($this->server);

        // The server prints "ready" once it listens, or stops with an error on stderr
        // (stderr is only read then: reading it earlier would wait for the server to exit).
        $line = trim((string) fgets($pipes[1]));
        if ($line !== 'ready') {
            $this->fail('The fake TURN server did not start: '.stream_get_contents($pipes[2]));
        }

        return $this->port;
    }

    private function saveServer(string $urls, string $secret = self::SECRET): void
    {
        app(TurnServerService::class)->saveServer(explode(',', $urls), $secret);
    }

    public function test_a_working_server_gives_a_relay_over_udp_and_tcp(): void
    {
        $port = $this->startFakeTurnServer();
        $credentials = ['username' => (time() + 600).':check'];
        $credentials['credential'] = base64_encode(hash_hmac('sha1', $credentials['username'], self::SECRET, true));
        $probe = new TurnProbe;

        foreach (['udp', 'tcp'] as $transport) {
            $result = $probe->allocate('127.0.0.1', $port, $transport, $credentials['username'], $credentials['credential'], 3);
            $this->assertTrue($result['ok'], $transport.': '.$result['detail']);
            $this->assertSame('203.0.113.7:49170', $result['relay']);
            $this->assertIsInt($result['ms']);
        }
    }

    public function test_a_wrong_secret_and_a_missing_server_are_told_apart(): void
    {
        $port = $this->startFakeTurnServer();
        $probe = new TurnProbe;

        $wrong = $probe->allocate('127.0.0.1', $port, 'udp', (time() + 600).':check', base64_encode(hash_hmac('sha1', 'x', 'not-the-secret', true)), 3);
        $this->assertFalse($wrong['ok']);
        $this->assertSame('wrong_secret', $wrong['problem']);
        $this->assertStringContainsString('static-auth-secret', $wrong['detail']);

        $closed = $this->freePort();
        $this->assertSame('no_answer', $probe->allocate('127.0.0.1', $closed, 'udp', 'u', 'p', 1.2)['problem']);
        $this->assertSame('unreachable', $probe->allocate('127.0.0.1', $closed, 'tcp', 'u', 'p', 1.2)['problem']);
    }

    public function test_setup_script_command_saves_the_server_in_the_admin_settings(): void
    {
        app(AppConfigService::class)->update(['turn_username' => 'old', 'turn_password' => 'old-password']);

        // Without a secret on stdin nothing is saved.
        $this->artisan('chat:turn-server', ['--urls' => 'turn:chat.example.com:3478?transport=udp', '--secret-stdin' => true])
            ->expectsOutputToContain('Pipe the shared secret')
            ->assertFailed();
        $this->assertNull(AppSetting::get('turn_urls'));

        $this->saveServer('turn:chat.example.com:3478?transport=udp,turns:chat.example.com:5349?transport=tcp');
        $this->assertSame('turn:chat.example.com:3478?transport=udp,turns:chat.example.com:5349?transport=tcp', AppSetting::get('turn_urls'));
        $this->assertSame(self::SECRET, Crypt::decryptString(AppSetting::get('turn_secret')));
        $this->assertNull(AppSetting::get('turn_username'));
        $this->assertNull(AppSetting::get('turn_password'));

        $status = app(TurnServerService::class)->status();
        $this->assertTrue($status['configured']);
        $this->assertSame('secret', $status['auth']);
        $this->assertSame([
            ['url' => 'turn:chat.example.com:3478?transport=udp', 'host' => 'chat.example.com', 'port' => 3478, 'transport' => 'udp'],
            ['url' => 'turns:chat.example.com:5349?transport=tcp', 'host' => 'chat.example.com', 'port' => 5349, 'transport' => 'tls'],
        ], $status['endpoints']);

        // Calls get per-person passwords from that secret.
        $user = User::factory()->create();
        $servers = app(IceServerService::class)->for($user);
        $turn = end($servers);
        $this->assertStringEndsWith(':'.$user->id, $turn['username']);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], self::SECRET, true)), $turn['credential']);
    }

    public function test_the_command_rejects_bad_addresses(): void
    {
        $this->artisan('chat:turn-server', ['--urls' => 'https://chat.example.com', '--secret-stdin' => true])
            ->expectsOutputToContain('not valid: https://chat.example.com')
            ->assertFailed();
        $this->artisan('chat:turn-server', ['--check' => true])->expectsOutputToContain('No TURN server is set up')->assertFailed();
    }

    public function test_the_command_and_the_admin_panel_check_the_server(): void
    {
        $port = $this->startFakeTurnServer();
        $this->saveServer("turn:127.0.0.1:{$port}?transport=udp,turn:127.0.0.1:{$port}?transport=tcp");

        $this->artisan('chat:turn-server', ['--check' => true])
            ->expectsOutputToContain('Relay address 203.0.113.7:49170')
            ->assertSuccessful();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.turn.check'))
            ->assertRedirect(route('admin.settings').'#turn')
            ->assertSessionHas('status', fn ($message) => str_starts_with($message, 'The TURN server answers and the secret matches.') && str_contains($message, 'Test from this browser'));

        $this->actingAs($admin)->get(route('admin.settings'))->assertOk()
            ->assertSee('Call server (TURN)')
            ->assertSee('<span class="badge badge-success">Answers</span>', false)
            ->assertSee('relay 203.0.113.7:49170')
            ->assertSee("turn:127.0.0.1:{$port}?transport=tcp");

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Call server (TURN)')->assertSee('answers (server check');

        // A different secret in the app than in coturn is caught.
        // Changing the secret in App settings drops the old result ("Answers" would be stale).
        app(AppConfigService::class)->update(['turn_secret' => str_repeat('b', 40)]);
        $this->assertNull(app(TurnServerService::class)->lastCheck());
        $this->actingAs($admin)->get(route('admin.settings'))->assertSee('<span class="badge badge-muted">Not checked</span>', false);

        // …and a secret that isn't coturn's is caught.
        $this->actingAs($admin)->post(route('admin.turn.check'))->assertSessionHas('error');
        $this->actingAs($admin)->get(route('admin.settings'))
            ->assertSee('<span class="badge badge-danger">Problem</span>', false)
            ->assertSee('static-auth-secret');
    }

    public function test_a_server_that_relays_for_anyone_is_a_problem(): void
    {
        $this->secret = 'open';
        $port = $this->startFakeTurnServer();

        $result = (new TurnProbe)->allocate('127.0.0.1', $port, 'udp', 'u', 'p', 3);

        $this->assertFalse($result['ok']);
        $this->assertSame('open_relay', $result['problem']);
        $this->assertStringContainsString('use-auth-secret', $result['detail']);
    }

    public function test_the_browser_check_gets_short_lived_turn_credentials_for_admins_only(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson(route('admin.turn.servers'))->assertOk()->assertExactJson(['ice_servers' => []]);
        $this->actingAs($admin)->get(route('admin.settings'))->assertOk()
            ->assertSee('<span class="badge badge-warning">Not set up</span>', false)
            ->assertSee('setup-turn.sh');
        $this->actingAs($admin)->post(route('admin.turn.check'))->assertSessionHas('error', 'Set up the TURN server first.');

        $this->saveServer('turn:turn.example.com:3478?transport=udp');
        $response = $this->actingAs($admin)->getJson(route('admin.turn.servers'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $server = $response->json('ice_servers.0');
        $this->assertSame(['turn:turn.example.com:3478?transport=udp'], $server['urls']);
        [$expires, $label] = explode(':', $server['username']);
        $this->assertSame('admin-'.$admin->id, $label);
        $this->assertLessThanOrEqual(time() + 600, (int) $expires);
        $this->assertArrayNotHasKey('stun', $server);

        $this->actingAs(User::factory()->create())->getJson(route('admin.turn.servers'))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('admin.turn.check'))->assertForbidden();
    }
}
