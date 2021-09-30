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
use App\Models\GroupUser;
use App\Models\GroupUserMq;
use App\Models\Invite;
use App\Models\Order;
use App\Repositories\UserRepository;
use Discuz\Auth\AssertPermissionTrait;
use Discuz\Auth\Exception\PermissionDeniedException;
use Discuz\Contracts\Setting\SettingsRepository;
use Discuz\Foundation\Application;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Discuz\Common\Utils;

class CheckoutSite implements MiddlewareInterface
{
    use AssertPermissionTrait;

    const CACHE_GROUP_USER_TIME = 300;
    const CACHE_GROUP_USER_MQS_TIME = 300;

    protected $app;

    protected $settings;

    private $noCheckPayMode = [
        'user',
        'forum',
        'follow.list',
        'users.list',
        'order.create',
        'trade/pay/order',
        'order.detail',
        'wallet/cash',
        'wallet/log',
        'wallet/user',
        'categories',
        'thread.stick',
        'tom.permissions',
        'thread.recommends',
        'trade/notify/wechat',
        'threads/notify/video',
        'offiaccount/jssdk',
        'attachment.download',
        'user/signinfields.list', // 查询扩展字段
        'user/signinfields.create', // 提交扩展字段
        'attachments', //上传图片、附件
        'unreadnotification',
        'posts.list', // 帖子
        'backAdmin/login',
        'emoji',
        'view.count',
        'swagger'
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
        $actor = $request->getAttribute('actor');
        $siteClose = (bool)$this->settings->get('site_close');
        $siteMode = $this->settings->get('site_mode');
        if ($siteClose && !$actor->isAdmin() && $request->getUri()->getPath() != '/api/backAdmin/login') {
            $siteCloseMsg = $this->settings->get('site_close_msg');
            Utils::outPut(ResponseCode::SITE_CLOSED, '', ['detail' => $siteCloseMsg]);
        }

//        if (in_array($request->getUri()->getPath(), ['/api/login', '/api/oauth/wechat/miniprogram'])) {
//            return $handler->handle($request);
//        }
        // $siteClose && $this->assertAdmin($actor);
        $this->checkPayMode($request, $actor);
        $this->judgeGroupUser($actor);
        // 处理 付费模式 逻辑， 过期之后 加入待付费组
        if (!$actor->isAdmin() && $siteMode === 'pay' && ( Carbon::now()->gt($actor->expired_at) || $actor->isGuest() )) {
            if (!$this->getOrder($actor) && !$this->getInvite($actor)) {
                $actor->setRelation('groups', Group::query()->where('id', Group::UNPAID)->get());
            }
        }

        return $handler->handle($request);
    }

    private function checkPayMode($request, $actor)
    {
        $userRepo = app(UserRepository::class);
        if ($userRepo->isPaid($actor) === true) {
            return;
        }
        $apiPath = $request->getUri()->getPath();
        $queryString = $request->getUri()->getQuery();
        $api = str_replace(['/apiv3/', '/api/'], '', $apiPath);
        $this->inWhiteApiList($api, $queryString);
        if (!(in_array($api, $this->noCheckPayMode) || $this->inWhiteApiList($api, $queryString)) && !(strpos($api, 'users') === 0) && !(strpos($api, 'backAdmin') === 0)) {
            Utils::outPut(ResponseCode::JUMP_TO_PAY_SITE);
        }
    }

    private function inWhiteApiList($api, $queryString)
    {
        parse_str($queryString, $query);
        $isPass = false;
        switch ($api) {
            case 'thread.list':
                if (isset($query['scope']) && $query['scope'] == 3) {
                    $isPass = true;
                }
                break;
        }
        return $isPass;
    }

    private function getOrder($actor)
    {
        if ($actor->isGuest()) {
            return false;
        }
        return $actor->orders()
            ->whereIn('type', [Order::ORDER_TYPE_REGISTER, Order::ORDER_TYPE_RENEW])
            ->where('status', Order::ORDER_STATUS_PAID)
            ->where(function ($query) {
                $query->where('expired_at', '>', Carbon::now()->toDateTimeString())
                    ->orWhere('expired_at', null);
            })
            ->orderBy('id', 'desc')
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

    private function judgeGroupUser($actor)
    {
        if($actor->isGuest() || $actor->isAdmin()){
            return;
        }
        $group_user = app('cache')->get('judge_group_user_'.$actor->id);
        if(empty($group_user)){
            // 目前的业务中，保持着 user 与 group_user 一对一的关系，所以这里可以只取一个
            $group_user = GroupUser::query()->where('user_id', $actor->id)->first();
            app('cache')->put('judge_group_user_'.$actor->id, $group_user, self::CACHE_GROUP_USER_TIME);
        }
        if($group_user->expiration_time < Carbon::now()){
            $empty_group_user_mqs = app('cache')->get('empty_group_user_mqs_'.$actor->id);
            if(!empty($empty_group_user_mqs)){
                return;
            }
            //用户当前用户组到期，需要切换用户组
            $group_user_mqs = GroupUserMq::query()
                            ->select('groups.id','group_user_mqs.remain_days')
                            ->join('groups', 'group_user_mqs.group_id','=','groups.id')
                            ->where('group_user_mqs.user_id', $actor->id)
                            ->orderBy('groups.level', 'desc')
                            ->first();
            if(empty($group_user_mqs)){
                app('cache')->put('empty_group_user_mqs_'.$actor->id, 1, self::CACHE_GROUP_USER_MQS_TIME);
                return;
            }
            //更换用户组
            $db = $this->app->make('db');
            $log = $this->app->make('log');
            $db->beginTransaction();
            //先删掉之前的 group_user
            $res = GroupUser::query()->where('user_id', $actor->id)->delete();
            if($res === false){
                $db->rollBack();
                $log->error('删除 group_user 出错', [$actor]);
                return;
            }
            //再新增新的 group_user
            $res = $db->table('group_user')->insert([
                'group_id'  =>  $group_user_mqs->id,
                'user_id'   =>  $actor->id,
                'expiration_time'   =>  Carbon::now()->addDays($group_user_mqs->remain_days)
            ]);
            if($res === false){
                $db->rollBack();
                $log->error('新增 group_user 出错', [$actor]);
                return;
            }
            //最后删除 group_user_mqs
            $res = GroupUserMq::query()->where(['user_id' => $actor->id, 'group_id' => $group_user_mqs->id])->delete();
            if($res === false){
                $db->rollBack();
                $log->error('删除 group_user_mqs 出错', [$actor]);
                return;
            }
            $db->commit();
        }
    }
}
