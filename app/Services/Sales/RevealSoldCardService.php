<?php

namespace App\Services\Sales;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RevealSoldCardService
{
    public function __construct(private readonly PaidSaleCardService $cards) {}

    /** @return array{sale_id: string, credentials: array<string, string>, first_revealed_at: string} */
    public function handle(User $actor, string $saleId): array
    {
        return DB::transaction(function () use ($actor, $saleId): array {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($currentActor->role !== UserRole::SELLER || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $sale = $currentActor->seller()->firstOrFail()->sales()->lockForUpdate()->findOrFail($saleId);
            $card = $this->cards->card($sale);
            $credentials = $this->cards->credentials($card);
            $firstReveal = $card->first_revealed_at === null;
            if ($firstReveal) {
                $card->first_revealed_at = now();
                $card->save();
            }
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'card.revealed',
                'subject_type' => 'sold_card', 'subject_id' => $card->id,
                'after' => ['sale_id' => $sale->id, 'first_reveal' => $firstReveal]]);

            return ['sale_id' => $sale->id, 'credentials' => $credentials,
                'first_revealed_at' => $card->first_revealed_at->toIso8601String()];
        }, 3);
    }
}
