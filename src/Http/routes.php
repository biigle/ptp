<?php

$router->group([
    'middleware' => 'auth',
    'namespace' => 'Api',
    'prefix' => 'api/v1'
], function ($router) {
    $router->post('send-ptp-job/{id}', 'PtpController@store');
});
