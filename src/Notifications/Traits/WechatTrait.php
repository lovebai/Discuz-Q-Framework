<?php

/**
 * Copyright (C) 2020 Tencent Cloud.
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *   http://www.apache.org/licenses/LICENSE-2.0
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Discuz\Notifications\Traits;

use Carbon\Carbon;
use Discuz\Http\UrlGenerator;

trait WechatTrait
{
    /**
     * @var array 模板变量数据
     */
    protected $templateData = [];

    /**
     * 设置模板变量
     * (可在该方法赋予全局默认字段)
     *
     * @param array $build
     */
    protected function setTemplateData(array $build)
    {
        $defaultData = [
            '{$notify_time}' => Carbon::now()->toDateTimeString(),
            '{$site_domain}' => app(UrlGenerator::class)->to(''),
        ];

        $this->templateData = array_merge($build, $defaultData);
    }

    /**
     * 构建模板数组
     *
     * @param array $expand 扩展数组
     * @return array
     */
    public function compiledArray($expand = [])
    {
        // first_data
        $firstData = $this->matchRegular($this->firstData->first_data);

        // keywords_data
        $keywords = explode(',', $this->firstData->keywords_data);
        $build = ['first' => $firstData];
        $this->matchKeywords($keywords, $build); // Tag &$build

        // remark_data
        $build['remark'] = $this->matchRegular($this->firstData->remark_data);

        // color
        $build['color'] = $this->firstData->color;

        // redirect_url
        if (! empty($this->firstData->redirect_url)) {
            $redirectUrl = $this->matchRegular($this->firstData->redirect_url);
        } else {
            $redirectUrl = $expand['redirect_url'] ?? '';
        }
        $build['redirect_url'] = $redirectUrl;

        return $build;
    }

    /**
     * 替换数据中心
     *
     * @param string $target 字符串
     * @param string $replace 替换值
     * @return string
     */
    protected function matchReplace(string $target, $replace = '')
    {
        $replace = $replace ?: $target;

        if (isset($this->templateData[$replace])) {
            $target = str_replace($replace, $this->templateData[$replace], $target);
        }

        return $target;
    }

    /**
     * 模板变量替换值
     *
     * @param string $target 目标字符串
     * @return string
     */
    protected function matchRegular(string $target)
    {
        if (preg_match_all('/{\$\w+}/i', $target, $match)) {
            foreach (array_shift($match) as $item) {
                $target = $this->matchReplace($target, $item);
            }
        }

        return $target;
    }

    /**
     * 顺序合并替换
     *
     * @param $build
     * @param array $target 目标数组
     */
    protected function matchKeywords(array $target, &$build)
    {
        foreach ($target as $item) {
            $item = $this->matchRegular($item);
            $key = 'keyword' . count($build);
            // Tag 按顺序合入数组
            $build = array_merge($build, [$key => $item]);
        }
    }
}
