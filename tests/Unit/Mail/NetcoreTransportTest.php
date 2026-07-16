<?php

namespace Tests\Unit\Mail;

use App\Mail\Transports\NetcoreTransport;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class NetcoreTransportTest extends TestCase
{
    use RefreshDatabase;

    private function makeMessage(string $to = 'to@example.com'): Email
    {
        return (new Email)
            ->from(new Address('from@example.com', 'From Name'))
            ->to(new Address($to, 'To Name'))
            ->subject('Hello')
            ->html('<p>Body</p>')
            ->text('Body');
    }

    public function test_sends_correct_payload_and_api_key_header(): void
    {
        Http::fake(['emailapi.netcorecloud.net/*' => Http::response(['status' => 'success'], 200)]);

        (new NetcoreTransport('test-key', 'https://emailapi.netcorecloud.net', 10))
            ->send($this->makeMessage());

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->hasHeader('api_key', 'test-key')
                && $body['from']['email'] === 'from@example.com'
                && $body['from']['name'] === 'From Name'
                && $body['subject'] === 'Hello'
                && $body['personalizations'][0]['to'][0]['email'] === 'to@example.com'
                && $body['content'][0]['type'] === 'html';
        });
    }

    public function test_throws_on_non_2xx_response(): void
    {
        Http::fake(['emailapi.netcorecloud.net/*' => Http::response('boom', 500)]);

        $this->expectException(TransportException::class);

        (new NetcoreTransport('test-key', 'https://emailapi.netcorecloud.net', 10))->send($this->makeMessage());
    }

    public function test_throws_when_api_key_is_missing(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->expectException(TransportException::class);

        (new NetcoreTransport('', 'https://emailapi.netcorecloud.net', 10))->send($this->makeMessage());
    }

    public function test_suppressed_when_global_kill_switch_is_off(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Setting::set('netcore_enabled', false);

        (new NetcoreTransport('test-key', 'https://emailapi.netcorecloud.net', 10))->send($this->makeMessage());

        Http::assertNothingSent();
    }

    public function test_uses_api_key_from_settings_over_config(): void
    {
        Http::fake(['emailapi.netcorecloud.net/*' => Http::response(['status' => 'success'], 200)]);
        Setting::set('netcore_api_key', 'db-key');

        // Transport constructed with a config key; the DB setting must win.
        (new NetcoreTransport('config-key', 'https://emailapi.netcorecloud.net', 10))->send($this->makeMessage());

        Http::assertSent(fn ($request) => $request->hasHeader('api_key', 'db-key'));
    }
}
