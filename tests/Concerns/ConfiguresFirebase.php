<?php

namespace Tests\Concerns;

use App\Services\PushService;
use Illuminate\Support\Facades\Http;

/**
 * Firebase push with a throwaway service account and faked Google endpoints.
 */
trait ConfiguresFirebase
{
    private ?string $firebaseCredentialsPath = null;

    protected function tearDownConfiguresFirebase(): void
    {
        if ($this->firebaseCredentialsPath && is_file($this->firebaseCredentialsPath)) {
            unlink($this->firebaseCredentialsPath);
        }
    }

    protected function fakeFirebase(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/one2one-test/messages/1']),
        ]);
    }

    /**
     * Writes a service-account file with a freshly generated key.
     */
    protected function configureFirebase(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $exportOptions = null;
        $key = @openssl_pkey_new($options);

        // Windows PHP builds (XAMPP) need an explicit openssl.cnf.
        foreach ([dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', dirname(PHP_BINARY).'/extras/openssl/openssl.cnf'] as $config) {
            if ($key === false && is_file($config)) {
                $key = @openssl_pkey_new($options + ['config' => $config]);
                $exportOptions = ['config' => $config];
            }
        }

        if ($key === false || ! openssl_pkey_export($key, $pem, null, $exportOptions)) {
            $this->markTestSkipped('OpenSSL cannot generate RSA keys in this environment.');
        }

        $this->firebaseCredentialsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fcm-test-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->firebaseCredentialsPath, json_encode([
            'type' => 'service_account',
            'project_id' => 'one2one-test',
            'private_key' => $pem,
            'client_email' => 'push@one2one-test.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config(['chat.push.credentials' => $this->firebaseCredentialsPath]);
        $this->app->forgetInstance(PushService::class);
    }
}
