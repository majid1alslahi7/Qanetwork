<?php

namespace App\Services\Delivery;

use App\Enums\UserRole;
use App\Jobs\SendCardDeliveryJob;
use App\Models\AuditEvent;
use App\Models\CardDelivery;
use App\Models\User;
use App\Services\Delivery\Contracts\SmsSender;
use App\Services\Sales\PaidSaleCardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RequestCardDeliveryService
{
    public function __construct(private readonly PaidSaleCardService $cards, private readonly SmsSender $sender) {}

    /** @param array{recipient: string, idempotency_key: string} $data */
    public function handle(User $actor, string $saleId, array $data): CardDelivery
    {
        if (! preg_match('/\A\+[1-9][0-9]{7,14}\z/', $data['recipient'])) {
            throw ValidationException::withMessages(['recipient' => 'Use an international phone number beginning with +.']);
        }

        return DB::transaction(function () use ($actor, $saleId, $data): CardDelivery {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($currentActor->role !== UserRole::SELLER || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $sale = $currentActor->seller()->firstOrFail()->sales()->lockForUpdate()->findOrFail($saleId);
            $this->cards->card($sale);
            $key = 'card-delivery:'.$sale->id.':'.hash('sha256', $data['idempotency_key']);
            $existing = $sale->delivery()->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->idempotency_key !== $key || $existing->recipient_encrypted !== $data['recipient']) {
                    throw new ConflictHttpException('A delivery request already exists for this sale.');
                }
                if ($existing->status === 'awaiting_configuration' && $this->sender->available()) {
                    $existing->status = 'queued';
                    $existing->save();
                }
                if ($existing->status === 'queued') {
                    SendCardDeliveryJob::dispatch($existing->id)->afterCommit();
                }

                return $existing;
            }
            $delivery = new CardDelivery(['sale_id' => $sale->id, 'requested_by' => $actor->id,
                'idempotency_key' => $key, 'channel' => 'sms', 'recipient_hint' => '****'.substr($data['recipient'], -4)]);
            $delivery->recipient_encrypted = $data['recipient'];
            $delivery->status = $this->sender->available() ? 'queued' : 'awaiting_configuration';
            $delivery->save();
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'delivery.requested',
                'subject_type' => 'card_delivery', 'subject_id' => $delivery->id,
                'after' => ['sale_id' => $sale->id, 'channel' => 'sms', 'status' => $delivery->status]]);
            if ($delivery->status === 'queued') {
                SendCardDeliveryJob::dispatch($delivery->id)->afterCommit();
            }

            return $delivery;
        }, 3);
    }
}
