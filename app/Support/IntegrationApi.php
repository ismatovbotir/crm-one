<?php

namespace App\Support;

use App\Models\IntegrationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Helpers shared by the 1C inbound API (Spec 02 section 9): request id, the authenticated token
 * on the request, and the error envelope `{ "error": { code, message, fields?, request_id } }`.
 */
final class IntegrationApi
{
    public const TOKEN_ATTRIBUTE = 'integration_token';

    public const REQUEST_ID_ATTRIBUTE = 'integration_request_id';

    public const ERROR_CODE_ATTRIBUTE = 'integration_error_code';

    public const BATCH_ATTRIBUTE = 'integration_batch_id';

    /**
     * The X-Request-Id sent by 1C when it is a UUID, otherwise a new one (stored on the request).
     */
    public static function requestId(Request $request): string
    {
        $existing = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        if (is_string($existing)) {
            return $existing;
        }

        $sent = (string) $request->headers->get('X-Request-Id', '');
        $id = Str::isUuid($sent) ? strtolower($sent) : (string) Str::uuid();
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $id);

        return $id;
    }

    public static function token(Request $request): ?IntegrationToken
    {
        $token = $request->attributes->get(self::TOKEN_ATTRIBUTE);

        return $token instanceof IntegrationToken ? $token : null;
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, string>  $headers
     */
    public static function error(Request $request, int $status, string $code, string $message, array $fields = [], array $headers = []): JsonResponse
    {
        $request->attributes->set(self::ERROR_CODE_ATTRIBUTE, $code);

        $error = ['code' => $code, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        $error['request_id'] = self::requestId($request);

        return response()->json(['error' => $error], $status, $headers);
    }
}
