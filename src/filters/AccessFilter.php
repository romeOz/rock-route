<?php

namespace rock\route\filters;


use rock\filters\AccessErrorInterface;
use rock\filters\AccessTrait;
use rock\route\RouteEvent;

/**
 * Access provides simple access control based on a set of rules.
 *
 * AccessControl is an route filter. It will check its {@see \rock\route\filters\AccessFilter::$rules} to find
 * the first rule that matches the current context variables (such as user IP address, user role).
 * The matching rule will dictate whether to allow or deny the access to the requested controller
 * action. If no rule matches, the access will be denied.
 *
 * To use AccessControl, declare it in the `behaviors()` method of your controller class.
 * For example, the following declarations will allow authenticated users to access the "create"
 * and "update" actions and deny all other users from accessing these two actions.
 *
 * ```php
 * public function behaviors()
 * {
 *  return [
 *   'access' => [
 *          'class' => AccessControl::className(),
 *          'rules' => [
 *              // deny ip 127.0.0.1
 *              [
 *               'allow' => false,
 *               'ips' => ['127.0.0.1']
 *              ],
 *              // allow authenticated users
 *              [
 *                  'allow' => true,
 *                  'roles' => ['@'],
 *              ],
 *          // everything else is denied
 *          ],
 *      ],
 * ];
 * }
 * ```
 */
class AccessFilter extends RouteFilter implements AccessErrorInterface
{
    use AccessTrait;

    public function before()
    {
        if (!$this->check()) {
            if ($this->event instanceof RouteEvent) {
                $this->event->errors |= $this->errors;
            }
            return false;
        }

        return parent::before();
    }
}