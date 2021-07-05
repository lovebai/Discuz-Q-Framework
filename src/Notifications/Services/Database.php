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

namespace Discuz\Notifications\Services;

use App\Models\Thread;
use Illuminate\Support\Str;

class Database extends AbstractDriver
{
    public function build()
    {
        $notification = $this->notification->render();

        // 对title/content进行截取
        if (isset($notification['title']) && !empty($notification['title']) && mb_strlen($notification['title']) > Thread::TITLE_LENGTH) {
            $notification['title'] = $this->getThreadTitleOrContent($notification['title'], Thread::TITLE_LENGTH);
        }

        if (isset($notification['thread_title']) && !empty($notification['thread_title']) && mb_strlen($notification['thread_title']) > Thread::TITLE_LENGTH) {
            $notification['thread_title'] = $this->getThreadTitleOrContent($notification['thread_title'], Thread::TITLE_LENGTH);
        }

        if (isset($notification['content']) && !empty($notification['content']) && mb_strlen($notification['content']) > Thread::NOTICE_CONTENT_LENGTH) {
            $notification['content'] = $this->getThreadTitleOrContent($notification['content'], Thread::NOTICE_CONTENT_LENGTH);
        }

        if (isset($notification['post_content']) && !empty($notification['post_content']) && mb_strlen($notification['post_content']) > Thread::NOTICE_CONTENT_LENGTH) {
            $notification['post_content'] = $this->getThreadTitleOrContent($notification['post_content'], Thread::NOTICE_CONTENT_LENGTH);
        }

        if (isset($notification['reply_post_content']) && !empty($notification['reply_post_content']) && mb_strlen($notification['reply_post_content']) > Thread::NOTICE_CONTENT_LENGTH) {
            $notification['reply_post_content'] = $this->getThreadTitleOrContent($notification['reply_post_content'], Thread::NOTICE_CONTENT_LENGTH);
        }
        // dd($notification);
        return $notification;
    }

    private function getThreadTitleOrContent($titleOrContent, $length)
    {
        $titleOrContent = Str::substr(strip_tags($titleOrContent), 0, $length);
        return $titleOrContent;
    }
}
