<?php

namespace Tests\Feature\Account;

use App\Exceptions\SmsException;
use App\Services\SmsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 7 — the SMS gateways: log, Twilio and any web SMS API.
 */
class SmsServiceTest extends TestCase
{
    public function test_which_gateways_are_ready(): void
    {
        $sms = new SmsService;

        config(['services.sms.driver' => 'log']);
        $this->assertTrue($sms->available());
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse($sms->available(), 'The log is never used for real users.');
        $this->app->detectEnvironment(fn () => 'testing');

        config(['services.sms.driver' => 'twilio', 'services.sms.twilio' => ['sid' => 'AC1', 'token' => 't', 'from' => null]]);
        $this->assertFalse($sms->available());
        config(['services.sms.twilio.from' => '+15550001111']);
        $this->assertTrue($sms->available());

        config(['services.sms.driver' => 'http', 'services.sms.http.url' => null]);
        $this->assertFalse($sms->available());
        config(['services.sms.http.url' => 'https://sms.example.com/send']);
        $this->assertTrue($sms->available());

        config(['services.sms.driver' => 'carrier-pigeon']);
        $this->assertFalse($sms->available());
    }

    public function test_the_log_gateway_writes_the_text(): void
    {
        config(['services.sms.driver' => 'log']);
        Log::shouldReceive('info')->once()->with('SMS to +923001234567: 123456 is your code.');

        (new SmsService)->send('+923001234567', '123456 is your code.');
    }

    public function test_twilio(): void
    {
        config(['services.sms.driver' => 'twilio', 'services.sms.twilio' => ['sid' => 'AC123', 'token' => 'secret', 'from' => 'MG999']]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        (new SmsService)->send('0092 300 1234567', 'Hello');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC123:secret'))
            && $request['To'] === '+923001234567'
            && $request['Body'] === 'Hello'
            && $request['MessagingServiceSid'] === 'MG999');
    }

    public function test_a_web_sms_api_with_its_own_field_names(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.http' => [
            'url' => 'https://sms.example.com/api/send',
            'method' => 'post',
            'format' => 'form',
            'to_field' => 'receiver',
            'message_field' => 'textmessage',
            'phone_format' => 'digits',
            'params' => 'api_key=abc123&sender=One2One',
            'headers' => 'X-Client=chat',
            'bearer' => 'tok',
            'success_text' => 'queued',
        ]]);
        Http::fake(['sms.example.com/*' => Http::response('{"status":"Queued"}')]);

        (new SmsService)->send('+923001234567', 'Your code');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['receiver'] === '923001234567'
            && $request['textmessage'] === 'Your code'
            && $request['api_key'] === 'abc123'
            && $request['sender'] === 'One2One'
            && $request->hasHeader('X-Client', 'chat')
            && $request->hasHeader('Authorization', 'Bearer tok'));

        // JSON and GET styles.
        config(['services.sms.http.format' => 'json', 'services.sms.http.success_text' => null]);
        (new SmsService)->send('+923001234567', 'Json');
        Http::assertSent(fn (Request $request) => $request->isJson() && $request['textmessage'] === 'Json');

        config(['services.sms.http.method' => 'get']);
        (new SmsService)->send('+923001234567', 'Get');
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'textmessage=Get'));
    }

    public function test_a_refused_text_is_an_error(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.http' => [
            'url' => 'https://sms.example.com/api/send', 'method' => 'post', 'format' => 'form', 'to_field' => 'to',
            'message_field' => 'message', 'phone_format' => 'plus', 'params' => null, 'headers' => null, 'bearer' => null,
            'success_text' => 'ok',
        ]]);

        Http::fake(['sms.example.com/*' => Http::sequence()->push('error: low balance')->push('', 500)]);

        foreach ([1, 2] as $attempt) {
            try {
                (new SmsService)->send('+923001234567', 'Code');
                $this->fail('The text should not count as sent.');
            } catch (SmsException $e) {
                $this->assertSame('The SMS could not be sent.', $e->getMessage());
            }
        }
    }
}
