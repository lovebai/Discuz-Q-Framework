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

    public static function delKey($key)
    {
        return app('cache')->forget($key);
    }

    public static function delHashKey($key, $hashKey)
    {
        $cache = app('cache');
        $data = $cache->get($key);
        if ($data && array_key_exists($hashKey, $data)) {
            unset($data[$hashKey]);
            return app('cache')->put($key, $data);
        }
        return true;
    }

    public static function set($key, $value, $ttl = null)
    {
        if ($ttl) {
            return app('cache')->put($key, $value, $ttl);
        }
        return app('cache')->put($key, $value);
    }


    public static function get($key)
    {
        return app('cache')->get($key);
    }


    public static function hSet($key, $hashKey, $value)
    {
        $data = app('cache')->get($key);
        $data[$hashKey] = $value;
        return app('cache')->put($key, $data);
    }

    public static function hMSet($key, array $value, $indexField = null, $mutiColumn = false, $indexes = [], $defaultValue = [])
    {
        if (empty($value)) return false;
        $result = self::hMSetResult($value, $indexField, $mutiColumn, $indexes, $defaultValue);
        $data = app('cache')->get($key);
        foreach ($result as $k => $v) {
            $data[$k] = $v;
        }
        return app('cache')->put($key, $data) ? $result : false;
    }

    public static function hM2Set($key, array $value, $hashKey, $indexField = null, $mutiColumn = false, $indexes = [], $defaultValue = [])
    {
        $result = self::hMSetResult($value, $indexField, $mutiColumn, $indexes, $defaultValue);
        $data = app('cache')->get($key);
        $data[$hashKey] = $result;
        return app('cache')->put($key, $data) ? [$hashKey => $result] : false;
    }

    private static function hMSetResult(array $value, $indexField = null, $mutiColumn = false, $indexes = [], $defaultValue = [])
    {
        $result = [];
        if ($indexField) {
            if ($mutiColumn) {
                foreach ($value as $item) {
                    $result[$item[$indexField]][] = $item;
                }
            } else {
                $result = array_column($value, null, $indexField);
            }
        } else {
            $result = $value;
        }
        foreach ($indexes as $index) {
            if (!isset($result[$index])) {
                $result[$index] = $defaultValue;
            }
        }
        return $result;
    }

    public static function hGet($key, $hashKey, callable $callBack = null, $autoCache = true)
    {
        $cache = app('cache');
        $data = $cache->get($key);
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
                $cache->put($key, $data);
            }
        }
        return $ret;
    }

    //未测试,暂不能用
    public static function exists($key, $hashKey, callable $callBack = null, $autoCache = true)
    {
        $cache = app('cache');
        $data = $cache->get($key);
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
            $autoCache && $cache->put($key, $data);
            return !empty($ret);
        } else {
            return false;
        }
    }

    public static function exists2($key, $hashKey1, $hashKey2, callable $callBack = null, $autoCache = true)
    {
        $cache = app('cache');
        $data = $cache->get($key);
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
            if (!empty($data[$hashKey1])) {
                $data[$hashKey1][$hashKey2] = $ret;
                $autoCache && $cache->put($key, $data);
            }
            return !empty($ret);
        } else {
            return false;
        }
    }

    /**
     * @param $key
     * @param $hashKeys
     * @param callable|null $callBack
     * @param bool $autoCache
     * @return bool|array
     */
    public static function hMGet($key, $hashKeys, callable $callBack = null, $autoCache = true)
    {
        $cache = app('cache');
        $data = $cache->get($key);
        $ret = false;
        if ($data && self::CACHE_SWICH) {
            foreach ($hashKeys as $hashKey) {
                if (array_key_exists($hashKey, $data)) {
                    $ret[$hashKey] = $data[$hashKey];
                } else {
                    $ret = false;
                    break;
                }
            }
        }
        if ($ret === false && !empty($callBack)) {
            $ret = $callBack($hashKeys);
            if ($autoCache) {
                foreach ($ret as $k => $v) {
                    $data[$k] = $v;
                }
                $cache->put($key, $data);
            }
        }
        return $ret;
    }

    public static function hMGetCollection($key, array $hashKeys, callable $callBack = null)
    {
        $cache = app('cache');
        $data = $cache->get($key);
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
            foreach ($ret as $key => $value) {
                $data->put($key, $value);
            }
            $cache->put($key, $data);
        }
        return $ret;
    }

    public static function hM2Get($key, $hashKey1, $hashKey2, callable $callBack = null, $preload = false)
    {
        $cache = app('cache');
        $data = $cache->get($key);
        $ret = false;
        if ($data && self::CACHE_SWICH) {
            if (array_key_exists($hashKey1, $data) && array_key_exists($hashKey2, $data[$hashKey1])) {
                $ret = $data[$hashKey1][$hashKey2];
            }
        }
        if (($ret === false || !$data) && !empty($callBack)) {
            $ret = $callBack($hashKey1, $hashKey2);
            !$data && $data = [];
            if ($preload) {
                $data[$hashKey1] = $ret;
                $ret = $data[$hashKey1][$hashKey2];
            } else {
                $data[$hashKey1][$hashKey2] = $ret;
            }
            $cache->put($key, $data);
        }
        return $ret;
    }

}
