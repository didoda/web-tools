<?php
declare(strict_types=1);

/**
 * BEdita, API-first content management framework
 * Copyright 2016 ChannelWeb Srl, Chialab Srl
 *
 * This file is part of BEdita: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * See LICENSE.LGPL or <http://gnu.org/licenses/lgpl-3.0.html> for more details.
 */

use BEdita\WebTools\Plugin as WebToolsPlugin;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Cake\Routing\Router;
use josegonzalez\Dotenv\Loader;

/**
 * Test suite bootstrap for BEdita/WebTools.
 *
 * This function is used to find the location of CakePHP whether CakePHP
 * has been installed as a dependency of the plugin, or the plugin is itself
 * installed as a dependency of an application.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

if (!getenv('BEDITA_API') && file_exists(dirname(__DIR__) . '/tests/.env')) {
    $dotenv = new Loader([dirname(__DIR__) . '/tests/.env']);
    $dotenv->parse()
        ->putenv()
        ->toEnv()
        ->toServer();
}

$findRoot = function ($root) {
    do {
        $lastRoot = $root;
        $root = dirname($root);
        if (is_dir($root . '/vendor/cakephp/cakephp')) {
            return $root;
        }
    } while ($root !== $lastRoot);

    throw new Exception('Cannot find the root of the application, unable to run tests');
};
$root = $findRoot(__FILE__);
unset($findRoot);

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
// before calling bootstrap, define some constants
define('ROOT', dirname(__DIR__));
define('TMP', ROOT . DS . 'tmp' . DS);
define('APP', $root . DS . 'tests' . DS . 'test_app' . DS);
define('CACHE', sys_get_temp_dir() . DS . 'cache' . DS);
define('CONFIG', $root . DS . 'test_app' . DS . 'config' . DS);
define('WWW_ROOT', $root . DS . 'tests' . DS . 'test_app' . DS . 'webroot' . DS);
define('CAKE_CORE_INCLUDE_PATH', ROOT . DS . 'vendor' . DS . 'cakephp' . DS . 'cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);

require CORE_PATH . 'config' . DS . 'bootstrap.php';
require CAKE . 'functions.php';
require $root . DS . 'config' . DS . 'bootstrap.php';

chdir($root);

Configure::write('debug', true);

Configure::write('App', [
    'namespace' => 'TestApp',
    'encoding' => 'utf-8',
    'paths' => [
        'plugins' => [ROOT . 'Plugin' . DS],
        'templates' => [APP . 'templates' . DS],
    ],
]);

Configure::write('Error', [
    'errorLevel' => E_ALL,
    'exceptionRenderer' => 'BEdita\WebTools\Error\ExceptionRenderer',
    'skipLog' => [],
    'log' => true,
    'trace' => true,
]);

Cache::setConfig([
    'default' => [
        'className' => 'File',
        'path' => CACHE,
        'url' => env('CACHE_DEFAULT_URL', null),
    ],
    '_cake_core_' => [
        'engine' => 'File',
        'prefix' => 'cake_core_',
        'serialize' => true,
    ],
    '_cake_model_' => [
        'engine' => 'File',
        'prefix' => 'cake_model_',
        'serialize' => true,
    ],
]);

Configure::write('API', [
    'apiBaseUrl' => env('BEDITA_API', ''),
    'apiKey' => env('BEDITA_API_KEY', ''),
]);

if (!getenv('db_dsn')) {
    putenv('db_dsn=sqlite:///:memory:');
}
ConnectionManager::setConfig('test', ['url' => getenv('db_dsn')]);
Router::reload();

Plugin::getCollection()->add(new WebToolsPlugin());
