<?php

namespace App\Services\Delivery;

use App\Enums\SmsSubmissionStatus;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\CardDelivery;
use App\Models\Sale;
use App\Models\User;
use App\Services\Delivery\Contracts\SmsSender;
use App\Services\Delivery\Data\SmsSubmissionResult;
use App\Services\Sales\PaidSaleCardService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class SendCardDeliveryService
{
    public function __construct(private readonly SmsSender $sender, private readonly PaidSaleCardService $cards) {}

    public function handle(string $deliveryId): CardDelivery
    {
        $record = CardDelivery::query()->findOrFail($deliveryId);
        $payload = DB::transaction(function () use ($record): ?array {
            $actor = User::query()->lockForUpdate()->findOrFail($record->requested_by);
            $sale = Sale::query()->lockForUpdate()->findOrFail($record->sale_id);
            $delivery = CardDelivery::query()->lockForUpdate()->findOrFail($record->id);
            if ($delivery->status === 'sending') {
                if ($delivery->started_at === null || $delivery->started_at->lte(now()->subMinutes(2))) {
                    $this->transition($delivery, 'unknown', 'interrupted_submission');
                }

                return null;
            }
            if (! in_array($delivery->status, ['queued', 'retryable', 'awaiting_configuration'], true)) {
                return null;
            }
            if ($actor->role !== UserRole::SELLER || ! $actor->canAccessApplication()
                || $actor->seller()->firstOrFail()->id !== $sale->seller_id) {
                $this->transition($delivery, 'cancelled', 'requester_unavailable');

                return null;
            }
            if (! $this->sender->available()) {
                $this->transition($delivery, 'awaiting_configuration', 'provider_not_configured');

                return null;
            }
            if ($delivery->next_retry_at?->isFuture()) {
                return null;
            }
            if ($delivery->attempt_count >= 3) {
                $this->transition($delivery, 'failed', 'retry_limit');

                return null;
            }
            try {
                $credentials = $this->cards->credentials($this->cards->card($sale));
                $recipient = $delivery->recipient_encrypted;
            } catch (ConflictHttpException|ServiceUnavailableHttpException) {
                $this->transition($delivery, 'failed', 'card_unavailable');

                return null;
            } catch (Throwable) {
                $this->transition($delivery, 'failed', 'recipient_unavailable');

                return null;
            }
            $delivery->attempt_count++;
            $delivery->started_at = now();
            $delivery->next_retry_at = null;
            $this->transition($delivery, 'sending');

            return ['recipient' => $recipient, 'credentials' => $credentials, 'attempt' => $delivery->attempt_count];
        }, 3);
        if ($payload === null) {
            return $record->fresh();
        }
        try {
            $result = $this->sender->submit($payload['recipient'], $payload['credentials'], 'card-delivery:'.$record->id);
        } catch (Throwable) {
            $result = new SmsSubmissionResult(SmsSubmissionStatus::UNKNOWN);
        }

        return DB::transaction(function () use ($record, $payload, $result): CardDelivery {
            Sale::query()->lockForUpdate()->findOrFail($record->sale_id);
            $delivery = CardDelivery::query()->lockForUpdate()->findOrFail($record->id);
            if ($delivery->status !== 'sending' || $delivery->attempt_count !== $payload['attempt']) {
                return $delivery;
            }
            if ($result->providerReference !== null && strlen($result->providerReference) > 191) {
                $this->transition($delivery, 'unknown', 'invalid_provider_result');

                return $delivery;
            }
            $delivery->provider_reference = $result->providerReference;
            match ($result->status) {
                SmsSubmissionStatus::ACCEPTED => $this->accepted($delivery),
                SmsSubmissionStatus::REJECTED => $this->transition($delivery, 'failed', 'provider_rejected'),
                SmsSubmissionStatus::UNKNOWN => $this->transition($delivery, 'unknown', 'uncertain_submission'),
                SmsSubmissionStatus::RETRYABLE => $this->retry($delivery),
            };

            return $delivery;
        }, 3);
    }

    private function accepted(CardDelivery $delivery): void
    {
        $delivery->submitted_at = now();
        $this->transition($delivery, 'submitted');
    }

    private function retry(CardDelivery $delivery): void
    {
        if ($delivery->attempt_count >= 3) {
            $this->transition($delivery, 'failed', 'retry_limit');

            return;
        }
        $delivery->next_retry_at = now()->addSeconds($delivery->attempt_count * 60);
        $this->transition($delivery, 'retryable', 'confirmed_not_sent');
    }

    private function transition(CardDelivery $delivery, string $status, ?string $failureCode = null): void
    {
        $before = $delivery->getOriginal('status');
        $delivery->status = $status;
        $delivery->failure_code = $failureCode;
        if ($status !== 'retryable') {
            $delivery->next_retry_at = null;
        }
        $delivery->save();
        if ($before !== $status) {
            AuditEvent::query()->create(['actor_id' => $delivery->requested_by, 'event_type' => 'delivery.status_changed',
                'subject_type' => 'card_delivery', 'subject_id' => $delivery->id,
                'before' => ['status' => $before], 'after' => ['status' => $status, 'attempt_count' => $delivery->attempt_count,
                    'failure_code' => $failureCode]]);
        }
    }
}
