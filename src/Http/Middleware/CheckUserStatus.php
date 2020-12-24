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
use Discuz\Auth\Exception\PermissionDeniedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CheckUserStatus implements MiddlewareInterface
{
    private $noCheckAction = [
        '/api/user/signinfields',
        '/api/attachments'
    ];

    /**
     * {@inheritdoc}
     *
     * @throws PermissionDeniedException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {

        $actor = $request->getAttribute('actor');
        // 被禁用的用户
        if ($actor->status == User::STATUS_BAN) {
            throw new PermissionDeniedException('ban_user');
        }
        // 审核中的用户
        if ($actor->status == User::STATUS_MOD) {
            $path = $request->getUri()->getPath();
            if (!in_array($path, $this->noCheckAction)) {
                throw new PermissionDeniedException('register_validate');
            }
        }
        // 审核拒绝
        if ($actor->status == User::STATUS_REFUSE) {
            throw new PermissionDeniedException('validate_reject');
        }
        // 审核忽略
        if ($actor->status == User::STATUS_IGNORE) {
            throw new PermissionDeniedException('validate_ignore');
        }
        // 待填写扩展审核字段的用户
        if ($actor->status == User::STATUS_NEED_FIELDS) {
            $path = $request->getUri()->getPath();
            if (!in_array($path, $this->noCheckAction)) {
                throw new PermissionDeniedException('need_ext_fields');
            }
        }
        return $handler->handle($request);
    }
}
