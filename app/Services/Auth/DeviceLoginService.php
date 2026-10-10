<?php

namespace App\Services\Auth;

use App\Models\AccountDevice;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceLoginService
{
    public function assertAllowed(User $user, ?AccountDevice $device): void
    {
        $code = match (true) {
            $device !== null && ($device->user_id !== $user->id || $device->status === 'blocked') => 'device_blocked',
            $user->require_device_approval && $device === null => 'device_proof_required',
            $user->require_device_approval && $device->status !== 'approved' => 'device_pending',
            default => null,
        };
        if ($code !== null) {
            throw new HttpResponseException(response()->json(['message' => 'Device access is unavailable.', 'code' => $code], 403)->header('Cache-Control', 'no-store'));
        }
    }

    /** @return array{id: string, payload: string, expires_at: string} */
    public function challenge(User $user, string $publicKey, string $deviceName, ?string $imei): array
    {
        $key = openssl_pkey_get_public($publicKey);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw ValidationException::withMessages(['public_key' => 'A P-256 device signing key is required.']);
        }
        $id = (string) Str::uuid();
        $payload = "QaNetwork/device-login/v1\n".$id."\n".bin2hex(random_bytes(32));
        $expires = now()->addMinutes(2);
        Cache::put('device-challenge:'.$id, ['user_id' => $user->id, 'public_key' => $details['key'], 'device_name' => $deviceName,
            'imei' => $imei === null ? null : Crypt::encryptString($imei), 'payload' => $payload], $expires);

        return ['id' => $id, 'payload' => $payload, 'expires_at' => $expires->toISOString()];
    }

    public function verify(User $user, string $challengeId, string $encodedSignature): AccountDevice
    {
        try {
            $challenge = Cache::lock('device-challenge-lock:'.$challengeId, 10)->block(3, fn () => Cache::pull('device-challenge:'.$challengeId));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['device_signature' => 'The device challenge is unavailable. Request a new challenge.']);
        }
        $signature = base64_decode($encodedSignature, true);
        if (! is_array($challenge) || $challenge['user_id'] !== $user->id || $signature === false
            || strlen($signature) > 128 || openssl_verify($challenge['payload'], $signature, $challenge['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            throw ValidationException::withMessages(['device_signature' => 'Device proof is invalid or expired.']);
        }

        return DB::transaction(function () use ($user, $challenge): AccountDevice {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($current->canAccessApplication(), 403);
            $fingerprint = hash('sha256', $challenge['public_key']);
            $device = AccountDevice::query()->where('user_id', $current->id)->where('fingerprint', $fingerprint)->lockForUpdate()->first();
            $imei = $challenge['imei'] === null ? null : Crypt::decryptString($challenge['imei']);
            if ($device === null) {
                if (AccountDevice::query()->where('user_id', $current->id)->count() >= 20) {
                    throw ValidationException::withMessages(['device_name' => 'This account has reached its device registration limit.']);
                }
                $device = new AccountDevice(['user_id' => $current->id, 'fingerprint' => $fingerprint, 'public_key' => $challenge['public_key'],
                    'device_name' => $challenge['device_name'], 'imei' => $imei, 'status' => 'pending']);
                $device->last_seen_at = now();
                $device->save();
                AuditEvent::query()->create(['actor_id' => $current->id, 'event_type' => 'device.registered', 'subject_type' => 'account_device', 'subject_id' => $device->id,
                    'after' => ['user_id' => $current->id, 'fingerprint' => $fingerprint, 'status' => 'pending']]);
            } else {
                if ($imei !== null && $device->imei !== $imei) {
                    $device->imei = $imei;
                    if ($device->status === 'approved') {
                        $device->status = 'pending';
                        $device->approved_at = null;
                        $device->approved_by = null;
                        $current->tokens()->where('account_device_id', $device->id)->delete();
                    }
                    AuditEvent::query()->create(['actor_id' => $current->id, 'event_type' => 'device.imei_changed', 'subject_type' => 'account_device', 'subject_id' => $device->id,
                        'after' => ['status' => $device->status, 'imei_recorded' => true]]);
                }
                $device->last_seen_at = now();
                $device->save();
            }

            return $device;
        }, 3);
    }
}
