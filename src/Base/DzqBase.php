<?php
/**
 * Copyright (C) 2021 Tencent Cloud.
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

namespace Discuz\Base;


use App\Common\ResponseCode;
use Discuz\Http\DiscuzResponseFactory;
use Illuminate\Support\Str;

class DzqBase
{
    public  function outPut($code, $msg = '', $data = [],$requestId=null,$requestTime=null)
    {
        if (empty($msg)) {
            if (ResponseCode::$codeMap[$code]) {
                $msg = ResponseCode::$codeMap[$code];
            }
        }
        $data = [
            'Code' => $code,
            'Message' => $msg,
            'Data' => $data,
            'RequestId' => Str::uuid(),
            'RequestTime' => date('Y-m-d H:i:s')
        ];
        $crossHeaders = DiscuzResponseFactory::getCrossHeaders();
        foreach ($crossHeaders as $k => $v) {
            header($k . ':' . $v);
        }
        header('Content-Type:application/json; charset=utf-8', true, 200);
        exit(json_encode($data, 256));
    }
}