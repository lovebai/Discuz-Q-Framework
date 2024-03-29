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

namespace Discuz\Http\Middleware;

use Discuz\Http\Exception\MethodNotAllowedException;
use Discuz\Http\Exception\RouteNotFoundException;
use Discuz\Http\GroupCountBased;
use Discuz\Http\RouteCollection;
use Discuz\Http\RouteHandlerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use FastRoute\Dispatcher;

class DispatchRoute implements MiddlewareInterface
{
    /**
     * @var RouteCollection
     */
    protected $routes;

    /**
     * @var Dispatcher
     */
    protected $dispatcher;

    protected $factory;

    /**
     * Create the middleware instance.
     *
     * @param RouteCollection $routes
     * @param RouteHandlerFactory $factory
     */
    public function __construct(RouteCollection $routes, RouteHandlerFactory $factory)
    {
        $this->routes = $routes;
        $this->factory = $factory;
    }

    /**
     * Dispatch the given request to our route collection.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws \Exception
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = $request->getUri()->getPath() ?: '/';
        $routeInfo = $this->getDispatcher()->dispatch($method, $uri);
        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                throw new RouteNotFoundException($uri);
            case Dispatcher::METHOD_NOT_ALLOWED:
                throw new MethodNotAllowedException($method);
            case Dispatcher::FOUND:
                $handlerInfo = $routeInfo[1];
                $parameters = $routeInfo[2];
                $handler = $this->getReplaceHandler($method, $handlerInfo);
                return $this->factory->toController($handler)($request, $parameters);
        }
    }

    protected function getDispatcher()
    {
        if (!isset($this->dispatcher)) {
            $this->dispatcher = new GroupCountBased($this->routes->getRouteData());
        }
        return $this->dispatcher;
    }

    protected function getReplaceHandler($method, $handlerInfo)
    {
        $dispatcher = $this->getDispatcher();
        $staticRouteMap = $dispatcher->getStaticRouteMap();
        //$variableRouteData = $dispatcher->getVariableRouteData(); //不支持动态路由
        $replaceHandlers = [];
        foreach ($staticRouteMap as $m => $staticRoutes) {
            foreach ($staticRoutes as $urlPath => $staticRoute) {
                if(!empty($staticRoute['replaceHandler'])){
                    $replaceHandlers[$staticRoute['replaceHandler']] = $staticRoute;
                }
            }
        }
        $handler = $handlerInfo['handler'];
        if(isset($replaceHandlers[$handler])){
            if($replaceHandlers[$handler]['method'] == $method){
                return $replaceHandlers[$handler]['handler'];
            }else{
                throw new \Exception('handler (' . $handler . ') method not matched');
            }
        }
        return $handler;
    }
}
