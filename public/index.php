<?php

session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/database.php';

use function App\Database\dbConnection; //$pdo
use DI\Container;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Flash\Messages;
use Slim\Routing\RouteContext;
use Slim\Routing\RouteCollector;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Carbon\Carbon;
use Valitron\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;

$pdo = dbConnection();

$container = new Container();

$container->set('renderer', function () {
    $renderer = new Slim\Views\PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');
    return $renderer;
});
$container->set('flash', function () {
    return new Slim\Flash\Messages();
});

AppFactory::setContainer($container);
$app = AppFactory::createFromContainer($container);

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(MethodOverrideMiddleware::class);
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$container->set('router', function () use ($app) {
    return $app->getRouteCollector()->getRouteParser();
});

$app->get('/', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();
    $flash = getFlashData($messages);
    $wrongUrl = $this->get('flash')->getFirstMessage('wrongUrl');
    $content = [
        'flash' => $flash['message'] ?? '',
        'type' => $flash['type'] ?? '',
        'wrongUrl' => $wrongUrl,
        ];
    return $this->get('renderer')->render($response, 'index.phtml', $content);
})->setName('index');

$app->get('/urls', function ($request, $response, $args) use ($pdo) {
    $messages = $this->get('flash')->getMessages();
    $flash = getFlashData($messages);
    $sql = "SELECT MAX(url_checks.created_at) AS created_at, 
        url_checks.status_code, 
        urls.id, 
        urls.name 
        FROM urls LEFT OUTER JOIN url_checks ON url_checks.url_id = urls.id 
        GROUP BY url_checks.url_id, urls.id, url_checks.status_code 
        ORDER BY urls.id DESC";
    $stmt = $pdo->query($sql);
    $urls = $stmt->fetchAll();
    $content = [
        'urls' => $urls,
        'flash' => $flash['message'] ?? '',
        'type' => $flash['type'] ?? '',
        ];
    return $this->get('renderer')->render($response, 'urls.phtml', $content);
})->setName('urls');

$app->post('/urls', function ($request, $response) use ($pdo) {
    $url = $request->getParsedBody('url');
    $url['url'] = normalizeUrl($url['url']);    
    $valid = new Valitron\Validator($url);
    $valid->rule('required', 'url')->message('URL не должен быть пустым')
        ->rule('url', 'url')->message('Некорректный URL')
        ->rule('lengthMax', 'url', 255)->message('Превышено допустимое количество символов');
    if ($valid->validate($url)) {
        $name = $url['url'];
        $stmt = $pdo->prepare("SELECT * FROM urls WHERE name = :name");
        $stmt->execute(["name" => $name]);
        $url = $stmt->fetch();
        if ($url) {
            $this->get('flash')->addMessage('warning', 'Страница уже существует');
            $route = $this->get("router")->urlFor('show', ['id' => $url['id']]);
            return $response->withRedirect($route);
        } else {
            $date = Carbon::now()->toDateTimeString();
            $sql = "INSERT INTO urls (name, created_at) VALUES (:name, :created_at)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':created_at', $date);
            $stmt->execute();
            $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
            $route = $this->get("router")->urlFor('show', ['id' => $pdo->lastInsertId()]);
            return $response->withRedirect($route);
        }
    } else {
        $error = $valid->errors("url")[0];
        $this->get('flash')->addMessage('wrongUrl', $url['url']);
        $this->get('flash')->addMessage('danger', $error);
        return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', [
            'flash' => $error ?? '',
            'type' => 'danger',
            'wrongUrl' => $url['url']
        ]);
    }
});

$app->get('/urls/{id}', function ($request, $response, $args) use ($pdo) {
    $messages = $this->get('flash')->getMessages();
    $flash = getFlashData($messages);
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = :id");
    $stmt->execute([':id' => $args['id']]);
    $url = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT * FROM url_checks WHERE url_id = :id");
    $stmt->execute([':id' => $args['id']]);
    $url_check = $stmt->fetchAll();
    foreach ($url_check as &$check) {
        $check['h1'] = Str::limit((string)$check['h1'], 200, '...') ?? '';
        $check['title'] = Str::limit((string)$check['title'], 200, '...') ?? '';
        $check['description'] = Str::limit((string)$check['description'], 200, '...') ?? '';
    }
    unset($check);
    $content = [
        'url' => $url,
        'url_check' => $url_check,
        'flash' => $flash['message'] ?? '',
        'type' => $flash['type'] ?? '',
        ];
    return $this->get('renderer')->render($response, 'show.phtml', $content);
})->setName('show');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($pdo) {
    $date = Carbon::now()->toDateTimeString();
    $stmt = $pdo->prepare("SELECT name FROM urls WHERE id = :id");
    $stmt->execute([':id' => $args['url_id']]);
    $url = $stmt->fetch();
    $client = new \GuzzleHttp\Client([
        'timeout' => 10,
        'allow_redirects' => true,
        ]);
    try {
        libxml_use_internal_errors(true);
        $responseGuzzle = $client->get($url['name']);
        $statusCode = $responseGuzzle->getStatusCode();
        $html = (string) $responseGuzzle->getBody();
        $parsedData = parseHtmlData($html, $url['name']);
        libxml_clear_errors();

        $sql = "INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
                VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'url_id' => $args['url_id'],
            'status_code' => $statusCode,
            'h1' => $parsedData['h1'],
            'title' => $parsedData['title'],
            'description' => $parsedData['description'],
            'created_at' => Carbon::now()
        ]);

        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (GuzzleHttp\Exception\RequestException $e) {
        $this->get('flash')->addMessage('warning', 'Произошла ошибка при проверке, не удалось подключиться');
    } catch (Exception $e) {
        $this->get('flash')->addMessage('warning', 'Произошла ошибка при проверке, не удалось подключиться');
    }
    $route = $this->get('router')->urlFor('show', ['id' => $args['url_id']]);
    return $response->withRedirect($route);
})->setName('checks');

$app->run();

function normalizeUrl($url)
{
    $parsed = parse_url($url);
    $scheme = $parsed['scheme'] ?? 'http';
    $host = $parsed['host'] ?? "";

    return strtolower("{$scheme}://{$host}");
}

function parseHtmlData($html, $url)
{
    $data = [
        'h1' => null,
        'title' => null,
        'description' => null
    ];

    $dom = new DOMDocument();

    try {
        $dom->loadHTML($html);

        $h1Tags = $dom->getElementsByTagName('h1');
        if ($h1Tags->length > 0) {
            $data['h1'] = trim($h1Tags->item(0)->textContent);
        }
        $titleTags = $dom->getElementsByTagName('title');
        if ($titleTags->length > 0) {
            $data['title'] = trim($titleTags->item(0)->textContent);
        }
        $metaTags = $dom->getElementsByTagName('meta');
        foreach ($metaTags as $meta) {
            if ($meta->getAttribute('name') === 'description') {
                $data['description'] = trim($meta->getAttribute('content'));
                break;
            }
        }

        return $data;
    } catch (Exception $e) {
        error_log("Failed to load HTML: " . $e->getMessage());
    }
}

function getFlashData($messages)
{
    $flash = [];
    foreach ($messages as $type => $messages) {
        foreach ($messages as $message) {
             $flash = [
                'type' => $type,
                'message' => $message
                ];
        }
    }
    return $flash;
}
