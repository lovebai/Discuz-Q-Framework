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

namespace Discuz\Http;

use Discuz\Common\Utils;
use Discuz\Foundation\Application;
use Discuz\Foundation\SiteApp;
use Discuz\Http\Middleware\RequestHandler;
use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\Stratigility\Middleware\ErrorResponseGenerator;
use Laminas\Stratigility\MiddlewarePipe;

class Server extends SiteApp
{
    public function listen()
    {
        try {
            $app = $this->siteBoot(true);
            $this->includePluginRoutes($app->make(RouteCollection::class));
        } catch (QueryException $e) {
            // 忽略，避免由于数据库引起的启动错误，导致页面无法显示
        } catch (Throwable $e) {
            exit($this->formatBootException($e));
        }
        $this->isDebug();
        $pipe = new MiddlewarePipe();

        $pipe->pipe(new RequestHandler([
            '/plugin'=>'discuz.api.middleware',
            '/api' => 'discuz.api.middleware',
            '/' => 'discuz.web.middleware'
        ], $this->app));

        $request = ServerRequestFactory::fromGlobals();

        $this->app->instance('request', $request);
        $this->app->alias('request', ServerRequestInterface::class);

        $runner = new RequestHandlerRunner(
            $pipe,
            new SapiEmitter,
            function () use ($request) {
                return $request;
            },
            function (Throwable $e) {
                $generator = new ErrorResponseGenerator;
                return $generator($e, new ServerRequest(), new Response());
            }
        );

        $runner->run();

        //增加性能日志
        //$this->addPerformanceLog();
    }
    /**
     * @desc 一次性加载所有插件的路由文件
     * @param RouteCollection $route
     * @return RouteCollection
     */
    private  function includePluginRoutes(RouteCollection $route){
        $plugins = Utils::getPluginList();
        foreach ($plugins as $plugin) {
            $prefix = '/plugin/' . $plugin['name_en'] . '/api/';
            $route->group($prefix, function (RouteCollection $route) use ($plugin) {
                $pluginFiles = $plugin['plugin_' . $plugin['app_id']];
                \App\Common\Utils::setPluginAppId($plugin['plugin_dirname'], $plugin['app_id']);
                if (isset($pluginFiles['routes'])) {
                    foreach ($pluginFiles['routes'] as $routeFile) {
                        require_once $routeFile;
                    }
                }
            });
        }
        $route->get('/{other:.*}', 'other', \App\Http\Controller\IndexController::class);
        Utils::setRouteMap($route->getRouteData());
        return $route;
    }

    private function isDebug()
    {
        if (app()->config('debug')) {//dev
            error_reporting(E_ALL);
            ini_set('display_errors', 'On');
        } else {//prod
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', 'Off');
        }
    }

    /**
     * Display the most relevant information about an early exception.
     * @param Throwable $error
     * @return string
     */
    private function formatBootException(Throwable $error): string
    {
        $message = $error->getMessage();
        $file = $error->getFile();
        $line = $error->getLine();
        $type = get_class($error);
        $this->app->make('log')->error($error);
        $trace = $error->getTraceAsString();
        return <<<ERROR
            Discuz Q! encountered a boot error ($type)<br />
            thrown in <b>$file</b> on line <b>$line</b><br/>
            <b>$message</b><br/>$trace

ERROR;
    }

    protected function addPerformanceLog()
    {
        $this->app->make('performancelog')->info(json_encode([
            'app_version' => Application::VERSION,
            'opcache_enable' => function_exists('opcache_get_status') ? opcache_get_status(true) : false,
            'response_time' => microtime(true) - DISCUZ_START.'s',
            'include_files' => count(get_included_files()),
            'memory_use' => $this->memory_usage(),
            'api_path' => $this->app->make('request')->getUri()->getPath(),
        ]));
    }

    private function memory_usage()
    {
        return (! function_exists('memory_get_usage')) ? '0' : round(memory_get_usage()/1024/1024, 2).'MB';
    }
}
