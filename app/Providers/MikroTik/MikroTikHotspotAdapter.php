<?php

namespace App\Providers\MikroTik;

use App\Models\NetworkConnection;
use App\Models\NetworkProduct;
use App\Providers\Contracts\ProviderAdapter;
use App\Providers\Data\ProviderProduct;
use App\Providers\Data\PurchaseCardRequest;
use App\Providers\Data\PurchaseCardResult;
use App\Providers\Data\TransactionStatusResult;
use App\Providers\Enums\ProviderTransactionStatus;
use InvalidArgumentException;

final class MikroTikHotspotAdapter implements ProviderAdapter
{
    public function __construct(
        private readonly RouterOsClient $client,
        private readonly RouterOsConnectionConfigFactory $configFactory,
    ) {}

    public function healthCheck(NetworkConnection $connection): bool
    {
        try {
            $reply = $this->client->execute($this->configFactory->forConnection($connection), [
                '/system/resource/print', '=.proplist=version,uptime',
            ]);

            return count($reply->rows) === 1 && ($reply->rows[0]['version'] ?? '') !== '';
        } catch (RouterOsException|InvalidArgumentException) {
            return false;
        }
    }

    public function getBalance(NetworkConnection $connection): ?string
    {
        return null;
    }

    public function getProducts(NetworkConnection $connection): array
    {
        $reply = $this->client->execute($this->configFactory->forConnection($connection), [
            '/ip/hotspot/user/profile/print', '=.proplist=name',
        ]);
        $profiles = array_column($reply->rows, 'name');
        $products = [];
        foreach (NetworkProduct::query()->where('network_id', $connection->network_id)
            ->where('status', 'active')->whereIn('external_product_id', $profiles)->orderBy('id')->cursor() as $product) {
            $products[] = new ProviderProduct(
                externalProductId: $product->external_product_id,
                name: $product->display_name ?? $product->name,
                faceValue: $product->face_value,
                currencyCode: $product->currency_code,
            );
        }

        return $products;
    }

    public function checkAvailability(NetworkConnection $connection, string $externalProductId): bool
    {
        $config = $this->configFactory->forConnection($connection);

        return $this->profileAvailable($config, $externalProductId);
    }

    public function purchaseCard(NetworkConnection $connection, PurchaseCardRequest $request): PurchaseCardResult
    {
        $this->validateTransactionId($request->internalTransactionId);
        $config = $this->configFactory->forConnection($connection);
        $username = $this->username($connection, $request->internalTransactionId);
        $marker = $this->marker($request->internalTransactionId);
        try {
            $rows = $this->findUser($config, $username);
            if ($rows !== []) {
                return $this->purchaseResult($this->verifiedUser($rows, $username, $marker, $request->externalProductId));
            }

            $product = NetworkProduct::query()
                ->where('network_id', $connection->network_id)
                ->where('external_product_id', $request->externalProductId)
                ->where('status', 'active')->first();
            if (! $product || ! $this->profileAvailable($config, $request->externalProductId)) {
                return PurchaseCardResult::failed(errorCode: 'HOTSPOT_PRODUCT_UNAVAILABLE', errorMessage: 'Hotspot product is unavailable.');
            }

            $limits = $this->issuanceLimits($product);
            $password = bin2hex(random_bytes(16));
            $intent = ['name' => $username, 'password' => $password, 'profile' => $request->externalProductId,
                'server' => 'all', 'limit-bytes-total' => '0', 'limit-uptime' => '0s'];
            foreach ($limits as $word) {
                [$key, $value] = explode('=', substr($word, 1), 2);
                $intent[$key] = $value;
            }
            $issuanceMarker = $marker.':'.$this->issuanceFingerprint($intent);
            $words = [
                '/ip/hotspot/user/add',
                '=name='.$username,
                '=password='.$password,
                '=profile='.$request->externalProductId,
                '=comment='.$issuanceMarker,
                '=disabled=no',
                ...$limits,
            ];
            $this->client->execute($config, $words);
            $rows = $this->findUser($config, $username);
            $result = $this->verifiedUser($rows, $username, $marker, $request->externalProductId);
            if ($result->status === ProviderTransactionStatus::CONFIRMED
                && (! hash_equals($password, $result->credentials['password'])
                    || ! $this->limitsMatch($rows[0], $limits))) {
                return PurchaseCardResult::unknown(errorMessage: 'Hotspot verification requires manual review.');
            }

            return $this->purchaseResult($result);
        } catch (RouterOsException $exception) {
            if ($exception->failure === RouterOsFailure::TIMEOUT) {
                return PurchaseCardResult::timeout(errorMessage: 'Hotspot request timed out; reconciliation required.');
            }

            return PurchaseCardResult::unknown(errorMessage: 'Hotspot request could not be confirmed; reconciliation required.');
        }
    }

