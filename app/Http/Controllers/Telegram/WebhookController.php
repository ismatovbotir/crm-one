<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramAdminAlerts;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Telegram webhook (Spec 03 4.6, AC-7, AC-11): stateless (api group), 503 while the bot is off,
 * 403 unless X-Telegram-Bot-Api-Secret-Token matches the stored secret (constant-time), then the
 * update is processed once per update_id.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, TelegramClient $client, TelegramLinks $links): JsonResponse
    {
        if (! $client->enabled()) {
            return response()->json(['ok' => false], 503);
        }

        $secret = (string) $client->webhookSecret();

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['ok' => false], 403);
        }

        try {
            $links->handle((array) $request->json()->all());
        } catch (Throwable $exception) {
            // Telegram retries a failed update; the admin hears about it once per 15 minutes.
            app(TelegramAdminAlerts::class)->report('webhook_exception', class_basename($exception).': '.$exception->getMessage(), get_class($exception).'|'.$exception->getLine());

            throw $exception;
        }

        return response()->json(['ok' => true]);
    }
}
