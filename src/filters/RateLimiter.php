<?php

namespace rock\route\filters;

use rock\filters\RateLimiterTrait;
use rock\route\Route;
use rock\route\RouteEvent;

/**
 * RateLimiter implements a rate limiting.
 *
 * You may use RateLimiter by attaching it as a behavior to a controller or module, like the following,
 *
 * ```php
 * $filters = [
 *         'rateLimiter' => [
 *             'class' => RateLimiter::className(),
 *             'limit' => 10,
 *             'period' => 120
 *         ],
 *     ];
 * ```
 *
 * When the user has exceeded his rate limit, RateLimiter will throw a {@see \rock\filters\RateLimiterException} exception.
 */
class RateLimiter extends RouteFilter
{
    use RateLimiterTrait;

    /**
     * Count of iteration.
     * @var int
     */
    public $limit = 5;
    /**
     * Period rate limit (second).
     * @var int
     */
    public $period = 180;
    /**
     * @var boolean whether to include rate limit headers in the response
     */
    public $sendHeaders = false;
    /**
     * Enabled throw exception.
     * @var bool
     */
    public $throwException = false;
    /**
     * The condition which to run the {@see \rock\filters\RateLimiterTrait::saveAllowance()}.
     * @var callable|bool
     */
    public $dependency = true;
    /**
     * Invert checking.
     * @var bool
     */
    public $invert = false;
    /**
     * Hash-key.
     * @var string
     */
    public $name;

    /**
     * @inheritdoc
     */
    public function before()
    {
        if (!$this->check($this->limit, $this->period, $this->name ? : get_class($this->owner))) {
            if ($this->event instanceof RouteEvent) {
                $this->event->errors |= Route::E_RATE_LIMIT;
            }
            return false;
        }

        return true;
    }
}