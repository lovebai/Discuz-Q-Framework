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

use App\Common\ResponseCode;
use App\Models\User;
use App\Passport\Repositories\AccessTokenRepository;
use Discuz\Auth\Guest;
use Discuz\Common\Utils;
use Illuminate\Support\Arr;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthenticateWithHeader implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        list($headerLine, $request) = $this->getHeaderLine($request);
        if ($headerLine) {
            $accessTokenRepository = new AccessTokenRepository();
            $publickey = new CryptKey(storage_path('cert/public.key'), '', false);
            $server = new ResourceServer($accessTokenRepository, $publickey);
            try {
                $request = $server->validateAuthenticatedRequest($request);
            } catch (\Exception $e) {
                Utils::outPut(ResponseCode::INVALID_TOKEN);
            }
        }
        if ($this->isBadRequest($request)) throw new \Exception('操作太频繁，请稍后重试');
        if ($headerLine) {
            // 获取Token位置，根据 Token 解析用户并查询到当前用户
            $actor = $this->getActor($request);
            if (!is_null($actor) && $actor->exists) {
                $request = $request->withoutAttribute('oauth_access_token_id')->withoutAttribute('oauth_client_id')->withoutAttribute('oauth_user_id')->withoutAttribute('oauth_scopes')->withAttribute('actor', $actor);
            }
        }
        return $handler->handle($request);
    }

    private function getHeaderLine($request)
    {
        $headerLine = $request->getHeaderLine('authorization');
        if (empty($headerLine)) {     //如果header头中没有 authorization，则从cookie里面找是否有access_token
            $cookies = $request->getCookieParams();
            if (!empty($cookies['access_token'])) {
                $headerLine = $cookies['access_token'];
                $request = $request->withHeader('authorization', $headerLine);
            }
        }
        // 允许 get、cookie 携带 Token
        if (!$headerLine) {
            $headerLine = Arr::get($request->getQueryParams(), 'token');

            if ($headerLine) {
                $request = $request->withHeader('authorization', $headerLine);
            }
        }
        //初始化为游客
        $request = $request->withAttribute('actor', new Guest());
        return [$headerLine, $request];
    }


    private function getActor(ServerRequestInterface $request)
    {
        $userId = $request->getAttribute('oauth_user_id');
        if (!$userId) {
            return null;
        }
        $cache = app('cache');
        $key = 'dzq_login_user_by_id_' . $userId;
        $actor = $cache->get($key);
        if (!$actor) {
            $actor = User::find($userId);
            $cache->put($key, $actor, 3 * 60);
        }
//        if (!is_null($actor) && $actor->exists) {
//            $actor->changeUpdateAt()->save();
//        }
        return $actor;
    }


    private function isBadRequest(ServerRequestInterface $request)
    {
        $api = $request->getUri()->getPath();
        if (Utils::startWith($api, '/backAdmin')) {
            return false;
        }
//      $api = '/api/v3/plug/tes/hello';
        $httpMethod = Arr::get($request->getServerParams(), 'REQUEST_METHOD', '');
        $routeInfo = $this->getRouteInfo($api, $httpMethod);
        $ip = ip($request->getServerParams());
        $userId = $request->getAttribute('oauth_user_id');
        if (empty($userId)) {
            $key = md5($ip . $api . $httpMethod);
        } else {
            $key = md5($userId . $api . $httpMethod);
        }
        $times = $routeInfo['times'] ?: 50;
        $interval = $routeInfo['interval'] ?: 60;
        $delay = $routeInfo['delay'] ?: 300;//默认禁用5分钟
        $cache = app('cache');
        $count = $cache->get($key);
        if (empty($count)) {
            $cache->add($key, 1, $interval);
            return false;
        } else {
            if ($count >= $times) {
                $cache->put($key, $count, $delay);
                return true;
            } else {
                $cache->increment($key);
                return false;
            }
        }
    }

    /**
     * author: 流火行者
     * desc: 获取单个路由详情
     * @param $api
     * @param $httpMethod
     * @return bool
     */
    private function getRouteInfo($api, $httpMethod)
    {
        $routeMaps = Utils::getRouteMap();
        $staticMaps = $routeMaps[0] ?? [];
        $variableMaps = $routeMaps[1] ?? [];
        foreach ($staticMaps as $method => $staticMap) {
            if ($method == $httpMethod && isset($staticMap[$api])) return $staticMap[$api];
        }
        foreach ($variableMaps as $method => $variableMap) {
            $route = $this->dispatchVariableRoute($variableMap, $api);
            if ($method == $httpMethod && $route) return $route;
        }
        return false;
    }

    /**
     * author: 流火行者
     * desc: 解析可变路由详情
     * @param $routeData
     * @param $uri
     * @return bool
     */
    private function dispatchVariableRoute($routeData, $uri)
    {
        foreach ($routeData as $data) {
            if (!preg_match($data['regex'], $uri, $matches)) {
                continue;
            }
            list($handler, $varNames) = $data['routeMap'][count($matches)];
            $vars = [];
            $i = 0;
            foreach ($varNames as $varName) {
                $vars[$varName] = $matches[++$i];
            }
            return $handler;
        }
        return false;
    }
