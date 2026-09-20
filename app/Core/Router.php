<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Route table with per-route middleware.
 *
 * Patterns use {name} placeholders, optionally typed: {id:int}, {uid:hex},
 * {slug:slug}. Typing at the route level means a controller never has to
 * re-validate an id it received from the router.
 */
final class Router
{
    /** @var array<string, list<array{regex:string,params:list<string>,handler:mixed,middleware:list<string>,name:string}>> */
    private array $routes = [];

    /** @var list<string> */
    private array $groupMiddleware = [];
    private string $groupPrefix = '';

    /** @var array<string,string> route name => pattern, for url() generation */
    private array $named = [];

    private const TYPE_PATTERNS = [
        'int'  => '[0-9]+',
        'hex'  => '[0-9a-fA-F]+',
        'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        'any'  => '[^/]+',
    ];

    /**
     * @param list<string> $middleware
     * @param callable(Router):void $callback
     */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = rtrim($previousPrefix . '/' . trim($prefix, '/'), '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /** @param list<string> $middleware */
    public function get(string $path, mixed $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('GET', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function post(string $path, mixed $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('POST', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function put(string $path, mixed $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('PUT', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function patch(string $path, mixed $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('PATCH', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function delete(string $path, mixed $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('DELETE', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function add(string $method, string $path, mixed $handler, array $middleware = [], string $name = ''): void
    {
        $full = $this->groupPrefix . '/' . ltrim($path, '/');
        $full = '/' . trim($full, '/');
        if ($full !== '/') {
            $full = rtrim($full, '/');
        }

        [$regex, $params] = $this->compile($full);

        $this->routes[$method][] = [
            'regex'      => $regex,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_values(array_unique(array_merge($this->groupMiddleware, $middleware))),
            'name'       => $name,
        ];

        if ($name !== '') {
            $this->named[$name] = $full;
        }
    }

    /**
     * @return array{handler:mixed,params:array<string,string>,middleware:list<string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name] ?? '';
            }

            return [
                'handler'    => $route['handler'],
                'params'     => $params,
                'middleware' => $route['middleware'],
            ];
        }

        return null;
    }

    /**
     * Which methods would have matched this path? Lets the dispatcher answer
     * 405 with a correct Allow header instead of a misleading 404.
     *
     * @return list<string>
     */
    public function allowedMethods(string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }

    /** @param array<string,string|int> $params */
    public function url(string $name, array $params = []): string
    {
        $pattern = $this->named[$name] ?? '/';
        foreach ($params as $key => $value) {
            $pattern = (string) preg_replace('/\{' . preg_quote((string) $key, '/') . '(?::[a-z]+)?\}/', rawurlencode((string) $value), $pattern);
        }

        return rtrim((string) Config::get('app.base_path', ''), '/') . $pattern;
    }

    /** @return array{0:string,1:list<string>} */
    private function compile(string $path): array
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-z]+))?\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                $type = $m[2] ?? 'any';
                $pattern = self::TYPE_PATTERNS[$type] ?? self::TYPE_PATTERNS['any'];

                return '(?P<' . $m[1] . '>' . $pattern . ')';
            },
            $path
        );

        return ['#^' . $regex . '$#', $params];
    }
}
