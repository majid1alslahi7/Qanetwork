<?php

namespace App\Providers\MikroTik;

use InvalidArgumentException;

final class RouterOsCodec
{
    public const MAX_WORD_BYTES = 1048576;

    public function encodeLength(int $length): string
    {
        if ($length < 0 || $length > 0xFFFFFFFF) {
            throw new InvalidArgumentException('Invalid RouterOS word length.');
        }
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            return pack('n', $length | 0x8000);
        }
        if ($length < 0x200000) {
            return substr(pack('N', $length | 0xC00000), 1);
        }
        if ($length < 0x10000000) {
            return pack('N', $length | 0xE0000000);
        }

        return "\xF0".pack('N', $length);
    }

    public function readLength(RouterOsTransport $transport): int
    {
        $first = ord($transport->read(1));
        if ($first < 0x80) {
            return $first;
        }
        if ($first < 0xC0) {
            return (($first & 0x3F) << 8) | ord($transport->read(1));
        }
        if ($first < 0xE0) {
            return (($first & 0x1F) << 16) | unpack('n', $transport->read(2))[1];
        }
        if ($first < 0xF0) {
            return (($first & 0x0F) << 24) | unpack('N', "\0".$transport->read(3))[1];
        }
        if ($first === 0xF0) {
            return unpack('N', $transport->read(4))[1];
        }

        throw new RouterOsException(RouterOsFailure::PROTOCOL);
    }

    /** @param list<string> $words */
    public function encodeSentence(#[\SensitiveParameter] array $words): string
    {
        $bytes = '';
        foreach ($words as $word) {
            if (! is_string($word) || $word === '' || strlen($word) > self::MAX_WORD_BYTES) {
                throw new InvalidArgumentException('Invalid RouterOS command word.');
            }
            $bytes .= $this->encodeLength(strlen($word)).$word;
            if (strlen($bytes) > 4 * self::MAX_WORD_BYTES) {
                throw new InvalidArgumentException('RouterOS command exceeds the size limit.');
            }
        }

        return $bytes."\0";
    }

    /** @return list<string> */
    public function readSentence(RouterOsTransport $transport): array
    {
        $words = [];
        $total = 0;
        while (true) {
            $length = $this->readLength($transport);
            if ($length === 0) {
                return $words;
            }
            $total += $length;
            if ($length > self::MAX_WORD_BYTES || $total > 4 * self::MAX_WORD_BYTES || count($words) >= 4096) {
                throw new RouterOsException(RouterOsFailure::PROTOCOL);
            }
            $words[] = $transport->read($length);
        }
    }
}
