<?php

namespace App\Services\Delivery;

use App\Enums\SmsSubmissionStatus;
use App\Services\Delivery\Contracts\SmsSender;
use App\Services\Delivery\Contracts\SmsStatusReader;
use App\Services\Delivery\Data\SmsSubmissionResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SensitiveParameter;

class QuickConnectSmsSender implements SmsSender, SmsStatusReader
{
    public function available(): bool
    {
        return config('services.quick_connect.enabled') === true
            && is_string(config('services.quick_connect.api_key'))
            && preg_match('/\Aqc_live_[a-zA-Z0-9]+\z/', config('services.quick_connect.api_key')) === 1
            && config('services.quick_connect.base_url') === 'https://aalcgjgocszngpcqzjih.supabase.co/functions/v1/public-api';
    }

    public function submit(#[SensitiveParameter] string $recipient, #[SensitiveParameter] array $credentials, string $idempotencyKey): SmsSubmissionResult
    {
        if (! $this->available() || preg_match('/\A\+967(?:70|71|73|77)[0-9]{7}\z/', $recipient) !== 1) {
            return new SmsSubmissionResult(SmsSubmissionStatus::REJECTED);
        }
        $lines = [];
        foreach (['username' => 'اسم المستخدم', 'password' => 'كلمة المرور', 'pin' => 'رقم الكرت', 'serial' => 'الرقم التسلسلي'] as $key => $label) {
            if (isset($credentials[$key]) && is_string($credentials[$key]) && $credentials[$key] !== '') {
                $lines[] = $label.': '.$credentials[$key];
            }
        }
        if ($lines === []) {
            return new SmsSubmissionResult(SmsSubmissionStatus::REJECTED);
        }
        $network = isset($credentials['network_name']) && is_string($credentials['network_name']) ? $credentials['network_name'] : null;

        return $this->sendText($recipient, MessageTemplate::format('بيانات كرت الإنترنت', $lines, $network));
    }

    public function sendText(#[SensitiveParameter] string $recipient, #[SensitiveParameter] string $body): SmsSubmissionResult
    {
        if (! $this->available() || YemenPhoneNumber::normalize($recipient) !== $recipient || trim($body) === '' || mb_strlen($body) > 1000) {
            return new SmsSubmissionResult(SmsSubmissionStatus::REJECTED);
        }
        try {
            $response = $this->client()->post('/messages', ['channel' => 'sms', 'to' => $recipient, 'body' => $body]);
        } catch (ConnectionException) {
            return new SmsSubmissionResult(SmsSubmissionStatus::UNKNOWN);
        }
        if (in_array($response->status(), [400, 401, 402, 403, 404], true)) {
            return new SmsSubmissionResult(SmsSubmissionStatus::REJECTED);
        }
        $ids = $response->json('ids');
        if ($response->status() !== 201 || ! is_array($ids) || count($ids) !== 1
            || ! is_string($ids[0]) || ! Str::isUuid($ids[0])
            || $response->json('channel_requested') !== 'sms'
            || $response->json('channels_used') !== ['sms'] || $response->json('to') !== $recipient
            || ! in_array($response->json('status'), ['queued', 'pending', 'sent'], true)) {
            return new SmsSubmissionResult(SmsSubmissionStatus::UNKNOWN);
        }

        return new SmsSubmissionResult(SmsSubmissionStatus::ACCEPTED, $ids[0]);
    }

    public function status(string $providerReference): ?string
    {
        if (! $this->available() || ! Str::isUuid($providerReference)) {
            return null;
        }
        try {
            $response = $this->client()->get('/messages/'.$providerReference);
        } catch (ConnectionException) {
            return null;
        }
        $message = $response->json('data') ?? $response->json('message');
        if (! $response->successful() || $response->json('success') !== true || ! is_array($message)
            || ($message['id'] ?? null) !== $providerReference || ($message['channel'] ?? null) !== 'sms') {
            return null;
        }

        return match ($message['status'] ?? null) {
            'sent' => 'sent',
            'failed' => 'failed',
            'queued', 'pending' => 'pending',
            default => null,
        };
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('services.quick_connect.base_url'))
            ->withToken(config('services.quick_connect.api_key'))->acceptJson()->asJson()
            ->connectTimeout(5)->timeout(15)->withoutRedirecting();
    }
}
