<?php
/*
 * Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved.
 *
 * Proprietary and confidential. This software is LICENSED, NOT SOLD.
 * Unauthorized copying, modification, distribution, or use of this file,
 * via any medium, is strictly prohibited and will be prosecuted to the
 * fullest extent of the law (17 U.S.C. Sections 501-505). See LICENSE.txt.
 * Licensing: licensing@wkrllc.com
 */
declare(strict_types=1);

final class App
{
    private static array $cfg = [];

    public static function boot(array $cfg): void
    {
        self::$cfg = $cfg;

        // Pin the clock before anything can read it. Left to php.ini this
        // inherits whatever the host happens to ship — local PHP bundles often
        // default to a European timezone — which would date document numbers
        // and authorization timestamps hours away from the business's own day.
        date_default_timezone_set($cfg['company']['tz'] ?? 'America/Los_Angeles');
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$cfg[$key] ?? $default;
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach (Db::all('SELECT skey, svalue FROM settings') as $r) {
                $cache[$r['skey']] = $r['svalue'];
            }
        }
        return $cache[$key] ?? $default;
    }

    public static function taxRate(): float
    {
        return (float) self::setting('tax_rate', (string) (self::config('rules')['default_tax_rate'] ?? 0));
    }
}

final class Auth
{
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user']) ? (int) $_SESSION['user']['id'] : null;
    }

    public static function role(): string
    {
        return $_SESSION['user']['role'] ?? 'GUEST';
    }

    public static function check(): bool
    {
        $id = (int) ($_SESSION['user']['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }

        $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$id]);
        if (!$user) {
            unset($_SESSION['user']);
            return false;
        }

        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        return true;
    }

    public static function is(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    public static function attempt(string $email, string $password): bool
    {
        $u = Db::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [strtolower(trim($email))]);
        if (!$u || !password_verify($password, $u['password_hash'])) { return false; }
        unset($u['password_hash']);
        session_regenerate_id(true);
        $_SESSION['user'] = $u;
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['user']);
        session_regenerate_id(true);
    }

    public static function require(): void
    {
        if (!self::check()) { redirect('/login'); }
    }

    /** Technicians see only their own work; dispatch cannot touch settings/users/catalog writes. */
    public static function requireRole(string ...$roles): void
    {
        self::require();
        if (!self::is(...$roles)) {
            http_response_code(403);
            View::render('pages/403', ['title' => 'Not permitted']);
            exit;
        }
    }
}

final class Router
{
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $pattern, callable $fn): void  { $this->routes['GET'][$pattern] = $fn; }
    public function post(string $pattern, callable $fn): void { $this->routes['POST'][$pattern] = $fn; }

    public function dispatch(string $method, string $path): void
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') { $path = '/'; }

        foreach ($this->routes[$method] ?? [] as $pattern => $fn) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (preg_match($regex, $path, $m)) {
                $args = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                $fn($args);
                return;
            }
        }
        http_response_code(404);
        View::render('pages/404', ['title' => 'Not found']);
    }
}

final class View
{
    public static function render(string $tpl, array $data = []): void
    {
        $data['__tpl'] = $tpl;
        extract($data, EXTR_SKIP);
        $viewFile = __DIR__ . '/Views/' . $tpl . '.php';
        if (!is_file($viewFile)) { throw new RuntimeException("View not found: $tpl"); }

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if (!empty($data['__bare'])) { echo $content; return; }
        require __DIR__ . '/Views/layouts/app.php';
    }

    public static function partial(string $tpl, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require __DIR__ . '/Views/' . $tpl . '.php';
    }
}
