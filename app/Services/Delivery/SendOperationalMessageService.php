<?php

namespace App\Services\Delivery;

use App\Enums\SmsSubmissionStatus;
use App\Models\OperationalMessage;
use App\Services\Delivery\Data\SmsSubmissionResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendOperationalMessageService
{
    public function __construct(private readonly QuickConnectSmsSender $sender) {}

    public function handle(string $id): void
    {
        $record = OperationalMessage::query()->findOrFail($id);
        if (! config('services.quick_connect.operational_enabled', false) || ! $this->sender->available()) {
            return;
        }
        if ($record->status === 'submitted' && $record->provider_reference !== null) {
            try {
                $status = $this->sender->status($record->provider_reference);
            } catch (Throwable) {
                $status = null;
            }
            DB::transaction(function () use ($id, $status): void {
                $locked = OperationalMessage::query()->lockForUpdate()->findOrFail($id);
                if ($locked->status === 'submitted') {
                    if (in_array($status, ['sent', 'failed'], true)) {
                        $locked->status = $status;
                        $locked->failure_code = $status === 'failed' ? 'provider_delivery_failed' : null;
                    }
                    $locked->updated_at = now();
                    $locked->save();
                }
            }, 3);

            return;
        }
        $payload = DB::transaction(function () use ($id): ?array {
            $locked = OperationalMessage::query()->lockForUpdate()->findOrFail($id);
            if ($locked->status === 'sending' && $locked->started_at?->lte(now()->subMinutes(2))) {
                $locked->status = 'unknown';
                $locked->failure_code = 'interrupted_submission';
                $locked->save();
            }
            if ($locked->status !== 'queued') {
                return null;
            }
            $payload = ['recipient' => $locked->recipient, 'body' => $locked->body];
            $locked->status = 'sending';
            $locked->started_at = now();
            $locked->save();

            return $payload;
        }, 3);
        if ($payload === null) {
            return;
        }
        try {
            $result = $this->sender->sendText($payload['recipient'], $payload['body']);
        } catch (Throwable) {
            $result = new SmsSubmissionResult(SmsSubmissionStatus::UNKNOWN);
        }
        DB::transaction(function () use ($id, $result): void {
            $locked = OperationalMessage::query()->lockForUpdate()->findOrFail($id);
            if ($locked->status !== 'sending') {
                return;
            }
            $locked->provider_reference = $result->providerReference;
            $locked->status = match ($result->status) {
                SmsSubmissionStatus::ACCEPTED => 'submitted',
                SmsSubmissionStatus::REJECTED => 'failed',
                default => 'unknown',
            };
            $locked->failure_code = match ($locked->status) {
                'failed' => 'provider_rejected', 'unknown' => 'uncertain_submission', default => null,
            };
            $locked->save();
        }, 3);
    }
}
