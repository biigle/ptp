<?php

$router->group([
    'middleware' => 'auth',
    'namespace' => 'Api',
    'prefix' => 'api/v1'
], function ($router) {
    $router->get('ptp-test', 'PtpController@generatePtpJob');
});
$router->group([
    'middleware' => 'auth',
    'namespace' => 'Views',
], function ($router) {
    $router->get('ptp/{id}', ['as' => 'volumes-ptp-conversion', 'uses' => 'PtpController@index']);
});
