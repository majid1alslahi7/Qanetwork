<?php

namespace Tests\Unit\Providers\MikroTik\Fakes;

use App\Providers\MikroTik\RouterOsClient;
use App\Providers\MikroTik\RouterOsConnectionConfig;
use App\Providers\MikroTik\RouterOsException;
use App\Providers\MikroTik\RouterOsFailure;
use App\Providers\MikroTik\RouterOsReply;
use LogicException;

final class FakeUserManagerRouterOsClient implements RouterOsClient
{
    public string $version = '7.18';

    public bool $enabled = true;

    public bool $useProfiles = true;

    public array $profiles = ['day'];

    public array $users = [];

    public array $userProfiles = [];

    public array $commands = [];

    public ?string $failCommand = null;

    public RouterOsFailure $failure = RouterOsFailure::TIMEOUT;

    public bool $persistBeforeFailure = true;

    public string $assignedState = 'waiting';

    public string $startsWhen = 'first-auth';

    public bool $hidePasswords = false;

    public function execute(RouterOsConnectionConfig $config, #[\SensitiveParameter] array $words): RouterOsReply
    {
        $command = $words[0];
        $this->commands[] = $command;
        if ($command === $this->failCommand && ! $this->persistBeforeFailure) {
            throw new RouterOsException($this->failure);
        }
        $attributes = [];
        $query = [];
        foreach (array_slice($words, 1) as $word) {
            if (str_starts_with($word, '=') || str_starts_with($word, '?')) {
                [$key, $value] = explode('=', substr($word, 1), 2);
                if ($word[0] === '?') {
                    $query[$key] = $value;
                } else {
                    $attributes[$key] = $value;
                }
            }
        }
        $reply = match ($command) {
            '/system/resource/print' => new RouterOsReply([['version' => $this->version]], []),
            '/user-manager/print' => new RouterOsReply([[
                'enabled' => $this->enabled ? 'true' : 'false',
                'use-profiles' => $this->useProfiles ? 'true' : 'false',
            ]], []),
            '/user-manager/profile/print' => new RouterOsReply(array_values(array_map(
                fn ($name) => ['name' => $name, 'starts-when' => $this->startsWhen],
                array_filter($this->profiles, fn ($name) => ! isset($query['name']) || $query['name'] === $name),
            )), []),
            '/user-manager/user/print' => $this->readUsers($query['name']),
            '/user-manager/user-profile/print' => new RouterOsReply(array_values(array_filter(
                $this->userProfiles, fn ($row) => $row['user'] === $query['user'],
            )), []),
            '/user-manager/user/add' => $this->addUser($attributes),
            '/user-manager/user-profile/add' => $this->addProfile($attributes),
            '/user-manager/user/set' => $this->setUser($attributes),
            default => throw new LogicException('Unexpected test User Manager command.'),
        };
        if ($command === $this->failCommand) {
            throw new RouterOsException($this->failure);
        }

        return $reply;
    }

    private function readUsers(string $name): RouterOsReply
    {
        $rows = array_values(array_filter($this->users, fn ($row) => $row['name'] === $name));
        if ($this->hidePasswords) {
            $rows = array_map(function ($row) {
                unset($row['password']);

                return $row;
            }, $rows);
        }

        return new RouterOsReply($rows, []);
    }

    private function addUser(array $attributes): RouterOsReply
    {
        foreach ($this->users as $user) {
            if ($user['name'] === $attributes['name']) {
                throw new RouterOsException(RouterOsFailure::COMMAND_REJECTED);
            }
        }
        $id = '*'.(count($this->users) + 1);
        $this->users[] = [...$attributes, '.id' => $id,
            'disabled' => ($attributes['disabled'] ?? 'no') === 'yes' ? 'true' : 'false',
            'attributes' => '', 'otp-secret' => ''];

        return new RouterOsReply([], ['ret' => $id]);
    }

    private function addProfile(array $attributes): RouterOsReply
    {
        $id = '*p'.(count($this->userProfiles) + 1);
        $this->userProfiles[] = [...$attributes, '.id' => $id, 'state' => $this->assignedState,
            'end-time' => $this->assignedState === 'waiting' ? 'not-yet-running' : ''];

        return new RouterOsReply([], ['ret' => $id]);
    }

    private function setUser(array $attributes): RouterOsReply
    {
        foreach ($this->users as &$user) {
            if ($user['.id'] === $attributes['.id']) {
                $user['disabled'] = $attributes['disabled'] === 'no' ? 'false' : 'true';
            }
        }

        return new RouterOsReply([], []);
    }
}
