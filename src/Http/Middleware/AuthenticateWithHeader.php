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

use App\Common\CacheKey;
use App\Models\Setting;
use App\Models\User;
use App\Passport\Repositories\AccessTokenRepository;
use Discuz\Auth\Guest;
use Discuz\Cache\CacheManager;
use Illuminate\Support\Arr;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthenticateWithHeader implements MiddlewareInterface
{
    const AUTH_USER_CACHE_TTL = 30;

    protected $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    private $apiFreq = [
        'get'=>[
            'freq'=>500,
            'forbidden'=>20
        ],
        'post'=>[
            'freq'=>100,
            'forbidden'=>30
        ]
    ];
    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws \League\OAuth2\Server\Exception\OAuthServerException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->getApiFreq($request);
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

    private function getApiFreq(ServerRequestInterface $request)
    {
        $cache = app('cache');
        $api = $request->getUri()->getPath();
        $cacheKey = CacheKey::API_FREQUENCE;
        if ($api == '/api/cache') {
            $cache->forget($cacheKey);
        }
        $apiFreq = $cache->get($cacheKey);
        if (!empty($apiFreq)) {
            $this->apiFreq = json_decode($apiFreq, true);
        } else {
            $apiFreqSetting = Setting::query()->where('key', 'api_freq')->first();
            if (!empty($apiFreqSetting)) {
                $this->apiFreq = json_decode($apiFreqSetting['value'], true);
                $cache->put($cacheKey, $apiFreqSetting['value'], 5 * 60);
            }
        }
    }

    private function getActor(ServerRequestInterface $request)
    {
        $userId = $request->getAttribute('oauth_user_id');
        if (!$userId) {
            return null;
        }

        if (app()->config('middleware_cache')) {
            $ttl = static::AUTH_USER_CACHE_TTL;
            return $this->cache->remember(
                CacheKey::AUTH_USER_PREFIX.$userId,
                mt_rand($ttl, $ttl + 10),
                function () use ($userId) {
                    return $this->getActorFromDatabase($userId);
                }
            );
        } else {
            return $this->getActorFromDatabase($userId);
        }
    }

    private function getActorFromDatabase($userId)
    {
        $actor = User::find($userId);
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
            if ($this->isForbidden($userId, $request, $method, $this->apiFreq['get']['freq'])) {
                throw new \Exception('操作太频繁，请稍后重试');
            }
        } else {
            if ($this->isForbidden($userId, $request, $method, $this->apiFreq['post']['freq'])) {
                throw new \Exception('操作太频繁，请稍后重试');
            }
        }
    }

    private function isForbidden($userId, ServerRequestInterface $request, $method, $max = 10, $interval = 60)
    {
        $ip = ip($request->getServerParams());
        $api = $request->getUri()->getPath();
        if (empty($api)) return true;
        $method = strtolower($method);
        if (empty($userId)) {
            $key = 'api_limit_by_ip_' . md5($ip . $api . $method);
        } else {
            $key = 'api_limit_by_uid_' . md5($userId . '_' . $api . $method);
        }
        if ($this->isHome($api, $method)) {
            return $this->setLimit($key, $method, $this->apiFreq['get']['freq'] * 2, $this->apiFreq['get']['forbidden']);
        }
        if ($this->isRegister($api, $method)) {
            return $this->setLimit($key, $method, 10, 10 * 60);
        }
        if ($this->isAttachments($api, $method)) {
            return $this->setLimit($key, $method, 20, 5 * 60);
        }
        if ($this->isMessage($api, $method)) {
            return $this->setLimit($key, $method, 300, 60);
        }
        return $this->setLimit($key, $method, $max);
    }

    private function isRegister($api, $method)
    {
        return $api == '/api/register' && $method == 'post';
    }

    private function isAttachments($api, $method)
    {
        return $api == '/api/attachments' && $method == 'post';
    }

    private function isHome($api, $method)
    {
        $homeApi = [
            '/api/threads',
            '/api/categories',
            '/api/forum',
            '/api/users/recommended'
        ];
        return in_array($api, $homeApi) && $method == 'get';
    }

    private function isMessage($api, $method)
    {
        return $api == '/api/dialog/message' && $method == 'get';
    }

    /*
     * $max interage 每分钟最大调用次数
     * $defaultDelay Boolen 超过调用次数禁止秒数
     */
    private function setLimit($key, $method, $max, $defaultDelay = null)
    {
        $cache = app('cache');
        $count = $cache->get($key);
        if (empty($count)) {
            $cache->add($key, 1, 60);
            return false;
        } else {
            if ($count >= $max) {
                if ($defaultDelay == null) {
                    if ($method == 'get') {
                        $cache->put($key, $count, $this->apiFreq['get']['forbidden']);
                    } else {
                        $cache->put($key, $count, $this->apiFreq['post']['forbidden']);
                    }
                } else {
                    $cache->put($key, $count, $defaultDelay);
                }
                return true;
            } else {
                $cache->increment($key);
                return false;
            }
        }
    }

}
