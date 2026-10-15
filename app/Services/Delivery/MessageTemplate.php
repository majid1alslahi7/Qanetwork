<?php

namespace App\Services\Delivery;

class MessageTemplate
{
    /** @param list<string> $lines */
    public static function format(string $title, array $lines, ?string $network = null): string
    {
        $name = $network === null ? '' : mb_substr(preg_replace('/[\r\n]+/u', ' ', trim($network)), 0, 100);
        $header = $name === '' ? 'QaNetwork' : (preg_match('/\Aشبكة(?:\s|$)/u', $name) ? $name : 'شبكة '.$name);

        return $header."\n".$title."\n".implode("\n", $lines)."\nخدمة QaNetwork";
    }
}
