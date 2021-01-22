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

use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Discuz\Http\DiscuzResponseFactory;

class OptionsRequest implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = Arr::get($request->getServerParams(), 'REQUEST_METHOD', '');
        if ($method == 'OPTIONS') {
            return DiscuzResponseFactory::EmptyResponse(200);
        } else {
            if (strtolower($method) == 'get') {
                if ($this->isForbidden(60)) {
                    throw new \Exception('请求太频繁，请稍后重试');
                }
            } else {
                if ($this->isForbidden(10)) {
                    throw new \Exception('请求太频繁，请稍后重试');
                }
            }
            return $handler->handle($request);
        }
    }

    private function isForbidden($max = 10, $interval = 60)
    {
        $request = app('request');
        $ip = ip($request->getServerParams());
        $api = $request->getUri()->getPath();
        if (empty($ip) || empty($api)) return false;
        $key = 'api_limit_by_ip_' . md5($ip . $api);
        $cache = app('cache');
        $count = $cache->get($key);
        if (empty($count)) {
            $cache->put($key, 1, $interval);
            return false;
        } else {
            if ($count > $max) {
                $cache->put($key, $count, 3 * 60);
                return true;
            } else {
                $cache->put($key, ++$count);
                return false;
            }
        }
    }
}
