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

namespace Discuz\Notifications\Channels;

use App\Models\NotificationTpl;
use App\SmsMessages\SendNoticeMessage;
use Discuz\Contracts\Setting\SettingsRepository;
use Discuz\Qcloud\QcloudTrait;
use Illuminate\Notifications\Notification;

/**
 * 短信通知 - 频道
 * Class SmsChannel
 *
 * @package Discuz\Notifications\Channels
 */
class SmsChannel
{
    use QcloudTrait;

    protected $settings;

    /**
     * WechatChannel constructor.
     *
     * @param SettingsRepository $settings
     */
    public function __construct(SettingsRepository $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Send the given notification.
     *
     * @param $notifiable
     * @param Notification $notification
     */
    public function send($notifiable, Notification $notification)
    {
        if (! empty($notifiable->mobile)) {
            // wechat post json
            $variable = $notification->toSms($notifiable);

            /**
             * get Sms Template
             *
             * @var NotificationTpl $notificationData
             */
            $notificationData = $notification->getTplModel('sms');
            $templateID = $notificationData->template_id;

            /** 发送错误时，@see SendNoticeMessage 直接返回了错误信息 */
            $this->smsSend($notifiable->getRawOriginal('mobile'), new SendNoticeMessage([
                'template_id' => $templateID,
                'variable' => $variable
            ]));
        }
    }
}
