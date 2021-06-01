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
use App\Models\Group;
use App\Models\Invite;
use App\Models\Order;
use Discuz\Auth\AssertPermissionTrait;
use Discuz\Auth\Exception\PermissionDeniedException;
use Discuz\Common\PubEnum;
use Discuz\Contracts\Setting\SettingsRepository;
use Discuz\Foundation\Application;
use EasyWeChat\Kernel\Http\Response;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Discuz\Common\Utils;

class CheckoutSite implements MiddlewareInterface
{
    use AssertPermissionTrait;

    protected $app;

    protected $settings;

    private $noCheckPayMode = [
        '/user/login', // 登录中转
        '/user/wx-auth', // 登录中转
        '/user/phone-login', // 手机登录
        '/user/username-login', // 用户名登录
        '/user/wx-login', // 微信登录
        '/user/wx-authorization', // 微信授权
        '/user/wx-bind', // 微信绑定
        '/user/wx-bind-phone', // 微信绑定手机号
        '/user/wx-bind-qrcode', // 扫码绑定微信
        '/user/wx-bind-username', // 微信用户名绑定
        '/user/wx-select', // 微信落地页
        '/user/register', // 注册
        '/user/status', // 状态
        '/user/supplementary', // 补充信息
        '/user/reset-password', // 找回密码
        '/user/agreement', // 协议
        '/user/bind-phone', // 绑定手机号
        '/user/bind-nickname', // 绑定昵称
        '/my', // 个人中心
        '/forum/partner-invite', // 站点加入
        '/forum'
    ];

    public function __construct(Application $app, SettingsRepository $settings)
    {
        $this->app = $app;
        $this->settings = $settings;
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws PermissionDeniedException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // get settings
        $siteClose = (bool)$this->settings->get('site_close');
        $siteMode = $this->settings->get('site_mode');
//        if (in_array($request->getUri()->getPath(), ['/api/login', '/api/oauth/wechat/miniprogram'])) {
//            return $handler->handle($request);
//        }
        $actor = $request->getAttribute('actor');
        $siteClose && $this->assertAdmin($actor);
        $this->checkPayMode($request, $actor);
        // 处理 付费模式 逻辑， 过期之后 加入待付费组
        if (!$actor->isAdmin() && $siteMode === 'pay' && Carbon::now()->gt($actor->expired_at)) {
            if (!$this->getOrder($actor) && !$this->getInvite($actor)) {
                $actor->setRelation('groups', Group::query()->where('id', Group::UNPAID)->get());
            }
        }

        return $handler->handle($request);
    }

    private function checkPayMode($request, $actor)
    {
        $siteMode = $this->settings->get('site_mode');
        $apiPath = $request->getUri()->getPath();
        $api = str_replace(['/apiv3', '/api'], '', $apiPath);
        if ($siteMode == "public") {
            return false;
        }
        if ($actor->isAdmin()) {
            return false;
        }
        //已付费未到期
        if (strtotime($actor->expired_at) > time()) {
            return false;
        }
        $sitePrice = $this->settings->get('site_price');
        $siteExpire = $this->settings->get('site_expire');
        if (!in_array($api, $this->noCheckPayMode)) {
            Utils::outPut(ResponseCode::JUMP_TO_PAY_SITE, '', [
                'expiredAt' => !empty($actor->expired_at) ? date('Y-m-d H:i:s', strtotime($actor->expired_at)) : null,
                'sitePrice' => $sitePrice,
                'siteExpire' => $siteExpire
            ]);
        }
    }

    private function getOrder($actor)
    {
        if ($actor->isGuest()) {
            return false;
        }
        return $actor->orders()
            ->where('type', Order::ORDER_TYPE_REGISTER)
            ->where('status', Order::ORDER_STATUS_PAID)
            ->where(function ($query) {
                $query->where('expired_at', '>', Carbon::now()->toDateTimeString())
                    ->orWhere('expired_at', null);
            })
            ->first();
    }

    private function getInvite($actor)
    {
        if ($actor->isGuest()) {
            return false;
        }
        return Invite::where('type', Invite::TYPE_ADMIN)
            ->where('to_user_id', $actor->id)
            ->where('status', Invite::STATUS_USED)
            ->first();
    }
}
