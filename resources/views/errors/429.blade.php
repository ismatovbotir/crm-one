@php($retryAfter = isset($exception) && method_exists($exception, 'getHeaders') ? ($exception->getHeaders()['Retry-After'] ?? null) : null)
@include('errors.layout', ['code' => 429, 'extra' => is_numeric($retryAfter) ? __('errors.retry_after', ['seconds' => (int) $retryAfter]) : null])
