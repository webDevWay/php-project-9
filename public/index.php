<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

$container = new Container();

$container->set('renderer', function () {
    $renderer = new Slim\Views\PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');


    return $renderer;
});

$container->set('flash', function () {
    return new Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);
//AppFactory::setContainer($container);
//$app->add(MethodOverrideMiddleware::class);
//$app->addErrorMiddleware(true, true, true);

//$app = AppFactory::create();

$app->get('/', function ($request, $response, $args) {
    return $this->get('renderer')->render($response, 'index.phtml');
});

$app->get('/hello/{name}', function ($request, $response, $args) {
    $name = $args['name'];
    $response->getBody()->write("Hello, $name");
    return $response;
});

$app->run();
