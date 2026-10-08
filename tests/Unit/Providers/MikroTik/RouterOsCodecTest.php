<?php

namespace Tests\Unit\Providers\MikroTik;

use App\Providers\MikroTik\RouterOsCodec;
use App\Providers\MikroTik\RouterOsException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Providers\MikroTik\Fakes\BufferedRouterOsTransport;

class RouterOsCodecTest extends TestCase
{
    #[DataProvider('lengths')]
    public function test_lengths_match_protocol_bytes(int $length, string $hex): void
    {
        $codec = new RouterOsCodec;

        $this->assertSame($hex, bin2hex($codec->encodeLength($length)));
        $this->assertSame($length, $codec->readLength(new BufferedRouterOsTransport(hex2bin($hex))));
    }

    public static function lengths(): array
    {
        return [
            [0, '00'], [127, '7f'], [128, '8080'], [16383, 'bfff'],
            [16384, 'c04000'], [2097151, 'dfffff'], [2097152, 'e0200000'],
            [268435455, 'efffffff'], [268435456, 'f010000000'], [4294967295, 'f0ffffffff'],
        ];
    }

    public function test_multibyte_words_are_framed_by_byte_length(): void
    {
        $codec = new RouterOsCodec;
        $words = ['!re', '=name=شبكة', '=password=a=b'];

        $this->assertSame($words, $codec->readSentence(new BufferedRouterOsTransport($codec->encodeSentence($words))));
    }

    public function test_reserved_control_byte_is_rejected(): void
    {
        $this->expectException(RouterOsException::class);

        (new RouterOsCodec)->readLength(new BufferedRouterOsTransport("\xF8"));
    }

    public function test_oversized_response_is_rejected_before_reading_payload(): void
    {
        $codec = new RouterOsCodec;
        $this->expectException(RouterOsException::class);

        $codec->readSentence(new BufferedRouterOsTransport($codec->encodeLength(RouterOsCodec::MAX_WORD_BYTES + 1)));
    }

    public function test_empty_command_word_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RouterOsCodec)->encodeSentence(['/print', '']);
    }
}
