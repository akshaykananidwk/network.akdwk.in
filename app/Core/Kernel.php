<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\ApiKeyMiddleware;
use App\Middleware\CoordinatorMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\MaintenanceMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RbacMiddleware;
use App\Middleware\SecurityHeadersMiddleware;

/**
 * Wires a request to a controller: resolve the route, run its middleware in
 * order, invoke the handler, and turn any exception into a response the caller
 * can understand.
 *
 * Error handling is the part worth reading. Two audiences are served from one
 * place: a browser gets a rendered page, an API client gets the standard JSON
 * envelope — and in production neither ever sees a stack trace or an internal
 * message. The full detail goes to storage/logs.
 */
final class Kernel
{
    public function __construct(private readonly Router $router)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            $route = $this->router->match($request->method(), $request->path());

            if ($route === null) {
                $allowed = $this->router->allowedMethods($request->path());

                if ($allowed !== []) {
                    return $this->errorResponse(
                        $request,
                        405,
                        'That action is not available on this URL.',
                        'method_not_allowed'
                    )->header('Allow', implode(', ', $allowed));
                }

                throw new NotFoundException('No route for ' . $request->method() . ' ' . $request->path());
            }

            foreach ($route['middleware'] as $middleware) {
                $response = $this->runMiddleware($middleware, $request);
                if ($response !== null) {
                    return SecurityHeadersMiddleware::apply($response, $request);
                }
            }

            $response = $this->invoke($route['handler'], $request, $route['params']);

            return SecurityHeadersMiddleware::apply($response, $request);
        } catch (\Throwable $e) {
            return SecurityHeadersMiddleware::apply($this->handleException($e, $request), $request);
        }
    }

    /** @param array<string,string> $params */
    private function invoke(mixed $handler, Request $request, array $params): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request, $params);
        } elseif (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $fqcn = str_starts_with($class, 'App\\') ? $class : 'App\\Controllers\\' . $class;

            if (!class_exists($fqcn)) {
                throw new AppException('Controller not found: ' . $fqcn);
            }

            $controller = new $fqcn();
            if (!method_exists($controller, $method)) {
                throw new AppException('Action not found: ' . $fqcn . '::' . $method);
            }

            $result = $controller->{$method}($request, $params);
        } else {
            throw new AppException('Route handler is not callable.');
        }

        if (!$result instanceof Response) {
            throw new AppException('Controller did not return a Response.');
        }

        return $result;
    }

    private function runMiddleware(string $middleware, Request $request): ?Response
    {
        // "can:network.create" carries its argument after the colon.
        $argument = '';
        if (str_contains($middleware, ':')) {
            [$middleware, $argument] = explode(':', $middleware, 2);
        }

        return match ($middleware) {
            'maintenance' => MaintenanceMiddleware::handle($request),
            'auth'        => AuthMiddleware::handle($request),
            'guest'       => GuestMiddleware::handle($request),
            'csrf'        => CsrfMiddleware::handle($request),
            'can'         => RbacMiddleware::handle($request, $argument),
            'api'         => ApiKeyMiddleware::handle($request),
            'device'      => ApiKeyMiddleware::handleDevice($request),
            'coordinator' => CoordinatorMiddleware::handle($request),
            'throttle'    => RateLimitMiddleware::handle($request, $argument !== '' ? $argument : 'default'),
            default       => throw new AppException('Unknown middleware: ' . $middleware),
        };
    }

    private function handleException(\Throwable $e, Request $request): Response
    {
        $isAppException = $e instanceof AppException;
        $status = $isAppException ? $e->statusCode() : 500;

        // A 5xx is our bug; a 4xx is the caller's. Log accordingly so the
        // error channel stays useful.
        if ($status >= 500) {
            Logger::error('app', 'Unhandled exception', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'path'      => $request->path(),
                'user_id'   => Auth::id(),
                'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
            ]);
        } else {
            Logger::info('app', 'Request rejected', [
                'exception' => $e::class,
                'status'    => $status,
                'message'   => $e->getMessage(),
                'path'      => $request->path(),
            ]);
        }

        if ($e instanceof ValidationException) {
            if ($request->wantsJson()) {
                return Response::apiError($e->userMessage(), 422, 'validation_failed', $e->errors());
            }

            Session::flash('errors', $e->errors());
            Session::flash('old', $request->all());
            Session::flash('error', $e->userMessage());

            $back = $request->header('Referer');

            return Response::redirect($back !== null && $this->isOwnUrl($back) ? $back : url('dashboard'));
        }

        if ($e instanceof RateLimitException) {
            return $this->errorResponse($request, 429, $e->userMessage(), 'rate_limited')
                ->header('Retry-After', (string) $e->retryAfter);
        }

        if ($e instanceof LimitExceededException) {
            if ($request->wantsJson()) {
                return Response::apiError($e->userMessage(), 402, 'limit_exceeded', ['upgrade' => $e->upgradeHint()]);
            }

            Session::flash('error', $e->userMessage() . ($e->upgradeHint() !== '' ? ' ' . $e->upgradeHint() : ''));
            $back = $request->header('Referer');

            return Response::redirect($back !== null && $this->isOwnUrl($back) ? $back : url('dashboard'));
        }

        $debug = (bool) Config::get('app.debug', false) && Config::get('app.env') !== 'production';

        $message = $isAppException
            ? $e->userMessage()
            : ($debug ? $e->getMessage() : 'Something went wrong on our side. The error has been logged.');

        return $this->errorResponse($request, $status, $message, '', $debug ? $e : null);
    }

    private function errorResponse(
        Request $request,
        int $status,
        string $message,
        string $code = '',
        ?\Throwable $debugException = null
    ): Response {
        if ($request->wantsJson()) {
            $details = [];
            if ($debugException !== null) {
                $details = [
                    'exception' => $debugException::class,
                    'file'      => $debugException->getFile() . ':' . $debugException->getLine(),
                ];
            }

            return Response::apiError($message, $status, $code, $details);
        }

        $template = match ($status) {
            403 => 'errors.403',
            404 => 'errors.404',
            429 => 'errors.429',
            default => 'errors.500',
        };

        try {
            $html = View::render($template, [
                'status'    => $status,
                'message'   => $message,
                'exception' => $debugException,
                'requestId' => Request::id(),
            ]);
        } catch (\Throwable) {
            // The error page itself failed — usually mid-update. Fall back to
            // something with no dependencies at all.
            $html = sprintf(
                '<!doctype html><html><head><meta charset="utf-8"><title>Error %1$d</title></head>'
                . '<body style="font-family:system-ui;padding:3rem;max-width:40rem;margin:0 auto">'
                . '<h1>Error %1$d</h1><p>%2$s</p><p style="color:#666;font-size:.85rem">Request %3$s</p></body></html>',
                $status,
                htmlspecialchars($message, ENT_QUOTES),
                htmlspecialchars(Request::id(), ENT_QUOTES)
            );
        }

        return Response::html($html, $status);
    }

    /** Only redirect back to our own host — an open redirect is a phishing tool. */
    private function isOwnUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return true; // a relative URL is ours by definition
        }

        return $host === parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
    }
}
