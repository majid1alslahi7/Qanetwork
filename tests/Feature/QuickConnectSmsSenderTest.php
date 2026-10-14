<?php

namespace Tests\Feature;

use App\Enums\SmsSubmissionStatus;
use App\Services\Delivery\Contracts\SmsSender;
use App\Services\Delivery\QuickConnectSmsSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuickConnectSmsSenderTest extends TestCase
{
    private const URL = 'https://aalcgjgocszngpcqzjih.supabase.co/functions/v1/public-api';

    private const ID = '8f3c2a91-3456-4567-8123-123456789abc';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.quick_connect.enabled' => true, 'services.quick_connect.api_key' => 'qc_live_test', 'services.quick_connect.base_url' => self::URL]);
        Http::preventStrayRequests();
    }

    public function test_sends_sms_only_with_private_credentials_and_records_provider_reference(): void
    {
        Http::fake([self::URL.'/messages' => Http::response(['ids' => [self::ID], 'channel_requested' => 'sms', 'channels_used' => ['sms'], 'to' => '+967771234567', 'status' => 'queued'], 201)]);

        $result = app(SmsSender::class)->submit('+967771234567', ['username' => 'user123', 'password' => 'secret123', 'provider_token' => 'never-send'], 'delivery:1');

        $this->assertSame(SmsSubmissionStatus::ACCEPTED, $result->status);
        $this->assertSame(self::ID, $result->providerReference);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer qc_live_test')
            && $request['channel'] === 'sms' && $request['to'] === '+967771234567'
            && $request['body'] === "QaNetwork — بيانات كرت الإنترنت\nاسم المستخدم: user123\nكلمة المرور: secret123");
        Http::assertSentCount(1);
    }

    #[DataProvider('rejectedResponses')]
    public function test_rejects_documented_errors_without_retrying(int $code): void
    {
        Http::fake([self::URL.'/messages' => Http::response(['error' => 'provider private details'], $code)]);

        $result = app(SmsSender::class)->submit('+967771234567', ['pin' => '123456'], 'delivery:1');

        $this->assertSame(SmsSubmissionStatus::REJECTED, $result->status);
        $this->assertNull($result->providerReference);
        Http::assertSentCount(1);
    }

    public static function rejectedResponses(): array
    {
        return [[400], [401], [402], [403], [404]];
    }

    #[DataProvider('uncertainResponses')]
    public function test_preserves_uncertain_results_without_automatic_resubmission(int $code, array $body): void
    {
        Http::fake([self::URL.'/messages' => Http::response($body, $code)]);

        $result = app(SmsSender::class)->submit('+967771234567', ['pin' => '123456'], 'delivery:1');

        $this->assertSame(SmsSubmissionStatus::UNKNOWN, $result->status);
        Http::assertSentCount(1);
    }

    public static function uncertainResponses(): array
    {
        return [[500, []], [429, []], [302, []], [201, ['ids' => ['invalid']]], [200, []]];
    }

    public function test_connection_failure_is_unknown_and_never_retries(): void
    {
        Http::fake([self::URL.'/messages' => Http::failedConnection()]);

        $result = app(SmsSender::class)->submit('+967771234567', ['pin' => '123456'], 'delivery:1');

        $this->assertSame(SmsSubmissionStatus::UNKNOWN, $result->status);
        Http::assertSentCount(1);
    }

    public function test_disabled_or_redirected_configuration_does_not_contact_provider(): void
    {
        config(['services.quick_connect.enabled' => false]);
        $this->assertFalse(app(SmsSender::class)->available());
        $this->assertSame(SmsSubmissionStatus::REJECTED, app(SmsSender::class)->submit('+967771234567', ['pin' => '123'], 'x')->status);
        config(['services.quick_connect.enabled' => true, 'services.quick_connect.base_url' => 'https://example.com']);
        $this->assertFalse(app(SmsSender::class)->available());
        Http::assertNothingSent();
    }

    public function test_unsupported_recipient_does_not_send_card_credentials(): void
    {
        $result = app(SmsSender::class)->submit('+15551234567', ['pin' => '123456'], 'delivery:1');

        $this->assertSame(SmsSubmissionStatus::REJECTED, $result->status);
        Http::assertNothingSent();
    }

    #[DataProvider('deliveryStatuses')]
    public function test_reads_only_matching_sms_status(string $status, ?string $expected): void
    {
        Http::fake([self::URL.'/messages/'.self::ID => Http::response(['success' => true, 'data' => ['id' => self::ID, 'channel' => 'sms', 'status' => $status]])]);

        $this->assertSame($expected, app(QuickConnectSmsSender::class)->status(self::ID));

        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    public static function deliveryStatuses(): array
    {
        return [['sent', 'sent'], ['failed', 'failed'], ['queued', 'pending'], ['pending', 'pending'], ['unexpected', null]];
    }

    public function test_status_with_foreign_reference_is_not_trusted(): void
    {
        Http::fake([self::URL.'/messages/'.self::ID => Http::response(['success' => true, 'data' => ['id' => 'different', 'channel' => 'sms', 'status' => 'sent']])]);

        $this->assertNull(app(QuickConnectSmsSender::class)->status(self::ID));

        Http::assertSentCount(1);
    }

    public function test_accepts_live_provider_message_envelope_without_exposing_message_body(): void
    {
        Http::fake([self::URL.'/messages/'.self::ID => Http::response(['success' => true, 'message' => ['id' => self::ID, 'channel' => 'sms', 'status' => 'sent', 'body' => 'private-card', 'delivered_at' => null]])]);

        $this->assertSame('sent', app(QuickConnectSmsSender::class)->status(self::ID));

        Http::assertSentCount(1);
    }
}
