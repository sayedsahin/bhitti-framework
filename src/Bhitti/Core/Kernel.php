<?php

declare(strict_types=1);

namespace Bhitti\Core;

use Bhitti\Http\Middleware\MiddlewareKernel;
use Bhitti\Http\Request;
use Bhitti\Http\Response;
use Bhitti\Routing\RouteDispatcher;
use Bhitti\Routing\Router;
use Bhitti\Session\SessionManager;

final class Kernel
{
    public function __construct(
        private MiddlewareKernel $middleware,
        private Router $router,
        private RouteDispatcher $dispatcher
    ) {
    }

    public function handle(Request $request): void
    {
        $isApi = $request->isApi();

        /* Handle kernel-level global middleware from config/middleware.php */

        $reqType = $isApi ? 'api' : 'web';
        $KernelMiddlewares = config('middleware.kernel.' . $reqType, []);
        $response = $this->middleware->handle($KernelMiddlewares);

        if ($response instanceof Response) {
            $response->send();
            return;
        }

        $routeInfo = $this->router->dispatch($request);

        $this->dispatcher->dispatch($routeInfo, $isApi);
    }
}