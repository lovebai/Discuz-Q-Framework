<?php
/**
 * created by vscode 2099.
 * author: 流火行者
 * date: 2021/9/7 19:58
 * desc: 描述
 * 请移步支付宝搜索 "510992378" 查看文档
 */

namespace Discuz\Base;


use App\Repositories\UserRepository;

abstract class DzqAdminController extends DzqController
{

    protected function checkRequestPermissions(UserRepository $userRepo)
    {
        return $this->user->isAdmin();
    }
}
