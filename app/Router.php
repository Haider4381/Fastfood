<?php
class Router {
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void {
        $this->routes[] = [$method, "#^{$pattern}$#", $handler];
    }

    public function dispatch(string $method, string $uri) {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        foreach ($this->routes as [$m, $regex, $handler]) {
            if (strcasecmp($method, $m) === 0 && preg_match($regex, $path, $matches)) {
                array_shift($matches);
                return $handler(...$matches);
            }
        }
        json_error('Not Found', 404);
    }
}