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


use Illuminate\Database\Eloquent\Collection;

class DzqCache
{
    const CACHE_SWICH = true;

    const CACHE_TTL = true;

    public static function set($key, $value)
    {
        if (self::CACHE_TTL) {
            return app('cache')->put($key, $value, 60 * 60);
        }
        return app('cache')->put($key, $value);
    }

    public static function get($key)
    {
        return app('cache')->get($key);
    }

    public static function delKey($key)
    {
        return app('cache')->forget($key);
    }

    public static function delHashKey($key, $hashKey)
    {
        $data = self::get($key);
        if ($data && array_key_exists($hashKey, $data)) {
            unset($data[$hashKey]);
            return self::set($key, $data);
        }
        return true;
    }

    public static function del2HashKey($key, $hashKey1, $hashKey2)
    {
        $data = self::get($key);
        if ($data && array_key_exists($hashKey1, $data) && array_key_exists($hashKey2, $data[$hashKey1])) {
            unset($data[$hashKey1][$hashKey2]);
            return self::set($key, $data);
        }
        return true;
    }

    public static function hSet($key, $hashKey, $value)
    {
        $data = self::get($key);
        $data[$hashKey] = $value;
        return self::set($key, $data);
    }

    public static function hMSet($key, array $value, $indexField = null, $mutiColumn = false, $indexes = [], $defaultValue = [])
    {
        if (empty($value)) return false;
        list($result) = self::hMSetResult($value, $indexField, $mutiColumn, $indexes, $defaultValue);
        $data = self::get($key);
        foreach ($result as $k => $v) {
            $data[$k] = $v;
        }
        return self::set($key, $data) ? $result : false;
    }

    public static function hM2Set($key, $hashKey, array $values, $indexField = null, $mutiColumn = false, $indexes = [], $defaultValue = [])
    {
        list($result) = self::hMSetResult($values, $indexField, $mutiColumn, $indexes, $defaultValue);
        $data = self::get($key);
        $data[$hashKey] = $result;
        return self::set($key, $data) ? [$hashKey => $result] : false;
    }

    /**
     * @desc 获取一个key下的数据
     * @param $key
     * @param $hashKey
     * @param callable|null $callBack
     * @param bool $autoCache
     * @return bool|mixed
     */
    public static function hGet($key, $hashKey, callable $callBack = null, $autoCache = true)
    {
        $data = self::get($key);
        $ret = false;
        if ($data && self::CACHE_SWICH) {
            if (array_key_exists($hashKey, $data)) {
                $ret = $data[$hashKey];
            }
        }
        if ($ret === false && !empty($callBack)) {
            $ret = $callBack($hashKey);
            if ($autoCache) {
                $data[$hashKey] = $ret;
                self::set($key, $data);
            }
        }
        return $ret;
    }

    /**
     * @desc 查询数据是否存在
     * todo 未测试,暂不能用
     * @param $key
     * @param $hashKey
     * @param callable|null $callBack
     * @param bool $autoCache
     * @return bool
     */
    public static function exists($key, $hashKey, callable $callBack = null, $autoCache = true)
    {
        $data = self::get($key);
        if ($data && self::CACHE_SWICH) {
            if (array_key_exists($hashKey, $data)) {
                if (empty($data[$hashKey])) {
                    return false;
                } else {
                    return true;
                }
            }
        }
        if (!empty($callBack)) {
            $ret = $callBack();
            !$ret && $ret = null;
            $data[$hashKey] = $ret;
            $autoCache && self::set($key, $data);
            return !empty($ret);
        } else {
            return false;
        }
    }


    /**
     * @desc 二级缓存存储数据是否存在的标记
     * @param $key
     * @param $hashKey1
     * @param $hashKey2
     * @param callable|null $callBack
     * @param bool $autoCache
     * @return bool
     */
    public static function exists2($key, $hashKey1, $hashKey2, callable $callBack = null, $autoCache = true)
    {
        $data = self::get($key);
        if ($data && self::CACHE_SWICH) {
            if (!empty($data[$hashKey1])) {
                if (array_key_exists($hashKey2, $data[$hashKey1])) {
                    if (empty($data[$hashKey1][$hashKey2])) {
                        return false;
                    } else {
                        return true;
                    }
                }
            }
        }
        if (!empty($callBack)) {
            $ret = $callBack();
            !$ret && $ret = null;
            $data[$hashKey1][$hashKey2] = $ret;
            $autoCache && self::set($key, $data);
            return !empty($ret);
        } else {
            return false;
        }
    }

