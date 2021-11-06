<?php

/**
 * Copyright (C) 2020 Tencent Cloud.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Discuz\Api;

use App\Common\Utils;
use Discuz\Api\Controller\AbstractSerializeController;
use Discuz\Api\Events\ApiExceptionRegisterHandler;
use Discuz\Api\Events\ConfigMiddleware;
use Discuz\Api\ExceptionHandler\FallbackExceptionHandler;
use Discuz\Api\ExceptionHandler\LoginFailedExceptionHandler;
use Discuz\Api\ExceptionHandler\LoginFailuresTimesToplimitExceptionHandler;
use Discuz\Api\ExceptionHandler\NotAuthenticatedExceptionHandler;
use Discuz\Api\ExceptionHandler\PermissionDeniedExceptionHandler;
use Discuz\Api\ExceptionHandler\RouteNotFoundExceptionHandler;
use Discuz\Api\ExceptionHandler\ServiceResponseExceptionHandler;
use Discuz\Api\ExceptionHandler\TencentCloudSDKExceptionHandler;
use Discuz\Api\ExceptionHandler\ValidationExceptionHandler;
use Discuz\Api\Listeners\AutoResisterApiExceptionRegisterHandler;
use Discuz\Api\Middleware\HandlerErrors;
use Discuz\Api\Middleware\InstallMiddleware;
use Discuz\Foundation\Application;
use Discuz\Http\Middleware\AuthenticateWithHeader;
use Discuz\Http\Middleware\CheckoutSite;
use Discuz\Http\Middleware\CheckUserStatus;
use Discuz\Http\Middleware\DispatchRoute;
use Discuz\Http\Middleware\ParseJsonBody;
use Discuz\Http\Middleware\OptionsRequest;
use Discuz\Http\RouteCollection;
use Illuminate\Support\ServiceProvider;
use Tobscure\JsonApi\ErrorHandler;
use Laminas\Stratigility\MiddlewarePipe;

class ApiServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('discuz.api.middleware', function (Application $app) {
            $pipe = new MiddlewarePipe();

            if (!$this->app->isInstall()) {
                $pipe->pipe($app->make(InstallMiddleware::class));
                return $pipe;
            }

            $pipe->pipe($app->make(HandlerErrors::class));
            $pipe->pipe($app->make(OptionsRequest::class));
            $pipe->pipe($app->make(ParseJsonBody::class));
            $pipe->pipe($app->make(AuthenticateWithHeader::class));
            $pipe->pipe($app->make(CheckoutSite::class));
            $pipe->pipe($app->make(CheckUserStatus::class));

            $app->make('events')->dispatch(new ConfigMiddleware($pipe));

            return $pipe;
        });

        $this->app->singleton(ErrorHandler::class, function (Application $app) {
            $errorHandler = new ErrorHandler;
            $errorHandler->registerHandler(new RouteNotFoundExceptionHandler());
            $errorHandler->registerHandler(new ValidationExceptionHandler());
            $errorHandler->registerHandler(new NotAuthenticatedExceptionHandler());
            $errorHandler->registerHandler(new PermissionDeniedExceptionHandler());
            $errorHandler->registerHandler(new TencentCloudSDKExceptionHandler());
            $errorHandler->registerHandler(new ServiceResponseExceptionHandler());
            $errorHandler->registerHandler(new LoginFailuresTimesToplimitExceptionHandler());
            $errorHandler->registerHandler(new LoginFailedExceptionHandler());

            $app->make('events')->dispatch(new ApiExceptionRegisterHandler($errorHandler));

            $errorHandler->registerHandler(new FallbackExceptionHandler($app->config('debug')));
            return $errorHandler;
        });

        // 保证路由中间件最后执行
        $this->app->afterResolving('discuz.api.middleware', function (MiddlewarePipe $pipe) {
            $pipe->pipe($this->app->make(DispatchRoute::class));
        });
    }

    public function boot()
    {
        $this->populateRoutes($this->app->make(RouteCollection::class));

        $this->app->make('events')->listen(ApiExceptionRegisterHandler::class, AutoResisterApiExceptionRegisterHandler::class);

        AbstractSerializeController::setContainer($this->app);
    }

    protected function populateRoutes(RouteCollection $route)
    {
        $reqUri = $_SERVER['REQUEST_URI'] ?? '';
        if (empty($reqUri)) return;
        preg_match("/(?<=plugin\/)[a-z|A-Z]+?(?=\/api)/", $reqUri, $m);
        $pluginName = $m[0] ?? '';
        $adminApiPrefix = '/api/backAdmin';
        $userApiPrefix = '/api';
        $userApiV3Prefix = '/apiv3';
        $userApiV3PrefixAlias = '/api/v3';
        $pluginApiPrefix = '/plugin/' . $pluginName . '/api';
        if ($this->matchPrefix($reqUri, $adminApiPrefix)) {
            $route->group($adminApiPrefix, function (RouteCollection $route) {
                require $this->app->basePath('routes/apiadmin.php');
            });
        } else if ($this->matchPrefix($reqUri, $userApiV3Prefix)) {
            $route->group($userApiV3Prefix, function (RouteCollection $route) {
                require $this->app->basePath('routes/apiv3.php');
            });
        } else if ($this->matchPrefix($reqUri, $userApiV3PrefixAlias)) {
            $route->group($userApiV3PrefixAlias, function (RouteCollection $route) {
                require $this->app->basePath('routes/apiv3.php');
            });
        } else if ($this->matchPrefix($reqUri, $userApiPrefix)) {
            $route->group($userApiPrefix, function (RouteCollection $route) {
                require $this->app->basePath('routes/api.php');
            });
        } else if ($this->matchPrefix($reqUri, $pluginApiPrefix)) {
//            $this->setPluginRoutes($route,$plugins, $pluginName);
        } else {
            $route->group($userApiPrefix, function (RouteCollection $route) {
                require $this->app->basePath('routes/api.php');
            });
        }
        $plugins = \Discuz\Common\Utils::getPluginList();
        //加载插件所有接口
        $this->setPluginRoutes($route, $plugins, $pluginName);
    }


    private function setPluginRoutes(RouteCollection $route, $plugins, $pluginName)
    {
        foreach ($plugins as $plugin) {
            $prefix = '/plugin/' . $plugin['name_en'] . '/api/';
            $route->group($prefix, function (RouteCollection $route) use ($plugin) {
                $pluginFiles = $plugin['plugin_' . $plugin['app_id']];
                Utils::setPluginAppId($plugin['app_id']);
                if (isset($pluginFiles['routes'])) {
                    foreach ($pluginFiles['routes'] as $routeFile) {
                        require_once $routeFile;
                    }
                }
            });
        }
    }

    private function matchPrefix($uri, $prefix)
    {
        $p = '/' . $prefix;//兼容前端错误的url拼接
        return ($uri & $prefix) == $prefix || ($uri & $p) == $p;
    }
}