    public function checkTransaction(
        NetworkConnection $connection,
        string $internalTransactionId,
        ?string $providerTransactionId = null,
    ): TransactionStatusResult {
        $this->validateTransactionId($internalTransactionId);
        try {
            $config = $this->configFactory->forConnection($connection);
            $username = $this->username($connection, $internalTransactionId);
            $rows = $this->findUser($config, $username);
            $result = $this->verifiedUser($rows, $username, $this->marker($internalTransactionId));
            if ($result->status === ProviderTransactionStatus::CONFIRMED
                && $providerTransactionId !== null && $providerTransactionId !== $result->providerTransactionId) {
                return $this->unresolved('Hotspot transaction identity requires manual review.');
            }

            return $result;
        } catch (RouterOsException|InvalidArgumentException) {
            return $this->unresolved('Hotspot status could not be checked; reconciliation required.');
        }
    }

    private function profileAvailable(RouterOsConnectionConfig $config, string $profile): bool
    {
        if ($profile === '' || strlen($profile) > 191) {
            return false;
        }
        $reply = $this->client->execute($config, [
            '/ip/hotspot/user/profile/print', '=.proplist=name', '?name='.$profile,
        ]);

        return count($reply->rows) === 1 && ($reply->rows[0]['name'] ?? null) === $profile;
    }

    /** @return list<array<string, string>> */
    private function findUser(RouterOsConnectionConfig $config, string $username): array
    {
        return $this->client->execute($config, [
            '/ip/hotspot/user/print',
            '=.proplist=.id,name,password,comment,profile,disabled,limit-bytes-total,limit-uptime,server',
            '?name='.$username,
        ])->rows;
    }

    /** @param list<array<string, string>> $rows */
    private function verifiedUser(
        #[\SensitiveParameter] array $rows,
        string $username,
        string $marker,
        ?string $profile = null,
    ): TransactionStatusResult {
        if (count($rows) !== 1) {
            return $this->unresolved('Hotspot transaction was not uniquely found; reconciliation required.');
        }
        $row = $rows[0];
        $fingerprint = $this->issuanceFingerprint($row);
        if (($row['name'] ?? null) !== $username || $fingerprint === null
            || ! hash_equals($marker.':'.$fingerprint, $row['comment'] ?? '')
            || ($row['.id'] ?? '') === '' || ($row['password'] ?? '') === ''
            || ($row['profile'] ?? '') === '' || ($row['disabled'] ?? null) !== 'false'
            || ($profile !== null && $row['profile'] !== $profile)) {
            return $this->unresolved('Hotspot verification requires manual review.');
        }

        return new TransactionStatusResult(
            status: ProviderTransactionStatus::CONFIRMED,
            providerTransactionId: $row['.id'],
            providerCardReference: $username,
            credentials: ['username' => $username, 'password' => $row['password']],
            providerStatus: 'hotspot_user_verified',
        );
    }

