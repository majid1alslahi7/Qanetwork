<?php

namespace App\Services\Accounting;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\NetworkOwner;
use App\Models\ProviderSettlement;
use App\Models\ProviderSettlementAllocation;
use App\Models\SaleAccountingEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RecordProviderSettlementService
{
    /** @param array<string, mixed> $data */
    public function handle(User $actor, NetworkOwner $owner, array $data): ProviderSettlement
    {
        if (! is_string($data['amount'] ?? null) || ! preg_match('/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?\z/', $data['amount'])
            || bccomp($data['amount'], '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => 'The settlement amount must be a positive decimal.']);
        }

        return DB::transaction(function () use ($actor, $owner, $data): ProviderSettlement {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            NetworkOwner::query()->lockForUpdate()->findOrFail($owner->id);
            $key = 'settlement:'.$owner->id.':'.hash('sha256', $data['idempotency_key']);
            $paidAt = CarbonImmutable::parse($data['paid_at'])->utc();
            $existing = $owner->settlements()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->currency_code !== $data['currency_code'] || bccomp($existing->amount, $data['amount'], 4) !== 0
                    || $existing->payment_method !== $data['payment_method'] || $existing->external_reference !== $data['external_reference']
                    || ! $existing->paid_at->equalTo($paidAt) || $existing->notes !== ($data['notes'] ?? null)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different settlement.');
                }
                $allocated = '0.0000';
                foreach ($existing->allocations()->with('entry')->lazyById(100) as $allocation) {
                    if ($allocation->entry->network_owner_id !== $owner->id || $allocation->entry->entry_type !== 'provider_payable'
                        || $allocation->entry->currency_code !== $existing->currency_code || bccomp($allocation->amount, '0', 4) <= 0) {
                        throw new RuntimeException('Recorded settlement allocation is inconsistent.');
                    }
                    $allocated = bcadd($allocated, $allocation->amount, 4);
                }
                if (bccomp($allocated, $existing->amount, 4) !== 0) {
                    throw new RuntimeException('Recorded settlement allocations do not equal its amount.');
                }

                return $existing;
            }
            if ($owner->settlements()->where('currency_code', $data['currency_code'])->where('payment_method', $data['payment_method'])
                ->where('external_reference', $data['external_reference'])->exists()) {
                throw ValidationException::withMessages(['external_reference' => 'This payment has already been recorded.']);
            }
            $settlement = ProviderSettlement::query()->create(['network_owner_id' => $owner->id, 'reference_no' => 'PAY-'.Str::ulid(),
                'amount' => bcadd($data['amount'], '0', 4), 'currency_code' => $data['currency_code'],
                'payment_method' => $data['payment_method'], 'external_reference' => $data['external_reference'],
                'idempotency_key' => $key, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id, 'paid_at' => $paidAt]);
            $remaining = bcadd($data['amount'], '0', 4);
            $entries = SaleAccountingEntry::query()->where('network_owner_id', $owner->id)->where('entry_type', 'provider_payable')
                ->where('currency_code', $data['currency_code'])->lockForUpdate()->with('settlementAllocations')->lazyById(100);
            foreach ($entries as $entry) {
                $allocated = '0.0000';
                foreach ($entry->settlementAllocations as $allocation) {
                    $allocated = bcadd($allocated, $allocation->amount, 4);
                }
                $available = bcsub($entry->amount, $allocated, 4);
                if (bccomp($available, '0', 4) < 0) {
                    throw new RuntimeException('Provider payable allocations exceed their recorded amount.');
                }
                if (bccomp($available, '0', 4) === 0) {
                    continue;
                }
                $amount = bccomp($remaining, $available, 4) <= 0 ? $remaining : $available;
                ProviderSettlementAllocation::query()->create(['provider_settlement_id' => $settlement->id,
                    'sale_accounting_entry_id' => $entry->id, 'amount' => $amount]);
                $remaining = bcsub($remaining, $amount, 4);
                if (bccomp($remaining, '0', 4) === 0) {
                    break;
                }
            }
            if (bccomp($remaining, '0', 4) !== 0) {
                throw ValidationException::withMessages(['amount' => 'The settlement exceeds the outstanding provider payable in this currency.']);
            }
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'settlement.recorded',
                'subject_type' => 'provider_settlement', 'subject_id' => $settlement->id,
                'after' => ['network_owner_id' => $owner->id, 'amount' => $settlement->amount, 'currency_code' => $settlement->currency_code]]);

            return $settlement;
        }, 3);
    }
}
