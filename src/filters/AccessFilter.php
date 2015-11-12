<?php

namespace rock\route\filters;


use rock\filters\AccessTrait;
use rock\route\Route;
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
class AccessFilter extends RouteFilter
{
    use AccessTrait {
        AccessTrait::check as parentCheck;
    }
    /**
     * @var array
     */
    public $rules = [];
    /**
     * Sending response headers. `true` by default.
     * @var bool
     */
    public $sendHeaders = false;
    /**
     * @var int
     */
    protected $errors = 0;

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

    /**
     * @inheritdoc
     */
    public function check()
    {
        if ($this->parentCheck()) {
            $this->errors = 0;
            return true;
        }
        return false;
    }

    /**
     * Returns a errors.
     * @return int
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Checks a username, role and ip.
     * @param array $rule array data of access
     * @return bool|null
     */
    protected function matches(array $rule)
    {
        $rule['allow'] = (bool)$rule['allow'];
        $result = [];
        if (isset($rule['users'])) {
            $result[] = $this->addError($this->matchUsers((array)$rule['users']), Route::E_USERS, $rule['allow']);
        }
        if (isset($rule['ips'])) {
            $result[] = $this->addError($this->matchIps((array)$rule['ips']),  Route::E_IPS, $rule['allow']);
        }
        if (isset($rule['roles'])) {
            $result[] = $this->addError($this->matchRole((array)$rule['roles']),  Route::E_ROLES, $rule['allow']);
        }
        if (isset($rule['custom'])) {
            $result[] = $this->addError($this->matchCustom($rule),  Route::E_CUSTOM, $rule['allow']);
        }
        if (empty($result)) {
            return null;
        }
        if (in_array(false, $result, true)) {
            return null;
        }

        return $rule['allow'];
    }

    /**
     * Adds a error.
     * @param bool $is
     * @param int $error
     * @param bool $allow
     * @return bool
     */
    protected function addError($is, $error, $allow)
    {
        if ($is === false || $allow === false) {
            $this->errors |= $error;
        }

        return $is;
    }
}