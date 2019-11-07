<?php

/**
 *      Discuz & Tencent Cloud
 *      This is NOT a freeware, use is subject to license terms
 *
 *      Id: AbstractPolicy.php xxx 2019-10-10 15:39 chenkeke $
 */

namespace Discuz\Foundation;

use Discuz\Contracts\Policy\Policy;
use Discuz\Api\Events\GetPermission;
use Discuz\Api\Events\ScopeModelVisibility;
use Illuminate\Contracts\Events\Dispatcher;

abstract class AbstractPolicy implements Policy
{
    /**
     * @var string
     */
    protected $model;

    /**
     * @param Dispatcher $events
     */
    public function subscribe(Dispatcher $events)
    {
        $events->listen(GetPermission::class, [$this, 'getPermission']);
        $events->listen(ScopeModelVisibility::class, [$this, 'scopeModelVisibility']);
    }

    /**
     * @param GetPermission $event
     * @return bool|void
     */
    public function getPermission(GetPermission $event)
    {
        if (! $event->model instanceof $this->model) {
            return;
        }

        if (method_exists($this, $event->ability)) {
            $result = call_user_func_array([$this, $event->ability], [$event->actor, $event->model]);

            if (! is_null($result)) {
                return $result;
            }
        }

        if (method_exists($this, 'can')) {
            return call_user_func_array([$this, 'can'], [$event->actor, $event->ability, $event->model]);
        }
    }

    /**
     * @param ScopeModelVisibility $event
     */
    public function scopeModelVisibility(ScopeModelVisibility $event)
    {
        if ($event->query->getModel() instanceof $this->model) {
            if (substr($event->ability, 0, 4) === 'view') {
                $method = 'find'.substr($event->ability, 4);

                if (method_exists($this, $method)) {
                    call_user_func_array([$this, $method], [$event->actor, $event->query]);
                }
            } elseif (method_exists($this, 'findWithPermission')) {
                call_user_func_array([$this, 'findWithPermission'], [$event->actor, $event->query, $event->ability]);
            }
        }
    }
}
