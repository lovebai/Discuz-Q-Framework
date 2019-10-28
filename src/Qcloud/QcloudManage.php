<?php

namespace Discuz\Qcloud;

use Discuz\Contracts\Qcloud\Factory;
use Discuz\Contracts\Setting\SettingsRepository;
use Discuz\Qcloud\Services\BillingService;
use Discuz\Qcloud\Services\CmsService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Arr;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class QcloudManage extends Manager implements Factory
{

    protected $qcloudConfig;

    public function __construct(Container $container)
    {
        parent::__construct($container);

        $settings = $container->make(SettingsRepository::class);
        $encrypter = $container->make(Encrypter::class);

        $this->qcloudConfig = collect($settings->tag('qcloud'))->map(function($value) use ($encrypter) {
            return $value ? $encrypter->decrypt($value) : null;
        });
    }

    public function createBillingDriver()
    {
        return $this->buildProvider(BillingService::class, $this->qcloudConfig);
    }

    public function createCmsDriver()
    {
        return $this->buildProvider(CmsService::class, $this->qcloudConfig);
    }

    /**
     * @param $provider
     * @param $config
     * @return mixed
     */
    public function buildProvider($provider, $config)
    {
        return new $provider($config['secretId'], $config['secretKey']);
    }

    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        throw new InvalidArgumentException('No Qcloud Service was specified.');
    }

    public function service($service)
    {
        return $this->driver($service);
    }
}
