<?php

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\Http;

/**
 * Sends one WhatsApp template message through Meta's WhatsApp Cloud API. A business may start a
 * conversation only with a template Meta has approved, so the text lives in that template (the
 * person's first name fills {{1}}). Without the three WHATSAPP_* settings this is switched off.
 */
class WhatsApp
{
    public static function enabled(): bool
    {
        return (bool) (config('services.whatsapp.token') && config('services.whatsapp.phone_id') && config('services.whatsapp.template'));
    }

    /** Digits only with the country code first; a bare 10-digit number is taken as Indian. */
    public static function normalise(?string $phone): ?string
    {
        $digits = ltrim((string) preg_replace('/\D+/', '', (string) $phone), '0');

        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        return strlen($digits) >= 11 && strlen($digits) <= 15 ? $digits : null;
    }

    /** @return bool whether WhatsApp accepted the message */
    public static function sendTemplate(string $phone, string $firstName): bool
    {
        $to = self::normalise($phone);
        if (! self::enabled() || $to === null) {
            return false;
        }

        $response = Http::withToken((string) config('services.whatsapp.token'))
            ->timeout(15)
            ->post('https://graph.facebook.com/v21.0/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => config('services.whatsapp.template'),
                    'language' => ['code' => config('services.whatsapp.language', 'en')],
                    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $firstName]]]],
                ],
            ]);

        if (! $response->successful()) {
            report(new \RuntimeException('WhatsApp send failed: '.$response->status().' '.$response->body()));
        }

        return $response->successful();
    }
}
