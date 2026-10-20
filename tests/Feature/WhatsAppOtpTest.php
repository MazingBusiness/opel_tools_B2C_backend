<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\OtpChallenge;
use App\Modules\Auth\Services\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WhatsAppOtpTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-wa-token-SECRET';

    private const GRAPH = 'graph.facebook.com/*';

    private const SMS = 'www.smsalert.co.in/*';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new class extends OtpCodeGenerator
        {
            public function generate(): string
            {
                return '123456';
            }
        });

        config([
            'services.whatsapp.otp_enabled' => true,
            'services.whatsapp.access_token' => self::TOKEN,
            'services.whatsapp.phone_number_id' => '1112223334',
            'services.whatsapp.graph_version' => 'v21.0',
            'services.whatsapp.otp_template' => 'login_registration_otp',
            'services.whatsapp.otp_language' => 'en',
            'services.whatsapp.otp_button' => true,
            'services.smsalert.api_key' => 'sms-key',
            'services.smsalert.sender' => 'OPELXX',
            'services.smsalert.otp_text' => 'Your OPEL login code is {code}.',
        ]);
    }

    private function request(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/otp/request', $body + ['identifier' => '98765 43210']);
    }

    private function waOk(): array
    {
        return [
            self::GRAPH => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.TEST']]], 200),
            self::SMS => Http::response(['status' => 'success'], 200),
        ];
    }

    private function smsSent(): bool
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'smsalert.co.in'))->isNotEmpty();
    }

    public function test_sends_exact_auth_template_payload_via_whatsapp(): void
    {
        Http::fake($this->waOk());

        $this->request()->assertOk()
            ->assertJson(['ok' => true, 'channel' => 'sms', 'delivery' => 'whatsapp', 'expires_in' => 300, 'resend_after' => 45]);

        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://graph.facebook.com/v21.0/1112223334/messages'
                && $r->method() === 'POST'
                && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $r->data() === [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => '919876543210',
                    'type' => 'template',
                    'template' => [
                        'name' => 'login_registration_otp',
                        'language' => ['code' => 'en'],
                        'components' => [
                            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '123456']]],
                            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '123456']]],
                        ],
                    ],
                ];
        });
        $this->assertFalse($this->smsSent());
        $this->assertDatabaseHas('otp_challenges', ['identifier' => '919876543210', 'channel' => 'sms', 'delivered_via' => 'whatsapp']);
    }

    public function test_button_component_omitted_when_disabled(): void
    {
        config(['services.whatsapp.otp_button' => false]);
        Http::fake($this->waOk());

        $this->request()->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && count($r['template']['components']) === 1
            && $r['template']['components'][0]['type'] === 'body');
    }

    /** @return array<string, array{0: int, 1: mixed}> */
    public static function graphFailures(): array
    {
        return [
            '4xx error' => [400, ['error' => ['message' => 'Template not found', 'code' => 132001, 'fbtrace_id' => 'Ab']]],
            '5xx error' => [503, 'upstream'],
            '200 without message id' => [200, ['messaging_product' => 'whatsapp', 'messages' => []]],
        ];
    }

    #[DataProvider('graphFailures')]
    public function test_falls_back_to_sms_when_graph_call_fails(int $status, mixed $body): void
    {
        Http::fake([self::GRAPH => Http::response($body, $status), self::SMS => Http::response(['status' => 'success'], 200)]);

        $this->request()->assertOk()->assertJsonPath('delivery', 'sms');

        $this->assertTrue($this->smsSent());
        $this->assertSame('sms', OtpChallenge::query()->value('delivered_via'));
    }

    public function test_falls_back_to_sms_on_connection_exception(): void
    {
        Http::fake([
            self::GRAPH => fn () => throw new ConnectionException('timeout'),
            self::SMS => Http::response(['status' => 'success'], 200),
        ]);

        $this->request()->assertOk()->assertJsonPath('delivery', 'sms');
        $this->assertTrue($this->smsSent());
    }

    public function test_both_channels_failing_returns_503(): void
    {
        Http::fake([self::GRAPH => Http::response([], 500), self::SMS => Http::response([], 500)]);

        $this->request()->assertStatus(503);
    }

    public function test_prefer_sms_skips_whatsapp(): void
    {
        Http::fake($this->waOk());

        $this->request(['prefer' => 'sms'])->assertOk()->assertJsonPath('delivery', 'sms');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'));
        $this->assertTrue($this->smsSent());
        $this->request(['prefer' => 'whatsapp'])->assertUnprocessable()->assertJsonValidationErrors('prefer');
    }

    public function test_kill_switch_and_missing_config_use_sms_only(): void
    {
        foreach ([
            ['services.whatsapp.otp_enabled' => false],
            ['services.whatsapp.access_token' => null],
            ['services.whatsapp.phone_number_id' => ''],
        ] as $i => $override) {
            config(['services.whatsapp.otp_enabled' => true, 'services.whatsapp.access_token' => self::TOKEN, 'services.whatsapp.phone_number_id' => '1112223334']);
            config($override);
            Http::fake($this->waOk());

            $this->postJson('/api/v1/auth/otp/request', ['identifier' => '98765432'.(10 + $i)])
                ->assertOk()->assertJsonPath('delivery', 'sms');
            Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'));
        }
    }

    public function test_email_never_uses_whatsapp(): void
    {
        Notification::fake();
        Http::fake($this->waOk());

        $this->request(['identifier' => 'shopper@example.com'])->assertOk()->assertJsonPath('delivery', 'email');
        Http::assertNothingSent();
    }

    public function test_verify_works_for_whatsapp_delivered_code(): void
    {
        Http::fake($this->waOk());
        $this->request()->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '9876543210', 'code' => '123456'])
            ->assertOk()->assertJsonPath('ok', true);
    }

    public function test_logs_never_contain_code_token_or_full_phone(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        Http::fake([self::GRAPH => Http::response(['error' => ['message' => 'bad', 'code' => 100]], 400), self::SMS => Http::response([], 500)]);

        $this->request()->assertStatus(503);

        $all = implode("\n", $logged);
        $this->assertNotSame('', $all);
        $this->assertStringNotContainsString('123456', $all);
        $this->assertStringNotContainsString(self::TOKEN, $all);
        $this->assertStringNotContainsString('9876543210', $all);
        $this->assertStringContainsString('91******3210', $all);
    }

    public function test_resend_cooldown_returns_429(): void
    {
        Http::fake($this->waOk());

        $this->request()->assertOk();
        $this->request(['prefer' => 'sms'])->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonPath('retry_after', 45);

        $this->travel(46)->seconds();
        $this->request(['prefer' => 'sms'])->assertOk()->assertJsonPath('delivery', 'sms');
    }

    public function test_per_identifier_hourly_cap(): void
    {
        config(['otp.resend_cooldown_seconds' => 0, 'otp.per_identifier_per_hour' => 3]);
        Http::fake($this->waOk());

        // Different formats of the same number share one bucket.
        foreach (['9876543210', '+91 98765 43210', '09876543210'] as $format) {
            $this->postJson('/api/v1/auth/otp/request', ['identifier' => $format])->assertOk();
            $this->travel(61)->seconds(); // stay under the 5/min burst limit
        }
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '919876543210'])->assertStatus(429);
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '9123456780'])->assertOk(); // other number fine
    }

    public function test_per_ip_hourly_cap_across_numbers(): void
    {
        config(['otp.resend_cooldown_seconds' => 0, 'otp.per_ip_per_hour' => 4]);
        Http::fake($this->waOk());

        foreach (['9000000001', '9000000002', '9000000003', '9000000004'] as $n) {
            $this->postJson('/api/v1/auth/otp/request', ['identifier' => $n])->assertOk();
        }
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '9000000005'])->assertStatus(429);

        $this->travel(61)->minutes();
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '9000000005'])->assertOk();
    }

    public function test_verify_limiter_is_separate_from_send_caps(): void
    {
        config(['otp.per_ip_per_hour' => 1]);
        Http::fake($this->waOk());

        $this->request()->assertOk();
        // Verify attempts don't consume the send buckets and vice versa.
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '9876543210', 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '9876543210', 'code' => '123456'])->assertOk();
    }
}