    /**
     * @desc 获取指定hashKey数组的对应数据
     * @param $key
     * @param $hashKeys
     * @param callable|null $callBack
     * @param null $indexField 数据转换的字段
     * @param bool $mutiColumn 每个字段下的数据是否保留多个
     * @param bool $autoCache
     * @return bool|array
     */
    public static function hMGet($key, $hashKeys, callable $callBack = null, $indexField = null, $mutiColumn = false, $autoCache = true)
    {
        $data = self::get($key);
        $ret = false;
        if ($data && self::CACHE_SWICH) {
            foreach ($hashKeys as $hashKey) {
                if (array_key_exists($hashKey, $data)) {
                    if (!empty($data[$hashKey])) {
                        $ret[$hashKey] = $data[$hashKey];
                    }
                } else {
                    $ret = false;
                    break;
                }
            }
        }
        if (($ret === false || !$data) && !empty($callBack)) {
            $ret = $callBack($hashKeys);
            list($resultWithNull, $resultNotNull) = self::hMSetResult($ret, $indexField, $mutiColumn, $hashKeys, null);
            if ($autoCache) {
                foreach ($resultWithNull as $k => $v) {
                    $data[$k] = $v;
                }
                self::set($key, $data);
            }
            return $resultNotNull;
        }
        return $ret;
    }

    /**
     * @desc 附件数据获取
     * todo 后续去除模型传递，废除该方法
     * @param $key
     * @param array $hashKeys
     * @param callable|null $callBack
     * @return bool|Collection
     */
    public static function hMGetCollection($key, array $hashKeys, callable $callBack = null)
    {
        $data = self::get($key);
        $ret = false;
        if ($data && self::CACHE_SWICH) {
            $ret = new  Collection();
            foreach ($hashKeys as $hashKey) {
                if ($data->has($hashKey)) {
                    $ret->put($hashKey, $data[$hashKey]);
                } else {
                    $ret = false;
                    break;
                }
            }
        }
        if (($ret === false || !$data) && !empty($callBack)) {
            $ret = $callBack($hashKeys);
            !$data && $data = new  Collection();
            foreach ($ret as $k => $v) {
                $data->put($k, $v);
            }
            self::set($key, $data);
        }
        return $ret;
    }

    /**
     * @desc 帖子列表二级缓存和预加载
     * @param $key
     * @param $hashKey1
     * @param $hashKey2
     * @param callable|null $callBack
     * @param bool $preload
     * @return bool|mixed
     */
    public static function hM2Get($key, $hashKey1, $hashKey2, callable $callBack = null, $preload = false)
    {
        $data = self::get($key);
        $ret = false;
        if ($data && self::CACHE_SWICH) {
            if (array_key_exists($hashKey1, $data) && array_key_exists($hashKey2, $data[$hashKey1])) {
                $ret = $data[$hashKey1][$hashKey2];
            }
        }
        if (($ret === false || !$data) && !empty($callBack)) {
            $ret = $callBack();
            !$data && $data = [];
            if ($preload) {
                $data[$hashKey1] = $ret;
                $ret = $data[$hashKey1][$hashKey2];
            } else {
                $data[$hashKey1][$hashKey2] = $ret;
            }
            self::set($key, $data);
        }
        return $ret;
    }

    private static function hMSetResult($values, $indexField = null, $mutiColumn = false, $indexes = [], $defaultValue = [])
    {
        $resultWithNull = [];
        if ($indexField) {
            if ($mutiColumn) {
                foreach ($values as $item) {
                    $resultWithNull[$item[$indexField]][] = $item;
                }
            } else {
                $resultWithNull = array_column($values, null, $indexField);
            }
        } else {
            $resultWithNull = $values;
        }
        $resultNotNull = $resultWithNull;
        foreach ($indexes as $index) {
            if (!isset($resultWithNull[$index])) {
                $resultWithNull[$index] = $defaultValue;
            }
        }
        return [$resultWithNull, $resultNotNull];
    }

}
