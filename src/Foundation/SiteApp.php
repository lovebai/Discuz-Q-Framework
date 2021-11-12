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

namespace Discuz\Foundation;

use App\Providers\RedPacketServiceProvider;
use Discuz\Api\ApiServiceProvider;
use Discuz\Auth\AuthServiceProvider;
use Discuz\Base\DzqLog;
use Discuz\Cache\CacheServiceProvider;
use Discuz\Common\Utils;
use Discuz\Database\DatabaseServiceProvider;
use Discuz\Database\MigrationServiceProvider;
use Discuz\Filesystem\FilesystemServiceProvider;
use Discuz\Http\HttpServiceProvider;
use Discuz\Http\RouteCollection;
use Discuz\Notifications\NotificationServiceProvider;
use Discuz\Qcloud\QcloudServiceProvider;
use Discuz\Queue\QueueServiceProvider;
use Discuz\Search\SearchServiceProvider;
use Discuz\Socialite\SocialiteServiceProvider;
use Discuz\Web\WebServiceProvider;
use Discuz\Wechat\WechatServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class SiteApp
{
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function siteBoot()
    {
        $this->app->instance('env', 'production');
        $this->app->instance('discuz.config', $this->loadConfig());
        $this->app->instance('config', $this->getIlluminateConfig());

        $this->registerBaseEnv();
        $this->registerLogger();

        $this->app->register(HttpServiceProvider::class);
        $this->app->register(DatabaseServiceProvider::class);
        $this->app->register(MigrationServiceProvider::class);
        $this->app->register(FilesystemServiceProvider::class);
        $this->app->register(EncryptionServiceProvider::class);
        $this->app->register(CacheServiceProvider::class);
        $this->app->register(RedisServiceProvider::class);
        if (ARTISAN_BINARY == 'http') {
            $this->app->register(ApiServiceProvider::class);
            $this->app->register(WebServiceProvider::class);
        }
        $this->app->register(BusServiceProvider::class);
        $this->app->register(ValidationServiceProvider::class);
        $this->app->register(HashServiceProvider::class);
        $this->app->register(TranslationServiceProvider::class);
        $this->app->register(AuthServiceProvider::class);
        $this->app->register(SearchServiceProvider::class);
        $this->app->register(QcloudServiceProvider::class);
        $this->app->register(QueueServiceProvider::class);
        $this->app->register(SocialiteServiceProvider::class);
        $this->app->register(NotificationServiceProvider::class);
        $this->app->register(WechatServiceProvider::class);
        $this->app->register(RedPacketServiceProvider::class);

        $this->registerServiceProvider();

        $this->app->registerConfiguredProviders();

        $this->app->boot();
        ARTISAN_BINARY == 'http' && $this->includePluginRoutes($this->app->make(RouteCollection::class));
        return $this->app;
    }
    /**
     * @desc 一次性加载所有插件的路由文件
     * @param RouteCollection $route
     * @return RouteCollection
     */
    private  function includePluginRoutes(RouteCollection $route){
        $plugins = Utils::getPluginList();
        foreach ($plugins as $plugin) {
            $prefix = '/plugin/' . $plugin['name_en'] . '/api/';
            $route->group($prefix, function (RouteCollection $route) use ($plugin) {
                $pluginFiles = $plugin['plugin_' . $plugin['app_id']];
                \App\Common\Utils::setPluginAppId($plugin['app_id']);
                if (isset($pluginFiles['routes'])) {
                    foreach ($pluginFiles['routes'] as $routeFile) {
                        require_once $routeFile;
                    }
                }
            });
        }
        //添加首页路由
        $route->group('', function (RouteCollection $route) {
            require_once $this->app->basePath('routes/other.php');
        });
        Utils::setRouteMap($route->getRouteData());
        return $route;
    }
    protected function registerServiceProvider()
    {
    }

    private function loadConfig()
    {
        if (file_exists($path = $this->app->basePath('config/config.php'))) {
            return include $path;
        }

        return [];
    }

    private function getIlluminateConfig()
    {
        $discuzConfig = [
            'queue' => $this->app->config('queue'),
            'filesystems' => $this->app->config('filesystems'),
            'app' => [
                'key' => $this->app->config('key'),
                'cipher' => $this->app->config('cipher'),
                'locale' => $this->app->config('locale'),
                'fallback_locale' => $this->app->config('fallback_locale'),
            ]
        ];

        if ($this->app->config('cache')) {
            $discuzConfig['cache'] = $this->app->config('cache');
        }

        $config = new ConfigRepository(
            array_merge(
                [
                    'database' => [
                        'default' => 'mysql',
                        'migrations' => 'migrations',
                        'redis' => $this->app->config('redis'),
                        'connections' => [
                            'mysql' => $this->app->config('database')
                        ]
                    ],
                    'cache' => [
                        'default' => 'file', //如果配置的 redis 可用， 会自动切换为redis

                        'stores' => [
                            'file' => [
                                'driver' => 'file',
                                'path' => storage_path('cache/data'),
                            ],
                            'redis' => [
                                'driver' => 'redis',
                                'connection' => 'cache',
                            ],
                        ],

                        'prefix' => 'discuz_cache',

                    ],
                    'view' => [
                        'paths' => [
                            resource_path('views'),
                        ],
                        'compiled' => realpath(storage_path('views')),
                    ]
                ],
                $discuzConfig
            )
        );

        return $config;
    }

    private function registerLogger()
    {
        //最后一条应为'alias' => 'log'。默认错误会输出到最后一条中
        $logs = [
            ['alias' => DzqLog::LOG_WECHAT, 'path' => 'logs/'.DzqLog::LOG_WECHAT.'.log', 'level' => Logger::INFO],
            ['alias' => DzqLog::LOG_PAY, 'path' => 'logs/'.DzqLog::LOG_PAY.'.log', 'level' => Logger::INFO],
            ['alias' => DzqLog::LOG_QCLOUND, 'path' => 'logs/'.DzqLog::LOG_QCLOUND.'.log', 'level' => Logger::INFO],
            ['alias' => DzqLog::LOG_WECHAT_OFFIACCOUNT, 'path' => 'logs/'.DzqLog::LOG_WECHAT_OFFIACCOUNT.'.log', 'level' => Logger::INFO],
            ['alias' => DzqLog::LOG_PERFORMANCE, 'path' => 'logs/'.DzqLog::LOG_PERFORMANCE.'.log', 'level' => Logger::INFO],
            ['alias' => DzqLog::LOG_LOGIN, 'path' => 'logs/'.DzqLog::LOG_LOGIN.'.log', 'level' => Logger::INFO],
            ['alias' => DzqLog::LOG_ADMIN, 'path' => 'logs/'.DzqLog::LOG_ADMIN.'.log', 'level' => Logger::INFO],
            ['alias' => DzqLog::LOG_API, 'path' => 'logs/'.DzqLog::LOG_API.'.log', 'level' => Logger::INFO],
            ['alias' => DzqLog::LOG_ERROR, 'path' => 'logs/'.DzqLog::LOG_ERROR.'.log', 'level' => Logger::INFO],
            ['alias' => DzqLog::LOG_INFO, 'path' => 'logs/'.DzqLog::LOG_INFO.'.log', 'level' => Logger::INFO],
        ];

        foreach ($logs as $log) {
            $handler = new RotatingFileHandler(
                storage_path(Arr::get($log, 'path')),
                200,
                Arr::get($log, 'level')
            );
            $handler->setFormatter(new LineFormatter(null, null, true, true));
            $this->app->instance(Arr::get($log, 'alias'), new Logger(Arr::get($log, 'alias'), [$handler]));
            $this->app->alias(Arr::get($log, 'alias'), LoggerInterface::class);
        }
    }

    protected function registerBaseEnv()
    {
        date_default_timezone_set($this->app->config('timezone', 'UTC'));
    }
}
