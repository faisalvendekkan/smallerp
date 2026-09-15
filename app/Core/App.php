<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\ValidationException;

/**
 * The application kernel: boots configuration, dispatches a request and turns
 * whatever comes back -- a Response, an exception, a validation failure --
 * into something the browser can render.
 */
final class App
{
    private static string $basePath = '';
    private static string $currentPath = '/';
    private static ?Router $router = null;

    public static function boot(string $root): void
    {
        Config::load($root);

        error_reporting(E_ALL);
        ini_set('display_errors', Config::isDebug() ? '1' : '0');
        ini_set('log_errors', '1');
        $logFile = $root . '/storage/logs/error.log';
        if (is_dir(dirname($logFile))) {
            ini_set('error_log', $logFile);
        }
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    /** The path being served, used to highlight the active navigation link. */
    public static function currentPath(): string
    {
        return self::$currentPath;
    }

    public static function router(): Router
    {
        if (self::$router === null) {
            self::$router = require Config::root() . '/app/routes.php';
        }

        return self::$router;
    }

    public static function handle(Request $request): Response
    {
        self::$basePath = $request->basePath();
        self::$currentPath = $request->path();

        try {
            Auth::startSession($request);
            Lang::boot((string) Config::get('default_locale', 'en'));

            // The installer runs before anything else if the schema is absent,
            // so a fresh upload to a hosting account walks the user through
            // setup instead of showing a database error.
            if (!Database::tableExists('users')) {
                return self::runInstaller($request);
            }

            $route = self::router()->match($request->method(), $request->path());
            if ($route === null) {
                throw HttpException::notFound();
            }

            $request->setRouteParams($route['params']);

            // Everything except the public routes needs a signed-in user.
            $permission = $route['permission'];
            if ($permission !== 'public') {
                if (!Auth::check()) {
                    if ($request->wantsJson()) {
                        return Response::json(['error' => 'Not signed in'], 401);
                    }
                    $_SESSION['intended_url'] = $request->path();

                    return Response::redirect(url('/login'));
                }
                Auth::authorise($permission);
            }

            // Any state change must carry a valid CSRF token.
            if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                Csrf::verify($request);
            }

            $GLOBALS['__old_input'] = View::takeOldInput();

            return self::callHandler($route['handler'], $request);
        } catch (ValidationException $e) {
            return self::handleValidationException($e, $request);
        } catch (HttpException $e) {
            return self::renderError($e->status(), $e->getMessage(), $request);
        } catch (\Throwable $e) {
            error_log('SmallERP error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            if (Config::isDebug()) {
                return Response::text(
                    $e::class . ': ' . $e->getMessage() . "\n\n"
                    . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString(),
                    500
                );
            }

            return self::renderError(500, 'Something went wrong. The error has been logged.', $request);
        }
    }

    /**
     * Invoke a route handler, which is either a closure or
     * `[ControllerClass::class, 'method']`.
     */
    private static function callHandler(mixed $handler, Request $request): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            $result = $controller->{$method}($request);
        } else {
            $result = $handler($request);
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (is_array($result)) {
            return Response::json($result);
        }

        throw new \RuntimeException('A route handler returned something that is not a response.');
    }

    /**
     * A failed validation sends the user back to the form with their input and
     * the error, rather than to a dead-end error page.
     */
    private static function handleValidationException(ValidationException $e, Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json([
                'error' => $e->getMessage(),
                'field' => $e->field,
                'errors' => $e->allMessages(),
            ], 422);
        }

        $messages = $e->allMessages();
        View::flash(
            count($messages) > 1
                ? $e->getMessage() . "\n• " . implode("\n• ", $messages)
                : $messages[0],
            'error'
        );
        View::flashInput($request->all());

        return Response::redirect(url($request->backUrl('/')));
    }

    private static function renderError(int $status, string $message, Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }

        try {
            $layout = Auth::check() ? 'layout/app' : 'layout/blank';

            return Response::html(
                View::render('errors/error', [
                    'status' => $status,
                    'message' => $message,
                    'title' => 'Error ' . $status,
                ], $layout),
                $status
            );
        } catch (\Throwable) {
            return Response::text("Error {$status}: {$message}", $status);
        }
    }

    /** Show the first-run installer when the database has no schema yet. */
    private static function runInstaller(Request $request): Response
    {
        $controller = new \App\Controllers\InstallController();

        if ($request->path() === '/install' && $request->isPost()) {
            return $controller->run($request);
        }

        return $controller->show($request);
    }
}