    /** @return list<string> */
    private function issuanceLimits(NetworkProduct $product): array
    {
        $settings = $product->metadata['hotspot'] ?? [];
        if (! is_array($settings)) {
            throw new InvalidArgumentException('Invalid Hotspot issuance settings.');
        }
        $words = [];
        foreach (['limit_bytes_total' => 'limit-bytes-total', 'limit_uptime_seconds' => 'limit-uptime'] as $key => $attribute) {
            if (array_key_exists($key, $settings)) {
                $value = $settings[$key];
                if (! is_int($value) || $value < 1) {
                    throw new InvalidArgumentException('Hotspot limits must be positive integers.');
                }
                $words[] = '='.$attribute.'='.$value.($key === 'limit_uptime_seconds' ? 's' : '');
            }
        }
        if ($words === [] && ($settings['allow_unlimited'] ?? false) !== true) {
            throw new InvalidArgumentException('Hotspot issuance requires explicit limits or unlimited approval.');
        }
        if (isset($settings['server'])) {
            if (! is_string($settings['server']) || trim($settings['server']) === '') {
                throw new InvalidArgumentException('Invalid Hotspot server setting.');
            }
            $words[] = '=server='.$settings['server'];
        }

        return $words;
    }

    private function validateTransactionId(string $id): void
    {
        if (! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $id)) {
            throw new InvalidArgumentException('Invalid Hotspot transaction identifier.');
        }
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $limits
     */
    private function limitsMatch(#[\SensitiveParameter] array $row, array $limits): bool
    {
        foreach ($limits as $word) {
            [$key, $expected] = explode('=', substr($word, 1), 2);
            $actual = $row[$key] ?? null;
            if ($actual === null) {
                return false;
            }
            if ($key === 'limit-uptime') {
                if ($this->durationSeconds($actual) !== (int) substr($expected, 0, -1)) {
                    return false;
                }
            } elseif ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function durationSeconds(string $duration): ?int
    {
        if (preg_match('/\A(?:(\d{1,8})w)?(?:(\d{1,8})d)?(\d{1,8}):(\d{2}):(\d{2})\z/', $duration, $parts)) {
            if ((int) $parts[4] > 59 || (int) $parts[5] > 59) {
                return null;
            }

            return (int) $parts[1] * 604800 + (int) $parts[2] * 86400
                + (int) $parts[3] * 3600 + (int) $parts[4] * 60 + (int) $parts[5];
        }
        if ($duration !== '' && preg_match('/\A(?:(\d{1,8})w)?(?:(\d{1,8})d)?(?:(\d{1,8})h)?(?:(\d{1,8})m)?(?:(\d{1,8})s)?\z/', $duration, $parts)) {
            return (int) ($parts[1] ?? 0) * 604800 + (int) ($parts[2] ?? 0) * 86400
                + (int) ($parts[3] ?? 0) * 3600 + (int) ($parts[4] ?? 0) * 60 + (int) ($parts[5] ?? 0);
        }

        return null;
    }

    /** @param array<string, string> $row */
    private function issuanceFingerprint(#[\SensitiveParameter] array $row): ?string
    {
        foreach (['name', 'password', 'profile', 'server', 'limit-bytes-total', 'limit-uptime'] as $key) {
            if (! isset($row[$key]) || ! is_string($row[$key])) {
                return null;
            }
        }
        $uptime = $this->durationSeconds($row['limit-uptime']);
        if ($uptime === null || ! preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $row['limit-bytes-total'])) {
            return null;
        }

        return hash('sha256', json_encode([
            $row['name'], $row['password'], $row['profile'], $row['server'],
            $row['limit-bytes-total'], $uptime,
        ], JSON_THROW_ON_ERROR));
    }

    private function username(NetworkConnection $connection, string $id): string
    {
        return 'qa'.substr(hash('sha256', $connection->id.':'.$id), 0, 30);
    }

    private function marker(string $id): string
    {
        return 'qanetwork:v1:'.$id;
    }

    private function unresolved(string $message): TransactionStatusResult
    {
        return new TransactionStatusResult(
            status: ProviderTransactionStatus::RECONCILIATION_REQUIRED,
            errorCode: 'HOTSPOT_VERIFICATION_REQUIRED',
            errorMessage: $message,
        );
    }

    private function purchaseResult(TransactionStatusResult $result): PurchaseCardResult
    {
        return new PurchaseCardResult(
            status: $result->status,
            providerTransactionId: $result->providerTransactionId,
            providerCardReference: $result->providerCardReference,
            credentials: $result->credentials,
            providerStatus: $result->providerStatus,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
        );
    }
}
