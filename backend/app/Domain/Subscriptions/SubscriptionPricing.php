<?php

namespace App\Domain\Subscriptions;

/** The monthly price, GST and total, always in whole paise so nothing is rounded twice. */
final class SubscriptionPricing
{
    public static function priceMinor(): int
    {
        return (int) config('subscription.price_minor');
    }

    public static function gstMinor(): int
    {
        return intdiv(self::priceMinor() * (int) config('subscription.gst_percent') + 50, 100);
    }

    public static function totalMinor(): int
    {
        return self::priceMinor() + self::gstMinor();
    }

    /**
     * Splits an amount that already includes GST back into price and GST.
     *
     * @return array{price: int, gst: int, total: int}
     */
    public static function splitTotal(int $totalMinor): array
    {
        $price = (int) round($totalMinor * 100 / (100 + (int) config('subscription.gst_percent')));

        return ['price' => $price, 'gst' => $totalMinor - $price, 'total' => $totalMinor];
    }

    /** @return array<string, mixed> */
    public static function toApi(): array
    {
        return [
            'price' => self::priceMinor() / 100,
            'gst_percent' => (int) config('subscription.gst_percent'),
            'gst' => self::gstMinor() / 100,
            'total' => self::totalMinor() / 100,
            'currency' => config('subscription.currency'),
            'interval' => 'month',
        ];
    }
}