//    private function getApiFreq($api)
//    {
//        $cache = app('cache');
//        $cacheKey = CacheKey::API_FREQUENCE;
//        if ($api == 'cache.delete') {
//            $cache->forget($cacheKey);
//        }
//        $apiFreq = $cache->get($cacheKey);
//        if (!empty($apiFreq)) {
//            $this->apiFreq = json_decode($apiFreq, true);
//        } else {
//            $apiFreqSetting = Setting::query()->where('key', 'api_freq')->first();
//            if (!empty($apiFreqSetting)) {
//                $this->apiFreq = json_decode($apiFreqSetting['value'], true);
//                $cache->put($cacheKey, $apiFreqSetting['value'], 5 * 60);
//            }
//        }
//    }
//    private function checkLimit($api, ServerRequestInterface $request)
//    {
//        $method = Arr::get($request->getServerParams(), 'REQUEST_METHOD', '');
//        $userId = $request->getAttribute('oauth_user_id');
//        if (strstr($api, 'backAdmin')) {
//            return;
//        }
//        if (strtolower($method) == 'get') {
//            if ($this->isForbidden($api, $userId, $request, $method, $this->apiFreq['get']['freq'])) {
//                throw new \Exception('操作太频繁，请稍后重试');
//            }
//        } else {
//            if ($this->isForbidden($api, $userId, $request, $method, $this->apiFreq['post']['freq'])) {
//                throw new \Exception('操作太频繁，请稍后重试');
//            }
//        }
//    }
//
//    private function isForbidden($api, $userId, ServerRequestInterface $request, $method, $max = 10, $interval = 60)
//    {
//
//        $ip = ip($request->getServerParams());
//        if (empty($api)) {
//            return true;
//        }
//        $method = strtolower($method);
//
//        if ($this->isAttachments($api, $method) || $this->isCoskey($api, $method)) {
//            $maxUploadNum = app()->make(SettingsRepository::class)->get('support_max_upload_attachment_num', 'default');
//            $maxLimit = $maxUploadNum ? (int)$maxUploadNum : 20;
//        }
//
//        if (empty($userId)) {
//            $key = 'api_limit_by_ip_' . md5($ip . $api . $method);
//        } else {
//            $key = 'api_limit_by_uid_' . md5($userId . '_' . $api . $method);
//        }
//        if ($this->isRegister($api, $method)) {
//            return $this->setLimit($key, $method, 10, 10 * 60);
//        }
//        if ($this->isAttachments($api, $method)) {
//            return $this->setLimit($key, $method, $maxLimit, 5 * 60);
//        }
//        if ($this->isPoll($api)) {
//            return $this->setLimit($key, $method, 200, 60);
//        }
//
//        if ($this->isCoskey($api, $method)) {
//            return $this->setLimit($key, $method, $maxLimit, 30);
//        }
//        if ($this->isPayOrder($api, $method)) {
//            return $this->setLimit($key, $method, 3, 10);
//        }
//        return $this->setLimit($key, $method, $max);
//    }

//    private function isRegister($api, $method)
//    {
//        return $api == 'users/username.register' && $method == 'post';
//    }
//
//    private function isAttachments($api, $method)
//    {
//        return $api == 'attachments' && $method == 'post';
//    }
//
//    private function isPoll($api)
//    {
//        $pollapi = [
//            'users/pc/wechat/h5.login',
//            'users/pc/wechat/h5.bind',
//            'users/pc/wechat/miniprogram.bind',
//            'users/pc/wechat/miniprogram.login',
//            'users/pc/wechat.rebind.poll',
//            'dialog/message',
//            'unreadnotification',
//            'dialog.update'
//        ];
//        return in_array($api, $pollapi);
//    }
//
//    private function isCoskey($api, $method)
//    {
//        return $api == 'coskey' && $method == 'post';
//    }
//
//    private function isPayOrder($api, $method)
//    {
//        return $api == 'trade/pay/order' && $method == 'post';
//    }

    /*
     * $max interage 每分钟最大调用次数
     * $defaultDelay Boolen 超过调用次数禁止秒数
     */
//    private function setLimit($key, $method, $max, $defaultDelay = null)
//    {
//        $cache = app('cache');
//        $count = $cache->get($key);
//
//        if (empty($count)) {
//            $cache->add($key, 1, 60);
//            return false;
//        } else {
//            if ($count >= $max) {
//                if ($defaultDelay == null) {
//                    if ($method == 'get') {
//                        $cache->put($key, $count, $this->apiFreq['get']['forbidden']);
//                    } else {
//                        $cache->put($key, $count, $this->apiFreq['post']['forbidden']);
//                    }
//                } else {
//                    $cache->put($key, $count, $defaultDelay);
//                }
//                return true;
//            } else {
//                $cache->increment($key);
//                return false;
//            }
//        }
//    }
}
