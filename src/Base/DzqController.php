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

use App\Common\CacheKey;
use App\Common\ResponseCode;
use App\Models\User;
use App\Modules\Services\ApiCacheService;
use DateTime;
use Discuz\Common\Utils;
use Discuz\Http\DiscuzResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Money\Number;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * @method  beforeMain($user)
 * @method  clearCache($user)
 */
abstract class DzqController implements RequestHandlerInterface
{
    public $request;
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
        $this->c9IbQHXVFFWu($this->user);//添加辅助函数
        $this->checkRequestPermissions();
        $this->main();
    }

    /*
     * 控制器权限检查
     */
    protected function checkRequestPermissions()
    {
    }

    /*
     * 控制器业务逻辑
     */
    abstract public function main();

    public function c9IbQHXVFFWu($name)
    {
        if (method_exists($this, 'beforeMain')) {
            $this->beforeMain($name);
        }
        if (method_exists($this, 'clearCache')) {
            $this->clearCache($name);
        }
    }

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
        Utils::outPut($code, $msg, $data, $this->requestId, $this->requestTime);
    }

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
    public function pagination($page, $perPage, \Illuminate\Database\Eloquent\Builder $builder, $toArray = true)
    {
        $page = $page >= 1 ? intval($page) : 1;
        $perPageMax = 50;
        $perPage = $perPage >= 1 ? intval($perPage) : 20;
        $perPage > $perPageMax && $perPage = $perPageMax;
        $count = $builder->count();
        $builder = $builder->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $builder = $toArray ? $builder->toArray() : $builder;
        $url = $this->request->getUri();
        $port = $url->getPort();
        $port = $port == null ? '' : ':' . $port;
        parse_str($url->getQuery(), $query);
        $queryFirst = $queryNext = $queryPre = $query;
        $queryFirst['page'] = 1;
        $queryNext['page'] = $page + 1;
        $queryPre['page'] = $page <= 1 ? 1 : $page - 1;

        $path = $url->getScheme() . '://' . $url->getHost() . $port . $url->getPath() . '?';
        return [
            'pageData' => $builder,
            'currentPage' => $page,
            'perPage' => $perPage,
            'firstPageUrl' => $this->buildUrl($path, $queryFirst),
            'nextPageUrl' => $this->buildUrl($path, $queryNext),
            'prePageUrl' => $this->buildUrl($path, $queryPre),
            'pageLength' => count($builder),
            'totalCount' => $count,
            'totalPage' => $count % $perPage == 0 ? $count / $perPage : intval($count / $perPage) + 1
        ];
    }

    public function preloadPaginiation($pageCount, $perPage, \Illuminate\Database\Eloquent\Builder $builder, $toArray = true)
    {
        $perPage = $perPage >= 1 ? intval($perPage) : 20;
        $perPageMax = 50;
        $perPage > $perPageMax && $perPage = $perPageMax;
        $count = $builder->count();
        $builder = $builder->offset(0)->limit($pageCount * $perPage)->get();
        $builder = $toArray ? $builder->toArray() : $builder;
        $url = $this->request->getUri();
        $port = $url->getPort();
        $port = $port == null ? '' : ':' . $port;
        parse_str($url->getQuery(), $query);
        unset($query['preload']);
        $ret = [];
        $currentPage = 1;
        $totalCount = $count;
        $totalPage = $count % $perPage == 0 ? $count / $perPage : intval($count / $perPage) + 1;
        while ($currentPage <= $pageCount) {
            $queryFirst = $queryNext = $queryPre = $query;
            $queryFirst['page'] = 1;
            $queryNext['page'] = $currentPage + 1;
            $queryPre['page'] = $currentPage <= 1 ? 1 : $currentPage - 1;
            $path = $url->getScheme() . '://' . $url->getHost() . $port . $url->getPath() . '?';
            $pageData = array_slice($builder, ($currentPage - 1) * $perPage, $perPage);
            if (empty($pageData)) {
                break;
            }
            $ret[$currentPage] = [
                'pageData' => $pageData,
                'currentPage' => $currentPage,
                'perPage' => $perPage,
                'firstPageUrl' => $this->buildUrl($path, $queryFirst),
                'nextPageUrl' => $this->buildUrl($path, $queryNext),
                'prePageUrl' => $this->buildUrl($path, $queryPre),
                'pageLength' => count($pageData),
                'totalCount' => $totalCount,
                'totalPage' => $totalPage
            ];
            $currentPage++;
        }
        return $ret;
    }

    private function buildUrl($path, $query)
    {
        return urldecode($path . http_build_query($query));
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
     * @param array|string $arr 原数组
     * @param bool|null $ucfirst 首字母大小写，false 小写，true 大写
     */
    public function camelData($arr, $ucfirst = false)
    {
        if (is_object($arr) && is_callable([$arr, 'toArray'])) $arr = $arr->toArray();
        if (!is_array($arr)) {
            //如果非数组原样返回
            return $arr;
        }
        $temp = [];
        foreach ($arr as $key => $value) {
            $key1 = Str::camel($key);           // foo_bar  --->  fooBar
            if ($ucfirst) $key1 = Str::ucfirst($key1);
            $value1 = self::camelData($value);
            $temp[$key1] = $value1;
        }
        return $temp;

    }

    public function info($tag, $params = [])
    {
        if (is_array($params)) {
            app('log')->info($tag . '::' . json_encode($params, 256));
        } else {
            app('log')->info($tag . '::' . $params);
        }
    }

    private $connection = null;

    public function openQueryLog()
    {
        $connection = app(ConnectionInterface::class);
        $this->connection = $connection;
        $connection->enableQueryLog();
    }

    public function closeQueryLog()
    {
        if (!empty($this->connection)) {
            dd(json_encode($this->connection->getQueryLog(), 256));
        }
    }

    /**
     * @desc 获取数据库实例
     * @param $array
     * @return ConnectionInterface
     */
    public function getDB()
    {
        return app(ConnectionInterface::class);
    }

    public function getIpPort()
    {
        $serverParams = $this->request->getServerParams();
        $ip = ip($serverParams);
        $port = !empty($serverParams['REMOTE_PORT']) ? $serverParams['REMOTE_PORT'] : 0;
        return [$ip, $port];
    }

    public function cacheInstance()
    {
        return app('cache');
    }
}
