<?php

namespace Tests\Unit\Providers\MikroTik\Fakes;

use App\Providers\MikroTik\RouterOsClient;
use App\Providers\MikroTik\RouterOsConnectionConfig;
use App\Providers\MikroTik\RouterOsException;
use App\Providers\MikroTik\RouterOsFailure;
use App\Providers\MikroTik\RouterOsReply;
use LogicException;

final class FakeHotspotRouterOsClient implements RouterOsClient
{
    public array $profiles = ['day'];

    public array $users = [];

    public array $commands = [];

    public ?RouterOsFailure $addFailure = null;

    public ?RouterOsFailure $readFailure = null;

    public bool $hidePasswords = false;

    public bool $persistBeforeFailure = true;

    public array $lastAdd = [];

    public array $addOverrides = [];

    public function execute(RouterOsConnectionConfig $config, #[\SensitiveParameter] array $words): RouterOsReply
    {
        $command = $words[0];
        $this->commands[] = $command;
        $attributes = [];
        $queryName = null;
        foreach (array_slice($words, 1) as $word) {
            if (str_starts_with($word, '?name=')) {
                $queryName = substr($word, 6);
            } elseif (str_starts_with($word, '=')) {
                [$key, $value] = explode('=', substr($word, 1), 2);
                $attributes[$key] = $value;
            }
        }
        if ($command === '/system/resource/print') {
            return new RouterOsReply([['version' => '7.18']], []);
        }
        if ($command === '/ip/hotspot/user/profile/print') {
            $profiles = array_filter($this->profiles, fn ($name) => $queryName === null || $name === $queryName);

            return new RouterOsReply(array_values(array_map(fn ($name) => ['name' => $name], $profiles)), []);
        }
        if ($command === '/ip/hotspot/user/print') {
            if ($this->readFailure !== null) {
                throw new RouterOsException($this->readFailure);
            }
            $rows = array_values(array_filter($this->users, fn ($row) => $row['name'] === $queryName));
            if ($this->hidePasswords) {
                $rows = array_map(function ($row) {
                    unset($row['password']);

                    return $row;
                }, $rows);
            }

            return new RouterOsReply($rows, []);
        }
        if ($command === '/ip/hotspot/user/add') {
            $this->lastAdd = $attributes;
            foreach ($this->users as $user) {
                if ($user['name'] === $attributes['name']) {
                    throw new RouterOsException(RouterOsFailure::COMMAND_REJECTED);
                }
            }
            if ($this->addFailure === null || $this->persistBeforeFailure) {
                $this->users[] = [
                    'server' => 'all',
                    'limit-bytes-total' => '0',
                    'limit-uptime' => '0s',
                    ...$attributes,
                    '.id' => '*'.(count($this->users) + 1),
                    'disabled' => 'false',
                    ...$this->addOverrides,
                ];
            }
            if ($this->addFailure !== null) {
                throw new RouterOsException($this->addFailure);
            }

            return new RouterOsReply([], ['ret' => end($this->users)['.id']]);
        }

        throw new LogicException('Unexpected test RouterOS command.');
    }
}
