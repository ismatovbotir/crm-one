<?php

namespace App\Http\Controllers;

use App\Support\OpenApi\OneCIntegrationSpec;
use App\Support\OpenApi\YamlEmitter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * 1C integration API documentation (Swagger UI at /api/docs, raw spec at /api/docs/openapi.json
 * and .yaml). Open without login only when APP_ENV=local; in every other environment only signed-in
 * staff with the `integration.docs` permission (administrators and the IT department). The spec holds example values only;
 * "Try it out" is disabled in the UI, so no token is ever entered or embedded here.
 */
class ApiDocsController extends Controller
{
    public function ui(Request $request): View|RedirectResponse
    {
        if (($denied = $this->guard($request)) !== null) {
            return $denied;
        }

        return view('api-docs', ['specUrl' => route('api-docs.json')]);
    }

    public function json(Request $request, OneCIntegrationSpec $spec): JsonResponse|RedirectResponse
    {
        if (($denied = $this->guard($request)) !== null) {
            return $denied;
        }

        return response()->json($spec->build(), 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function yaml(Request $request, OneCIntegrationSpec $spec): Response|RedirectResponse
    {
        if (($denied = $this->guard($request)) !== null) {
            return $denied;
        }

        return response(YamlEmitter::dump($spec->build()), 200, ['Content-Type' => 'application/yaml; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    protected function guard(Request $request): ?RedirectResponse
    {
        if (app()->environment('local')) {
            return null;
        }

        if ($request->user() === null) {
            return redirect()->guest(route('login'));
        }

        abort_unless(Gate::allows('viewApiDocs'), 403);

        return null;
    }
}
