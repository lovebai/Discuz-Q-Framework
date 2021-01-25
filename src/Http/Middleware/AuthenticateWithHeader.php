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

use App\Models\User;
use App\Passport\Repositories\AccessTokenRepository;
use Discuz\Auth\Guest;
use Illuminate\Support\Arr;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthenticateWithHeader implements MiddlewareInterface
{
    const MAX_GET_PER_MINUTE = 500;
    const MAX_POST_PER_MINUTE = 200;
    const MAX_GET_FORBIDDEN_SECONDS = 10;
    const MAX_POST_FORBIDDEN_SECONDS = 30;

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws \League\OAuth2\Server\Exception\OAuthServerException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $headerLine = $request->getHeaderLine('authorization');

        // 允许 get、cookie 携带 Token
        if (!$headerLine) {
            $headerLine = Arr::get($request->getQueryParams(), 'token');

            if ($headerLine) {
                $request = $request->withHeader('authorization', $headerLine);
            }
        }

        $request = $request->withAttribute('actor', new Guest());

        if ($headerLine) {
            $accessTokenRepository = new AccessTokenRepository();

            $publickey = new CryptKey(storage_path('cert/public.key'), '', false);

            $server = new ResourceServer($accessTokenRepository, $publickey);

            $request = $server->validateAuthenticatedRequest($request);
            $this->checkLimit($request);
            // 获取Token位置，根据 Token 解析用户并查询到当前用户
            $actor = $this->getActor($request);

            if (!is_null($actor) && $actor->exists) {
                $request = $request->withoutAttribute('oauth_access_token_id')->withoutAttribute('oauth_client_id')->withoutAttribute('oauth_user_id')->withoutAttribute('oauth_scopes')->withAttribute('actor', $actor);
            }
        } else {
            $this->checkLimit($request);
        }


        return $handler->handle($request);
    }

    private function getActor(ServerRequestInterface $request)
    {
        $actor = User::find($request->getAttribute('oauth_user_id'));
        if (!is_null($actor) && $actor->exists) {
            $actor->changeUpdateAt()->save();
        }
        return $actor;
    }

    private function checkLimit(ServerRequestInterface $request)
    {
        $method = Arr::get($request->getServerParams(), 'REQUEST_METHOD', '');
        $userId = $request->getAttribute('oauth_user_id');
        if (strtolower($method) == 'get') {
            if ($this->isForbidden($userId, $request, $method, self::MAX_GET_PER_MINUTE)) {
                throw new \Exception('请求太频繁，请稍后重试');
            }
        } else {
            if ($this->isForbidden($userId, $request, $method, self::MAX_POST_PER_MINUTE)) {
                throw new \Exception('请求太频繁，请稍后重试');
            }
        }
    }

    private function isForbidden($userId, ServerRequestInterface $request, $method, $max = 10, $interval = 60)
    {

        $ip = ip($request->getServerParams());
        $api = $request->getUri()->getPath();
        if (empty($api)) return true;
        $method = strtolower($method);
        $homeApi = [
            '/api/threads',
            '/api/categories',
            '/api/forum',
            '/api/users/recommended'
        ];
        if (in_array($api, $homeApi) && $method == 'get') {
            $max = 1000;
        }
        if ($this->isRegister($api)) {
            $max = 5;
        }
        if (empty($userId)) {
            $key = 'api_limit_by_ip_' . md5($ip . $api . $method);
        } else {
            $key = 'api_limit_by_uid_' . md5($userId . '_' . $api . $method);
        }
        $cache = app('cache');
        $count = $cache->get($key);

        if (empty($count)) {
            $cache->add($key, 1, $interval);
            return false;
        } else {
            if ($count >= $max) {
                if ($method == 'get') {
                    $cache->put($key, $count, self::MAX_GET_FORBIDDEN_SECONDS);
                } else {
                    $cache->put($key, $count, self::MAX_GET_FORBIDDEN_SECONDS);
                }
                if ($this->isRegister($api)) {
                    $cache->put($key, $count, 10 * 60);
                }
                return true;
            } else {
                $cache->increment($key);
                return false;
            }
        }
    }

    private function isRegister($api)
    {
        return $api == '/api/register';
    }

}
