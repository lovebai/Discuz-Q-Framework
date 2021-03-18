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

namespace Discuz\Base;

use App\Common\ResponseCode;
use App\Models\User;
use App\Modules\Services\ApiCacheService;
use DateTime;
use Discuz\Http\DiscuzResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Money\Number;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class DzqController implements RequestHandlerInterface
{
    protected $request;
    protected $requestId;
    protected $requestTime;
    protected $platform;
    protected $app;
    protected $openApiLog = true;//后台是否开启接口层日志
    protected $user = null;
    protected $isLogin = false;

    protected $isDebug = true;


    private $queryParams = [];
    private $parseBody = [];

    public $providers = [];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        $this->queryParams = $request->getQueryParams();
        $this->parseBody = $request->getParsedBody();
        $this->requestId = Str::uuid();
        $this->requestTime = date('Y-m-d H:i:s');
        $this->app = app();
        $this->registerProviders();
        $this->user = $request->getAttribute('actor');
        $this->main();
    }

    /*
     * 控制器业务逻辑
     */
    abstract public function main();

    /*
     * 引入providers
     */
    public function registerProviders()
    {
        if (!empty($this->providers)) {
            foreach ($this->providers as $val) {
                $this->app->register($val);
            }
        }
    }


    /*
     * 接口入参
     */
    public function inPut($name, $checkValid = true)
    {
        $p = '';
        if (isset($this->queryParams[$name])) {
            $p = $this->queryParams[$name];
        }
        if (isset($this->parseBody[$name])) {
            $p = $this->parseBody[$name];
        }
        return $p;
    }

    /*
     * 接口出参
     */
    public function outPut($code, $msg = '', $data = [])
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
            'RequestId' => $this->requestId,
            'RequestTime' => $this->requestTime
        ];
        $crossHeaders = DiscuzResponseFactory::getCrossHeaders();
        foreach ($crossHeaders as $k => $v) {
            header($k . ':' . $v);
        }
        header('Content-Type:application/json; charset=utf-8', true, 200);
        exit(json_encode($data, 256));
    }

//    public function __invoke()
//    {
//        $this->isDebug && $this->sendResponse();
//        try {
//            $this->sendResponse();
//        } catch (\Throwable $e) {
//            $code = $e->getCode();
//            $msg = $e->getMessage();
//            $result = [
//                'Code' => empty($code) ? ResponseCode::INTERNAL_ERROR : $code,
//                'Message' => $msg,
//                'RequestId' => $this->requestId,
//                'RequestTime' => $this->requestTime
//            ];
//            $apiScope = $this->request->headers->get('Api-Scope');
//            $this->openApiLog && Log::channel($apiScope)->info($this->requestId . ':response', [$result]);
//            if (empty($msg)) {
//                $result['Message'] = ResponseCode::$codeMap[ResponseCode::INTERNAL_ERROR];
//            }
//            response()->json($result)->setStatusCode(500)->send();
//        }
//    }
//
//    private function sendResponse()
//    {
//        $apiScope = $this->request->headers->get('Api-Scope');
//        $apiCache = new ApiCacheService($this->request, static::class);
//        $apiCache->forget();
//        $server = $this->request->server->all();
//        $this->openApiLog && Log::channel($apiScope)->info($this->requestId . ':request', [$server]);
//        $isSet = $apiCache->isSetCache();
//        $result = false;
//        $isSet && $result = $apiCache->getValue();
//        !$result && $result = $this->main();
//        $isSet && $apiCache->setValue($result);
//        $this->openApiLog && Log::channel($apiScope)->info($this->requestId . ':response', [$result]);
//        response()->json($result)->send();
//        exit;
//    }

    /*
     * 入参判断
     */
    public function dzqValidate($inputArray, array $rules, array $messages = [], array $customAttributes = [])
    {
        try {
            $validate = app('validator');
            $validate->validate($inputArray, $rules);
        } catch (\Exception $e) {
            $this->outPut(ResponseCode::INVALID_PARAMETER, $e->getMessage());
        }
    }

    /*
     * 分页
     */
    public function pagination($currentPage, $perPage, \Illuminate\Database\Eloquent\Builder $builder, $toArray = true)
    {
        $currentPage = $currentPage >= 1 ? intval($currentPage) : 1;
        $perPageMax = 50;
        $perPage = $perPage >= 1 ? intval($perPage) : 20;
        $perPage > $perPageMax && $perPage = $perPageMax;
        $count = $builder->get()->count();
        $builder = $builder->offset(($currentPage - 1) * $perPage)->limit($perPage)->get();
        $builder = $toArray ? $builder->toArray() : $builder;
        $url = $this->request->getUri();
        $port = $url->getPort();
        $port = $port == null ? '' : ':' . $port;
        parse_str($url->getQuery(), $query);
        $queryFirst = $queryNext = $queryPre = $query;
        $queryFirst['page'] = 1;
        $queryNext['page'] = $currentPage + 1;
        $queryPre['page'] = $currentPage <= 1 ? 1 : $currentPage - 1;

        $path = $url->getScheme() . '://' . $url->getHost() . $port . $url->getPath() . '?';
        return [
            'pageData' => $builder,
            'currentPage' => $currentPage,
            'perPage' => $perPage,
            'firstPageUrl' => urldecode($path . http_build_query($queryFirst)),
            'nextPageUrl' => urldecode($path . http_build_query($queryNext)),
            'prePageUrl' => urldecode($path . http_build_query($queryNext)),
            'pageLength' => count($builder),
            'totalPage' => $count % $perPage == 0 ? $count / $perPage : intval($count / $perPage) + 1
        ];
    }

    /**
     * @param DateTime|null $date
     * @return string|null
     */
    protected function formatDate(DateTime $date = null)
    {
        if ($date) {
            return $date->format(DateTime::RFC3339);
        }
    }

    /**
     * camelData
     * @param array|string $arr   原数组
     * @param bool|null $ucfirst   首字母大小写，false 小写，true 大写
     */
    public function camelData( $arr, $ucfirst = false)
    {
        if(is_object($arr))     $arr = $arr->toArray();
        if(!is_array($arr)){
            //如果非数组原样返回
            return  $arr;
        }
        $temp = [];
        foreach ($arr as $key => $value){
            $key1 = Str::camel($key);           // foo_bar  --->  fooBar
            if($ucfirst)    $key1 = Str::ucfirst($key1);
            $value1 = self::camelData($value);
            $temp[$key1] = $value1;
        }
        return $temp;

    }

}
