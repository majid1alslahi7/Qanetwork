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

final class MikroTikUserManagerAdapter implements ProviderAdapter
{
    public function __construct(
        private readonly RouterOsClient $client,
        private readonly RouterOsConnectionConfigFactory $configFactory,
    ) {}

    public function healthCheck(NetworkConnection $connection): bool
    {
        try {
            return $this->ready($this->configFactory->forConnection($connection));
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
        $config = $this->configFactory->forConnection($connection);
        if (! $this->ready($config)) {
            return [];
        }
        $reply = $this->client->execute($config, ['/user-manager/profile/print', '=.proplist=name']);
        $products = [];
        foreach (NetworkProduct::query()->where('network_id', $connection->network_id)
            ->where('status', 'active')->whereIn('external_product_id', array_column($reply->rows, 'name'))
            ->orderBy('id')->cursor() as $product) {
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

        return $this->ready($config) && $this->profileAvailable($config, $externalProductId);
    }

    public function purchaseCard(NetworkConnection $connection, PurchaseCardRequest $request): PurchaseCardResult
    {
        $this->validateTransactionId($request->internalTransactionId);
        $config = $this->configFactory->forConnection($connection);
        $username = $this->username($connection, $request->internalTransactionId);
        try {
            $rows = $this->findUser($config, $username);
            if ($rows !== []) {
                return $this->purchaseResult($this->verify($config, $username, $request->internalTransactionId, $rows, $request->externalProductId));
            }
            $exists = NetworkProduct::query()->where('network_id', $connection->network_id)
                ->where('external_product_id', $request->externalProductId)->where('status', 'active')->exists();
            if (! $exists || ! $this->ready($config) || ! $this->profileAvailable($config, $request->externalProductId)) {
                return PurchaseCardResult::failed(
                    errorCode: 'USER_MANAGER_UNAVAILABLE',
                    errorMessage: 'RouterOS 7 User Manager or the requested profile is unavailable.',
                );
            }

            $password = bin2hex(random_bytes(16));
            $marker = $this->marker($request->internalTransactionId, $username, $password, $request->externalProductId);
            $this->client->execute($config, [
                '/user-manager/user/add', '=name='.$username, '=password='.$password,
                '=comment='.$marker, '=group=default', '=shared-users=1', '=disabled=yes',
            ]);

            $users = $this->findUser($config, $username);
            if (count($users) !== 1 || ! $this->ownedUser($users[0], $username, $marker, 'true')) {
                return $this->purchaseResult($this->unresolved('User Manager creation requires manual review.'));
            }
            $userId = $users[0]['.id'];
            $this->client->execute($config, [
                '/user-manager/user-profile/add', '=user='.$username, '=profile='.$request->externalProductId,
            ]);
            $profiles = $this->findUserProfiles($config, $username);
            if (! $this->validProfile($config, $profiles, $username, $request->externalProductId)) {
                return $this->purchaseResult($this->unresolved('User Manager profile assignment requires manual review.'));
            }
            $this->client->execute($config, ['/user-manager/user/set', '=.id='.$userId, '=disabled=no']);

            return $this->purchaseResult($this->verify(
                $config, $username, $request->internalTransactionId, $this->findUser($config, $username),
                $request->externalProductId, $userId,
            ));
        } catch (RouterOsException $exception) {
            if ($exception->failure === RouterOsFailure::TIMEOUT) {
                return PurchaseCardResult::timeout(errorMessage: 'User Manager request timed out; reconciliation required.');
            }

            return PurchaseCardResult::unknown(errorMessage: 'User Manager request could not be confirmed; reconciliation required.');
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

            return $this->verify(
                $config, $username, $internalTransactionId, $this->findUser($config, $username),
                providerId: $providerTransactionId,
            );
        } catch (RouterOsException|InvalidArgumentException) {
            return $this->unresolved('User Manager status could not be checked; reconciliation required.');
        }
    }

    private function ready(RouterOsConnectionConfig $config): bool
    {
        $resource = $this->client->execute($config, ['/system/resource/print', '=.proplist=version']);
        if (count($resource->rows) !== 1 || ! preg_match('/\A7\./', $resource->rows[0]['version'] ?? '')) {
            return false;
        }
        $settings = $this->client->execute($config, ['/user-manager/print', '=.proplist=enabled,use-profiles']);

        return count($settings->rows) === 1 && ($settings->rows[0]['enabled'] ?? null) === 'true'
            && ($settings->rows[0]['use-profiles'] ?? null) === 'true';
    }

    private function profileAvailable(RouterOsConnectionConfig $config, string $profile): bool
    {
        if ($profile === '' || strlen($profile) > 191) {
            return false;
        }
        $reply = $this->client->execute($config, ['/user-manager/profile/print', '=.proplist=name', '?name='.$profile]);

        return count($reply->rows) === 1 && ($reply->rows[0]['name'] ?? null) === $profile;
    }

    /** @return list<array<string, string>> */
    private function findUser(RouterOsConnectionConfig $config, string $username): array
    {
        return $this->client->execute($config, [
            '/user-manager/user/print',
            '=.proplist=.id,name,password,comment,disabled,group,shared-users,otp-secret,attributes',
            '?name='.$username,
        ])->rows;
    }

    /** @return list<array<string, string>> */
    private function findUserProfiles(RouterOsConnectionConfig $config, string $username): array
    {
        return $this->client->execute($config, [
            '/user-manager/user-profile/print', '=.proplist=.id,user,profile,state,end-time', '?user='.$username,
        ])->rows;
    }

    /** @param list<array<string, string>> $users */
    private function verify(
        RouterOsConnectionConfig $config,
        string $username,
        string $transactionId,
        #[\SensitiveParameter] array $users,
        ?string $expectedProfile = null,
        ?string $providerId = null,
    ): TransactionStatusResult {
        if (! $this->ready($config)) {
            return $this->unresolved('User Manager is not ready; reconciliation required.');
        }
        if (count($users) !== 1 || ($providerId !== null && ($users[0]['.id'] ?? null) !== $providerId)) {
            return $this->unresolved('User Manager transaction was not uniquely found; manual review required.');
        }
        $profiles = $this->findUserProfiles($config, $username);
        $profile = $profiles[0]['profile'] ?? '';
        if (! $this->validProfile($config, $profiles, $username, $profile)
            || ($expectedProfile !== null && $expectedProfile !== $profile)) {
            return $this->unresolved('User Manager profile assignment requires manual review.');
        }
        $user = $users[0];
        $marker = $this->marker($transactionId, $username, $user['password'] ?? '', $profile);
        if (! $this->ownedUser($user, $username, $marker, 'false')) {
            return $this->unresolved('User Manager user verification requires manual review.');
        }

        return new TransactionStatusResult(
            status: ProviderTransactionStatus::CONFIRMED,
            providerTransactionId: $user['.id'],
            providerCardReference: $username,
            credentials: ['username' => $username, 'password' => $user['password']],
            providerStatus: 'user_manager_profile_verified',
        );
    }

    /** @param array<string, string> $user */
    private function ownedUser(#[\SensitiveParameter] array $user, string $username, string $marker, string $disabled): bool
    {
        return ($user['.id'] ?? '') !== '' && ($user['name'] ?? null) === $username
            && ($user['password'] ?? '') !== '' && hash_equals($marker, $user['comment'] ?? '')
            && ($user['disabled'] ?? null) === $disabled && ($user['group'] ?? null) === 'default'
            && ($user['shared-users'] ?? null) === '1'
            && ($user['otp-secret'] ?? '') === '' && ($user['attributes'] ?? '') === '';
    }

    /** @param list<array<string, string>> $profiles */
    private function validProfile(RouterOsConnectionConfig $config, array $profiles, string $username, string $profile): bool
    {
        if ($profile === '' || count($profiles) !== 1
            || ($profiles[0]['user'] ?? null) !== $username || ($profiles[0]['profile'] ?? null) !== $profile
            || ($profiles[0]['.id'] ?? '') === '') {
            return false;
        }
        if (in_array($profiles[0]['state'] ?? '', ['running', 'running active', 'running-active'], true)) {
            return true;
        }
        if (($profiles[0]['state'] ?? null) !== 'waiting'
            || ($profiles[0]['end-time'] ?? null) !== 'not-yet-running') {
            return false;
        }

        $reply = $this->client->execute($config, [
            '/user-manager/profile/print', '=.proplist=name,starts-when', '?name='.$profile,
        ]);

        return count($reply->rows) === 1 && ($reply->rows[0]['name'] ?? null) === $profile
            && ($reply->rows[0]['starts-when'] ?? null) === 'first-auth';
    }

    private function validateTransactionId(string $id): void
    {
        if (! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $id)) {
            throw new InvalidArgumentException('Invalid User Manager transaction identifier.');
        }
    }

    private function username(NetworkConnection $connection, string $id): string
    {
        return 'qu'.substr(hash('sha256', $connection->id.':'.$id), 0, 30);
    }

    private function marker(string $id, string $username, #[\SensitiveParameter] string $password, string $profile): string
    {
        return 'qanetwork:um:v1:'.$id.':'.hash('sha256', json_encode([$username, $password, $profile], JSON_THROW_ON_ERROR));
    }

    private function unresolved(string $message): TransactionStatusResult
    {
        return new TransactionStatusResult(
            status: ProviderTransactionStatus::RECONCILIATION_REQUIRED,
            errorCode: 'USER_MANAGER_VERIFICATION_REQUIRED', errorMessage: $message,
        );
    }

    private function purchaseResult(TransactionStatusResult $result): PurchaseCardResult
    {
        return new PurchaseCardResult(
            status: $result->status, providerTransactionId: $result->providerTransactionId,
            providerCardReference: $result->providerCardReference, credentials: $result->credentials,
            providerStatus: $result->providerStatus, errorCode: $result->errorCode, errorMessage: $result->errorMessage,
        );
    }
}
