<?php

namespace App\Services\Delivery;

class YemenPhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $number = strtr(trim($phone), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $number = preg_replace('/[\s()-]/u', '', $number);
        $number = preg_replace('/\A(?:\+967|00967|967|0)/', '', $number);

        return preg_match('/\A(?:70|71|73|77)[0-9]{7}\z/', $number) === 1 ? '+967'.$number : null;
    }
}
