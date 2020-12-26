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

namespace Discuz\Api\ExceptionHandler;

use Discuz\Auth\Exception\PermissionDeniedException;
use Discuz\Contracts\Setting\SettingsRepository;
use Exception;
use Tobscure\JsonApi\Exception\Handler\ExceptionHandlerInterface;
use Tobscure\JsonApi\Exception\Handler\ResponseBag;
use Discuz\Common\Utils;

class PermissionDeniedExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function manages(Exception $e)
    {
        return $e instanceof PermissionDeniedException;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Exception $e)
    {
        $status = 401;

        $error = [
            'status' => (string) $status,
        ];

        // 站点是否关闭
        $settings = app()->make(SettingsRepository::class);

        $reqType = Utils::requestFrom();
        $siteManage = json_decode($settings->get('site_manage'), true);
        $siteManage = array_column($siteManage,null,'key');
        $siteOpen = true;
        if(isset($siteManage[$reqType])){
            $siteOpen = $siteManage[$reqType]['value'];
        }
        if (!$siteOpen) {
            $error['code'] = 'site_closed';
            $error['detail'][] = $settings->get('site_close_msg') ?: '';
        }else{
            $error['code'] = $e->getMessage();
        }
        
        return new ResponseBag($status, [$error]);
    }
}
