<?php

namespace rockunit;

use rock\base\Alias;
use rock\cache\Memcached;
use rock\request\Request;
use rock\route\filters\AccessFilter;
use rock\route\filters\RateLimiter;
use rock\route\providers\Local;
use rock\route\Route;

/**
 * @group base
 * @group route
 */
class RouteConfigTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        $_SERVER['REQUEST_METHOD'] = $_POST['_method'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        static::getCache()->flush();
    }

    protected function setUp()
    {
        parent::setUp();
        Alias::$aliases = [];
    }

    /**
     * @param array $config
     * @return \rock\cache\CacheInterface
     */
    protected static function getCache(array $config = [])
    {
        return new Memcached($config);
    }

    // tests:

    public function testGroupPattern()
    {
        $_SERVER['REQUEST_URI'] = '/ajax/news/7/';
        $route = (new Route([
            'groups' => [
                [
                    Route::ANY,
                    '/ajax/{url:.+}',
                    'path' => '/ajax/',
                    'rules' => [
                        'foo' => [
                            Route::GET,
                            '/news/{id:\d+}/',
                            function (Route $route) {
                                $this->assertEquals(7, $route['id']);
                                $this->assertSame('news/7/', $route['url']);
                                return 'success';
                            }
                        ],
                    ]
                ]

            ],
        ]));
        $route->run();
        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertSame('success', $route->response->data);
    }

    /**
     * @dataProvider providerGroupPatternAsArraySuccess
     */
    public function testGroupPatternAsArraySuccess($host, $path, $get, $filters, $customPath, $rule, $params, $alias)
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = $host;
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['QUERY_STRING'] = $get;
        $route =
            (new Route([
                'groups' => [
                    [
                        Route::ANY,
                        $filters,
                        'rules' => [
                            'foo' => [
                                Route::GET,
                                $rule,
                                function (Route $route) use ($params) {
                                    $this->assertEquals($params, $route->getParams());
                                    return 'success';
                                }
                            ],
                        ],
                        'path' => $customPath
                    ]

                ],
            ]));
        $route->run();

        $this->assertSame($alias, Alias::getAlias('@foo'));
        $this->assertSame(0, $route->getErrors());
        $this->assertSame('success', $route->response->data);
    }

    public function providerGroupPatternAsArraySuccess()
    {
        return [
            ['ajax.site.com', '/news/7/', null, [Route::FILTER_HOST => 'ajax.site.com'], null, '/news/{id:\d+}/',
                ['id' => 7], 'http://ajax.site.com/news/{id}/'
            ],
            ['ajax.site.com', '/news/7/', null, [Route::FILTER_HOST => '{sub:\w+}.site.com'], null, '/news/{id:\d+}/',
                ['id' => 7, 'sub' => 'ajax'], 'http://{sub}.site.com/news/{id}/'
            ],
            ['ajax.site.com', '/news/7/', null, [Route::FILTER_HOST => '{sub:\w+}.site.com', Route::FILTER_PATH => '/news/{url:.+}'], '/news/', '/{id:\d+}/',
                ['id' => 7, 'url' => '7/', 'sub' => 'ajax'], 'http://{sub}.site.com/news/{id}/'
            ],
            ['ajax.site.com', '/news/7/', 'view=all', [Route::FILTER_HOST => '{sub:\w+}.site.com', Route::FILTER_PATH => '/news/{url:.+}', Route::FILTER_GET => ['view' => 'all']], '/news/', '/{id:\d+}/',
                ['id' => 7, 'url' => '7/', 'sub' => 'ajax'], 'http://{sub}.site.com/news/{id}/?view=all'
            ],
        ];
    }

    /**
     * @dataProvider providerGroupPatternAsArrayFail
     */
    public function testGroupPatternAsArrayFail($host, $path, $method, $filters, $errors)
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = $host;
        $_SERVER['REQUEST_URI'] = $path;
        $route = (new Route([
            'groups' => [
                [
                    $method,
                    $filters,
                    'rules' => [
                        'foo' => [
                            Route::GET,
                            '/news/{id:\d+}/',
                            function () {
                                return 'success';
                            }
                        ],
                    ]
                ]

            ],
        ]));
        $route->run();

        $this->assertSame($errors, $route->getErrors());
    }

    public function providerGroupPatternAsArrayFail()
    {
        return [
            ['ajax.site.com', '/news/7/', Route::PUT, [Route::FILTER_HOST => 'ajax.site.com'],
                Route::E_VERBS
            ],
            ['api.site.com', '/news/7/', Route::ANY, [Route::FILTER_HOST => 'ajax.site.com'],
                Route::E_NOT_FOUND
            ],
            ['ajax.site.com', '/news/7/', Route::ANY, [Route::FILTER_PATH => '/articles/7/'],
                Route::E_NOT_FOUND
            ],
            ['ajax.site.com', '/news/7/', Route::ANY, [Route::FILTER_PATH => '/articles/{id:\d+}/'],
                Route::E_NOT_FOUND
            ],
        ];
    }

    public function testPatternSuccess()
    {
        $_SERVER['REQUEST_URI'] = '/news/7/';
        $route = (new Route([
            'rules' => [
                'foo' => [
                    Route::GET,
                    '/news/{id:\d+}/',
                    function (Route $route) {
                        $this->assertEquals(7, $route['id']);
                        return 'success';
                    }
                ],
            ],
        ]));
        $route->run();

        $this->assertSame('/news/15/', Alias::getAlias('@foo', ['id' => 15]));
        $this->assertSame('success', $route->response->data);
    }

    public function testPatternAsArraySuccess()
    {
        $_SERVER['REQUEST_URI'] = '/bar/test';
        $_SERVER['QUERY_STRING'] = 'query=&view=all-views';
        $route = (new Route(
            [
                'rules' =>
                    [
                        'bar' => [
                            Route::GET,
                            [
                                Route::FILTER_PATH => '/bar/{name}',
                                Route::FILTER_GET => ['query' => true, 'view' => '{order}-views'],

                            ],
                            function (Route $route) {
                                $this->assertEquals(['name' => 'test', 'order' => 'all'], $route->getParams());
                                return 'success';
                            }
                        ],
                    ],

            ]

        ));
        $route->run();
        $this->assertSame('/bar/text?view={order}-views', Alias::getAlias('@bar', ['name' => 'text']));
        $this->assertSame('success', $route->response->data);
    }


    /**
     * @dataProvider providerPatternAsArrayFail
     */
    public function testPatternAsArrayFail($path, $query, $getView, $method, $output = null)
    {
        $_SERVER['REQUEST_URI'] = $path;
        $get = [];
        if (isset($getView)) {
            $get['view'] = $getView;
        }
        if (isset($query)) {
            $get['query'] = $query;
        }
        $_SERVER['QUERY_STRING'] = http_build_query($get);
        $route = (new Route(
            [
                'rules' =>
                    [
                        'bar' => [
                            $method,
                            [
                                Route::FILTER_PATH => '/bar/{name:\w+}',
                                Route::FILTER_GET => ['query' => true, 'view' => '{order}-views'],

                            ],
                            function () {
                                return 'success';
                            }
                        ],
                    ],
                'fail' =>
                    function (Route $route) {
                        echo 'fail' . $route->getErrors();
                    }

            ]

        ));
        $route->run();
        $this->expectOutputString($output);
    }

    public function providerPatternAsArrayFail()
    {
        return [
            [
                '/bar/test', null, 'all-views', Route::GET,
                'fail' . Route::E_NOT_FOUND
            ],
            [
                '/baz/test', '', 'all-views', Route::GET,
                'fail' . Route::E_NOT_FOUND
            ],
            [
                '/15/test', '', 'all-views', Route::GET,
                'fail' . Route::E_NOT_FOUND
            ],
            [
                '/bar/test', '', 'all-views', Route::POST,
                'fail' . Route::E_VERBS
            ]
        ];
    }

    public function testInjectArgs()
    {
        $_POST['_method'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $route = (new Route(

            [
                'rules' =>
                    [
                        [
                            Route::GET,
                            '/',
                            [FooController::className(), 'actionIndex']
                        ],
                    ],

            ]

        ));
        $route->run();
        $this->expectOutputString(Request::className());
    }

    /**
     * @dataProvider providerSuccess
     */
    public function testSuccess($request, $pattern, $httpMethods, $filters = null, $output)
    {
        call_user_func($request);
        (new Route(

            [
                'rules' =>
                    [
                        [
                            $httpMethods,
                            $pattern,
                            function (Route $route) {
                                //var_dump($route['controller']);
                                echo $route['controller'] . 'action';
                                return '';
                            },
                            'filters' => $filters
                        ],
                    ],
                'success' =>
                    function (Route $route) {
                        $this->assertSame(0, $route->getErrors());
                        echo 'success';
                    }
                ,
                'fail' =>
                    function (Route $route) {
                        echo 'fail' . $route->getErrors();
                    }
            ]

        ))
            ->run();

        $this->expectOutputString($output);
    }


    public function providerSuccess()
    {
        return [
            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/';
                    $_POST['_method'] = null;
                },
                '/',
                Route::GET,
                null,
                'successaction'
            ],
            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/';
                    $_POST['_method'] = Route::PUT;
                },
                '/',
                [Route::PUT],
                null,
                'successaction'
            ],
            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/';
                    $_POST['_method'] = Route::PUT;
                },
                '/',
                Route::ANY,
                null,
                'successaction'
            ],
            [

                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/tags/';
                    $_POST['_method'] = null;
                },
                '/{controller:(?:news|tags)}/',
                Route::GET,
                null,
                'successtagsaction'
            ],
            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'admin.site.com';
                    $_SERVER['REQUEST_URI'] = '/news/';
                    $_SERVER['QUERY_STRING'] = 'test=foo';
                    $_POST['_method'] = null;
                },
                [
                    Route::FILTER_HOST => 'admin.site.com',
                    Route::FILTER_PATH => '/{controller:(?:news|tags)}/',
                    Route::FILTER_GET => ['test' => 'foo']
                ],
                Route::GET,
                null,
                'successnewsaction'
            ],

            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/';
                    $_SERVER['REMOTE_ADDR'] = '10.2.3';
                    $_POST['_method'] = null;
                },
                '/',
                Route::GET,
                [
                    'access' => [
                        'class' => AccessFilter::className(),
                        'rules' => [
                            'allow' => true,
                            'ips' => ['10.2.3']
                        ],
                        'success' => function () {
                            echo 'success_behavior';
                        },
                        'fail' => function () {
                            echo 'fail_behavior';
                        }
                    ]
                ],
                'success_behaviorsuccessaction'
            ],

            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/';
                    $_SERVER['REMOTE_ADDR'] = '10.2.3';
                    $_POST['_method'] = null;
                },
                '/',
                Route::GET,
                function () {
                    return true;
                },
                'successaction'
            ],
        ];
    }

    /**
     * @dataProvider providerFail
     */
    public function testFail($request, $pattern, $verb, $filters = null, $output, $errors)
    {
        call_user_func($request);
        $route = (new Route(

            [
                'rules' =>
                    [
                        [
                            $verb,
                            $pattern,
                            function (Route $route) {
                                echo $route['controller'] . 'action';
                            },
                            'filters' => $filters
                        ],
                    ],
                'success' =>
                    function (Route $route) {
                        echo 'success' . $route->getErrors();
                    }
                ,
                'fail' =>
                    function (Route $route) {
                        echo 'fail' . $route->getErrors();
                    }

            ]

        ));
            $route->run();
        $this->assertSame($errors, $route->getErrors());
        $this->expectOutputString($output);
    }

    public function providerFail()
    {
        return [
            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/vv';
                    $_POST['_method'] = null;
                },
                '/',
                Route::GET,
                null,
                'fail' . Route::E_NOT_FOUND,
                Route::E_NOT_FOUND
            ],
            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/';
                    $_POST['_method'] = Route::GET;
                },
                '/',
                [Route::PUT],
                null,
                'fail' . Route::E_VERBS,
                Route::E_VERBS
            ],
            [

                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/foo/';
                    $_POST['_method'] = null;
                },
                '/{controller:(?:news|tags)}/',
                Route::GET,
                null,
                'fail' . Route::E_NOT_FOUND,
                Route::E_NOT_FOUND
            ],
            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/news/';
                    $_POST['_method'] = null;
                    $_SERVER['QUERY_STRING'] = 'test=bar';
                },
                [
                    Route::FILTER_HOST => 'site.com',
                    Route::FILTER_PATH => '/{controller:(?:news|tags)}/',
                    Route::FILTER_GET => ['test' => 'foo']
                ],
                Route::GET,
                null,
                'fail' . Route::E_NOT_FOUND,
                Route::E_NOT_FOUND
            ],

            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/';
                    $_SERVER['REMOTE_ADDR'] = '10.2.3';
                    $_POST['_method'] = null;
                },
                '/',
                Route::GET,
                [
                    'access' => [
                        'class' => AccessFilter::className(),
                        'rules' =>
                            [
                                'allow' => false,
                                'ips' => ['10.2.3']
                            ],
                        'success' =>
                            function () {
                                echo 'success_behavior';
                            }
                        ,
                        'fail' =>
                            function () {
                                echo 'fail_behavior';
                            }
                        ,
                    ]
                ],
                'fail_behavior' . 'fail' . (Route::E_IPS),
                Route::E_IPS
            ],

            [
                function () {
                    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
                    $_SERVER['REQUEST_URI'] = '/';
                    $_SERVER['REMOTE_ADDR'] = '10.2.3';
                    $_POST['_method'] = null;
                },
                '/',
                Route::GET,
                function () {
                    return false;
                },
                'fail' . Route::E_NOT_FOUND,
                Route::E_NOT_FOUND
            ],
        ];
    }

    public function testMultiRulesSuccess()
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
        $_SERVER['REQUEST_URI'] = '/';

        $route = (new Route(

            [
                'rules' =>
                    [
                        [
                            Route::GET,
                            '/news/',
                            function () {
                                echo 'action1';
                            }
                        ],
                        [
                            Route::POST,
                            '/',
                            function () {
                                echo 'action2';
                            }
                        ],
                        [
                            Route::GET,
                            '/',
                            function () {
                                echo 'action3';
                            }
                        ],
                    ],
                'success' =>
                    function (Route $route) {
                        $this->assertSame(0, $route->getErrors());
                        echo 'total_success';
                    }
                ,
                'fail' =>
                    function (Route $route) {
                        echo 'total_fail' . $route->getErrors();
                    }

            ]

        ));
        $route->run();
        $this->assertSame(0, $route->getErrors());
        $this->expectOutputString('total_successaction3');
    }

    public function testMultiRulesFail()
    {

        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
        $_SERVER['REQUEST_URI'] = '/';

        $route = (new Route(

            [
                'rules' =>
                    [
                        [
                            Route::GET,
                            '/news/',
                            function () {
                                echo 'action1';
                            }
                        ],
                        [
                            Route::POST,
                            '/',
                            function () {
                                echo 'action2';
                            }
                        ],
                    ],
                'success' =>
                    function (Route $route) {
                        echo 'success' . $route->getErrors();
                    }
                ,
                'fail' =>
                    function (Route $route) {
                        echo 'fail' . $route->getErrors();
                    }

            ]

        ));
        $route->run();
        $this->assertSame((Route::E_VERBS | Route::E_NOT_FOUND), $route->getErrors());
        $this->expectOutputString('fail' . (Route::E_VERBS | Route::E_NOT_FOUND));
    }


    /**
     * @dataProvider providerRESTSuccess
     */
    public function testRESTSuccess($url, $httpMethods, $alias, $path, $output)
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
        $_SERVER['REQUEST_URI'] = $url;
        $_POST['_method'] = $httpMethods;

        $route = new Route(
            [
                'rules' =>
                    [
                        [
                            Route::REST,
                            'orders',
                            OrdersController::className(),
                            'only' => ['index', 'show', 'update', 'create', 'delete']
                        ],
                    ],
                'success' =>
                    function (Route $route) {
                        $this->assertSame(0, $route->getErrors());
                        echo 'success';
                    }
                ,
                'fail' =>
                    function (Route $route) {
                        echo 'fail' . $route->getErrors();
                    }
            ]
        );

        $route->run();

        $this->assertSame($route->getErrors(), 0);
        $this->assertSame($path, Alias::getAlias($alias));
        $this->expectOutputString($output);
    }

    public function providerRESTSuccess()
    {
        return [
            ['/orders/', null, '@orders.index', '/orders/', 'successindex'],
            ['/orders/77/', 'PUT', '@orders.update', '/orders/{id}/', 'successupdate'],
            ['/orders/77/', 'PATCH', '@orders.update', '/orders/{id}/', 'successupdate'],
            ['/orders/77/', null, '@orders.show', '/orders/{id}/', 'successshow'],
            ['/orders/', 'POST', '@orders.create', '/orders/', 'successcreate'],
        ];
    }

    /**
     * @dataProvider providerRESTFail
     */
    public function testRESTFail($url, $verb, $errors, $output)
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
        $_SERVER['REQUEST_URI'] = $url;
        $_POST['_method'] = $verb;

        $route = new Route(
            [
                'rules' =>
                    [
                        [
                            Route::REST,
                            'orders',
                            OrdersController::className(),
                            'only' => ['index', 'show', 'create']
                        ],
                    ],
                'success' =>
                    function (Route $route) {
                        echo 'success' . $route->getErrors();
                    }
                ,
                'fail' =>
                    function (Route $route) {
                        echo 'fail' . $route->getErrors();
                    }

            ]
        );

        $route->run();

        $this->assertSame($route->getErrors(), $errors);
        $this->expectOutputString($output);
    }

    public function providerRESTFail()
    {
        return [
            ['/order', 'GET', (Route::E_NOT_FOUND | Route::E_VERBS), 'fail' . (Route::E_NOT_FOUND | Route::E_VERBS)],
            ['/order/77/', 'GET', (Route::E_NOT_FOUND | Route::E_VERBS), 'fail' . (Route::E_NOT_FOUND | Route::E_VERBS)],
            ['/orders/77/fail/', 'GET', (Route::E_NOT_FOUND | Route::E_VERBS), 'fail' . (Route::E_NOT_FOUND | Route::E_VERBS)],
            ['/orders/', 'PUT', Route::E_VERBS, 'fail' . Route::E_VERBS],
            ['/orders/77/', 'PUT', Route::E_VERBS, 'fail' . Route::E_VERBS],
            ['/orders/77', 'POST', (Route::E_NOT_FOUND | Route::E_VERBS), 'fail' . (Route::E_NOT_FOUND | Route::E_VERBS)],
            ['/orders/create/77', null, (Route::E_NOT_FOUND | Route::E_VERBS), 'fail' . (Route::E_NOT_FOUND | Route::E_VERBS)],
        ];
    }

    public function testRouteLocalRulesSuccess()
    {
        $_SERVER['REQUEST_URI'] = '/news/7/';
        $route = (new Route([
            'rules' => [
                'foo' => [
                    Route::GET,
                    '/news/{id:\d+}/',
                    function (Route $route) {
                        $this->assertEquals(7, $route['id']);
                        return 'success';
                    }
                ],
                'bar' => [
                    Route::GET,
                    [
                        Route::FILTER_PATH =>  '/bar/{id:\d+}/',
                        Route::FILTER_GET => [
                            'view' => 'all',
                            'query' => ''
                        ]
                    ],
                    function (Route $route) {
                        $this->assertEquals(2, $route['id']);
                        return 'barsuccess';
                    }
                ],
            ],
        ]));
        $route->run();

        $this->assertSame('/news/15/', Alias::getAlias('@foo', ['id' => 15]));

        $response = (new Local(['route' => $route]))->send('/bar/2/?view=all&query=');
        $this->assertSame('barsuccess', $response->data);
        $this->assertSame('view=all&query=', $response->request->getQueryString());
        $this->assertSame('http://site.com/bar/2/?view=all&query=', $response->request->getAbsoluteUrl());

        $response = (new Local(['route' => $route]))->send('/bar/2/?view=none', ['query' => ['view' => 'all', 'query' => '']]);
        $this->assertSame('barsuccess', $response->data);
        $this->assertSame('view=all&query=', $response->request->getQueryString());
        $this->assertSame('http://site.com/bar/2/?view=all&query=', $response->request->getAbsoluteUrl());
    }

    public function testRouteLocalRulesFail()
    {
        $_SERVER['REQUEST_URI'] = '/news/7/';
        $route = (new Route([
            'rules' => [
                'foo' => [
                    Route::GET,
                    '/news/{id:\d+}/',
                    function (Route $route) {
                        $this->assertEquals(7, $route['id']);
                        return 'success';
                    }
                ],
                'bar' => [
                    Route::GET,
                    [
                        Route::FILTER_PATH =>  '/bar/{id:\d+}/',
                        Route::FILTER_GET => [
                            'view' => 'all',
                            'query' => ''
                        ]
                    ],
                    function (Route $route) {
                        $this->assertEquals(2, $route['id']);
                        return 'barsuccess';
                    }
                ],
            ],
        ]));
        $route->run();

        // null
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=all');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=none&query=');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('/bar/2/');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=none', ['query' => ['view' => 'all']]);
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=none', ['query' => ['view' => 'none', 'query' => '']]);
        $this->assertNull($response);
    }

    public function testRouteLocalGroupsSuccess()
    {
        $_SERVER['REQUEST_URI'] = '/ajax/news/7/';
        $route = (new Route([
            'groups' => [
                [
                    Route::ANY,
                    '/ajax/{url:.+}',
                    'path' => '/ajax/',
                    'rules' => [
                        'foo' => [
                            Route::GET,
                            '/news/{id:\d+}/',
                            function (Route $route) {
                                $this->assertEquals(7, $route['id']);
                                $this->assertSame('news/7/', $route['url']);
                                return 'success';
                            }
                        ],
                    ]
                ],
                [
                    Route::GET,
                    [
                        Route::FILTER_HOST =>  'admin.site.com',
                        Route::FILTER_GET => [
                            'view' => 'all',
                            'query' => ''
                        ]
                    ],
                    'rules' => [
                        'bar' => [
                            Route::GET,
                            [
                                Route::FILTER_PATH =>  '/bar/{id:\d+}/',
                                Route::FILTER_GET => [
                                    'view' => 'all',
                                    'query' => ''
                                ]
                            ],
                            function (Route $route) {
                                $this->assertEquals(2, $route['id']);
                                return 'barsuccess';
                            }
                        ],
                    ],
                    'filters' => function(Route $route){
                        return $route->request->isAjax();
                    }
                ]

            ],
        ]));
        $route->run();
        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertSame('success', $route->response->data);

        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=all&query=', ['isAjax' => true]);
        $this->assertSame('barsuccess', $response->data);
        $this->assertSame('view=all&query=', $response->request->getQueryString());
        $this->assertSame('http://admin.site.com/bar/2/?view=all&query=', $response->request->getAbsoluteUrl());

        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=none', ['isAjax' => true, 'query' => ['view' => 'all', 'query' => '']]);
        $this->assertSame('barsuccess', $response->data);
        $this->assertSame('view=all&query=', $response->request->getQueryString());
        $this->assertSame('http://admin.site.com/bar/2/?view=all&query=', $response->request->getAbsoluteUrl());
    }

    public function testRouteLocalGroupsFail()
    {
        $_SERVER['REQUEST_URI'] = '/ajax/news/7/';
        $route = (new Route([
            'groups' => [
                [
                    Route::ANY,
                    '/ajax/{url:.+}',
                    'path' => '/ajax/',
                    'rules' => [
                        'foo' => [
                            Route::GET,
                            '/news/{id:\d+}/',
                            function (Route $route) {
                                $this->assertEquals(7, $route['id']);
                                $this->assertSame('news/7/', $route['url']);
                                return 'success';
                            }
                        ],
                    ]
                ],
                [
                    Route::GET,
                    [
                        Route::FILTER_HOST =>  'admin.site.com',
                        Route::FILTER_GET => [
                            'view' => 'all',
                            'query' => ''
                        ]
                    ],
                    'rules' => [
                        'bar' => [
                            Route::GET,
                            [
                                Route::FILTER_PATH =>  '/bar/{id:\d+}/',
                                Route::FILTER_GET => [
                                    'view' => 'all',
                                    'query' => ''
                                ]
                            ],
                            function (Route $route) {
                                $this->assertEquals(2, $route['id']);
                                return 'barsuccess';
                            }
                        ],
                    ]
                ]

            ],
        ]));
        $route->run();
        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertSame('success', $route->response->data);

        // null
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=all');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=all');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=none&query=');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=none', ['query' => ['view' => 'all']]);
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=none', ['query' => ['view' => 'none', 'query' => '']]);
        $this->assertNull($response);
    }

    public function testCacheGroup()
    {
        $cache = static::getCache();
        $this->assertFalse($cache->exists(Route::className()));

        $_SERVER['REQUEST_URI'] = '/ajax/news/7/';
        $groups = [
            [
                Route::ANY,
                '/ajax/{url:.+}',
                'path' => '/ajax/',
                'rules' => [
                    'foo' => [
                        Route::GET,
                        '/news/{id:\d+}/',
                        function (Route $route) {
                            $this->assertEquals(7, $route['id']);
                            $this->assertSame('news/7/', $route['url']);
                            return 'success';
                        }
                    ],
                ]
            ],

        ];
        $route = (new Route([
            'groups' => $groups,
            'cache' => $cache,
            'enableCache' => true
        ]));
        $route->run();
        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertCount(2, $cache->get(Route::className()));
        $this->assertSame('success', $route->response->data);

        Alias::$aliases = [];
        $route = (new Route([
            'groups' => $groups,
            'cache' => $cache,
            'enableCache' => true
        ]));
        $route->run();
        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertSame('success', $route->response->data);
    }

    protected function flushCache()
    {
        return (new Route(['cache' => static::getCache()]))->flushCache();
    }
    /**
     * @depends testCacheGroup
     */
    public function testFlushCache()
    {
        $this->assertTrue($this->flushCache());
    }

    /**
     * @depends testFlushCache
     */
    public function testCacheRules()
    {
        $cache = static::getCache();
        $this->assertFalse($cache->exists(Route::className()));

        $_SERVER['REQUEST_URI'] = '/bar/test';
        $_SERVER['QUERY_STRING'] = 'query=&view=all-views';

        $rules = [
            'bar' => [
                Route::GET,
                [
                    Route::FILTER_PATH => '/bar/{name}',
                    Route::FILTER_GET => ['query' => true, 'view' => '{order}-views'],

                ],
                function (Route $route) {
                    $this->assertEquals(['name' => 'test', 'order' => 'all'], $route->getParams());
                    return 'success';
                }
            ],
        ];
        $route = (new Route([
            'rules' => $rules,
            'cache' => $cache,
            'enableCache' => true
        ]));
        $route->run();
        $this->assertSame('/bar/text?view={order}-views', Alias::getAlias('@bar', ['name' => 'text']));
        $this->assertTrue($cache->exists(Route::className()));
        $this->assertSame('success', $route->response->data);

        Alias::$aliases = [];

        $route = (new Route([
            'rules' => $rules,
            'cache' => $cache,
            'enableCache' => true
        ]));
        $route->run();
        $this->assertSame('/bar/text?view={order}-views', Alias::getAlias('@bar', ['name' => 'text']));
        $this->assertSame('success', $route->response->data);
    }

    public function testRateLimiterFilter()
    {
        $_SERVER['REQUEST_URI'] = '/';
        $_SESSION = [];

        $route = (new Route(
            [
                'rules' =>
                    [
                        [
                            Route::ANY,
                            '/',
                            function () {

                                echo 'foo';
                            },
                            'filters' => [

                                'rate' => [
                                    'class' => RateLimiter::className(),
                                    'limit' => 2,
                                    'period' => 2,
                                    'sendHeaders' => true
                                ]
                            ]
                        ],
                    ],
                'success' =>
                    function (Route $route) {
                        $this->assertSame(0, $route->getErrors());
                        echo 'success';
                    }
                ,
                'fail' =>
                    function (Route $route) {
                        $this->assertSame(Route::E_RATE_LIMIT, $route->getErrors());
                        echo 'fail';
                    }
            ]

        ));
        $route->run();

        $this->assertSame($_SESSION['_allowance'][Route::className()]["maxRequests"], 1);
        $route->run();
        $this->assertSame($_SESSION['_allowance'][Route::className()]["maxRequests"], 0);
        $route->run();
        $this->assertSame(2, $route->response->getHeaders()->get('x-rate-limit-limit'));
        $this->assertSame(429, $route->response->getStatusCode());
        sleep(4);
        $route->run();
        $this->assertSame($_SESSION['_allowance'][Route::className()]["maxRequests"], 1);

        $_SESSION = [];
        $this->expectOutputString('successfoosuccessfoofailsuccessfoo');
    }
}


class OrdersController
{
    public static function className()
    {
        return get_called_class();
    }

    public function actionIndex()
    {
        echo 'index';
    }

    public function actionShow()
    {
        echo 'show';
    }

    public function actionCreate()
    {
        echo 'create';
    }

    public function actionUpdate()
    {
        echo 'update';
    }

    public function actionDelete()
    {
        echo 'delete';
    }

}

class FooController
{
    public static function className()
    {
        return get_called_class();
    }

    public function actionIndex(Request $request)
    {
        echo $request::className();
    }
}