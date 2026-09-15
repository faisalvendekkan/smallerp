<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A small routing table.
 *
 * Routes are declared as `/invoices/{id}/print`; the `{name}` segments become
 * route parameters on the Request. Each route also carries the permission
 * required to reach it, so authorisation is declared next to the URL rather
 * than repeated at the top of every controller method.
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,keys:string[],handler:mixed,permission:?string,name:?string}> */
    private array $routes = [];

    public function get(string $path, mixed $handler, ?string $permission = null, ?string $name = null): self
    {
        return $this->add('GET', $path, $handler, $permission, $name);
    }

    public function post(string $path, mixed $handler, ?string $permission = null, ?string $name = null): self
    {
        return $this->add('POST', $path, $handler, $permission, $name);
    }

    public function add(string $method, string $path, mixed $handler, ?string $permission = null, ?string $name = null): self
    {
        $keys = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$keys): string {
                $keys[] = $m[1];

                return '([^/]+)';
            },
            $path
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'keys' => $keys,
            'handler' => $handler,
            'permission' => $permission,
            'name' => $name,
            'path' => $path,
        ];

        return $this;
    }

    /**
     * Find the route matching a request.
     *
     * @return array{handler:mixed,params:array<string,string>,permission:?string}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $pathMatchedOtherMethod = false;
        $best = null;
        $bestKeyCount = PHP_INT_MAX;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $pathMatchedOtherMethod = true;
                continue;
            }

            // Prefer the most specific match rather than the first declared:
            // /contacts/export must win over /contacts/{id}, whichever order
            // they happen to be registered in.
            $keyCount = count($route['keys']);
            if ($keyCount >= $bestKeyCount) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['keys'] as $i => $key) {
                $params[$key] = urldecode($matches[$i]);
            }

            $best = [
                'handler' => $route['handler'],
                'params' => $params,
                'permission' => $route['permission'],
            ];
            $bestKeyCount = $keyCount;

            if ($keyCount === 0) {
                break; // An exact literal match cannot be bettered.
            }
        }

        if ($best !== null) {
            return $best;
        }

        if ($pathMatchedOtherMethod) {
            throw new HttpException(405, 'That action does not accept this request method.');
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public function routes(): array
    {
        return $this->routes;
    }
}
