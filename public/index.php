<?php

session_start();

require_once __DIR__ . '/../vendor/autoload.php';

use function App\Database\dbConnection; //$pdo
use DI\Container;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Views\PhpRenderer;
use Slim\Exception\HttpNotFoundException;
use Carbon\Carbon;
use Valitron\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;

$pdo = dbConnection();

$container = new Container();

$container->set('flash', function () {
    return new Messages();
});

AppFactory::setContainer($container);
$app = AppFactory::createFromContainer($container);

$app->addRoutingMiddleware();

$container->set('router', function () use ($app) {
    return $app->getRouteCollector()->getRouteParser();
});

$container->set('renderer', function () use ($container) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');
    $renderer->addAttribute('router', $container->get('router'));
    $renderer->addAttribute('flash', $container->get('flash')->getMessages());

    return $renderer;
});

$app->addBodyParsingMiddleware();

$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app) {
    try {
        return $handler->handle($request);
    } catch (HttpNotFoundException $e) {
        $response = $app->getResponseFactory()->createResponse();
        return $this->get('renderer')->render($response->withStatus(404), '404.phtml');
    } catch (\Throwable $e) {
        $response = $app->getResponseFactory()->createResponse();
        return $this->get('renderer')->render($response->withStatus(500), '500.phtml');
    }
});

$app->addErrorMiddleware(true, true, true);

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'index.phtml');
})->setName('index');

$app->get('/urls', function ($request, $response, $args) use ($pdo) {
    $sql = "SELECT id, name FROM urls ORDER BY id DESC";
    $stmt = $pdo->query($sql);
    $urls = $stmt->fetchAll();
    
    $sql = "SELECT DISTINCT ON (url_id)
                url_id, 
                status_code, 
                created_at 
            FROM url_checks 
            ORDER BY url_id, created_at DESC";
    $stmt = $pdo->query($sql);
    $lastChecks = $stmt->fetchAll();

    $checksMap = [];
    foreach ($lastChecks as $check) {
        $checksMap[$check['url_id']] = $check;
    }
    $urls = array_map(function($url) use ($checksMap) {
        $check = $checksMap[$url['id']] ?? null;
        return [
            'id' => $url['id'],
            'name' => $url['name'],
            'last_check' => $check['created_at'] ?? '-',
            'status_code' => $check['status_code'] ?? '-',
        ];
    }, $urls);

    return $this->get('renderer')->render($response, 'urls/index.phtml', [
        'urls' => $urls,
    ]);
})->setName('urls');

$app->post('/urls', function ($request, $response) use ($pdo) {
    $url = $request->getParsedBody('url');

    $valid = new Validator($url);
    $valid->rule('required', 'url')->message('URL не должен быть пустым')
        ->rule('url', 'url')->message('Некорректный URL')
        ->rule('lengthMax', 'url', 255)->message('Превышено допустимое количество символов');

    if (!$valid->validate()) {
        $errors = $valid->errors('url');
        $error = is_array($errors) ? ($errors[0] ?? '') : '';
        $flash['danger'] = [$error];

        return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', [
            'flash' => $flash,
            'wrongUrl' => $url['url']
        ]);
    }

    $name = normalizeUrl($url['url']);

    $stmt = $pdo->prepare("SELECT * FROM urls WHERE name = :name");
    $stmt->execute(["name" => $name]);
    $url = $stmt->fetch();

    if ($url) {
        $this->get('flash')->addMessage('warning', 'Страница уже существует');

        $route = $this->get("router")->urlFor('urls.show', ['id' => $url['id']]);
    } else {
        $sql = "INSERT INTO urls (name, created_at) VALUES (:name, :created_at)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'created_at' => Carbon::now()
            ]);

        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

        $route = $this->get("router")->urlFor('urls.show', ['id' => $pdo->lastInsertId()]);
    }
    return $response->withRedirect($route);
});

$app->get('/urls/{id:[0-9]+}', function ($request, $response, $args) use ($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = :id");
    $stmt->execute([':id' => $args['id']]);
    $url = $stmt->fetch();

    if (!$url) {
        return $this->get('renderer')->render($response->withStatus(404), '404.phtml');
    }

    $stmt = $pdo->prepare("SELECT * FROM url_checks WHERE url_id = :id");
    $stmt->execute([':id' => $args['id']]);
    $url_check = $stmt->fetchAll();

    $url_check = array_map(function ($check) {
        return [
            ...$check,
            'h1' => Str::limit((string)$check['h1'], 200, '...'),
            'title' => Str::limit((string)$check['title'], 200, '...'),
            'description' => Str::limit((string)$check['description'], 200, '...')
        ];
    }, $url_check);

    $content = [
        'url' => $url,
        'url_check' => $url_check,
        ];

    return $this->get('renderer')->render($response, 'urls/show.phtml', $content);
})->setName('urls.show');

$app->post('/urls/{url_id:[0-9]+}/checks', function ($request, $response, $args) use ($pdo) {
    $stmt = $pdo->prepare("SELECT name FROM urls WHERE id = :id");
    $stmt->execute([':id' => $args['url_id']]);
    $url = $stmt->fetch();

    $client = new Client([
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
    } catch (RequestException $e) {
        $this->get('flash')->addMessage('warning', 'Произошла ошибка при проверке, не удалось подключиться');
        error_log("Ошибка подключения: " .  $e->getMessage());
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('warning', 'Произошла ошибка при проверке, не удалось подключиться');
        error_log("Ошибка подключения: " . $e->getMessage());
    } catch (Exception $e) {
        $this->get('flash')->addMessage('warning', 'Произошла ошибка при проверке, не удалось подключиться');
        error_log("Unexpected error: " .  $e->getMessage());
    }

    $route = $this->get('router')->urlFor('urls.show', ['id' => $args['url_id']]);

    return $response->withRedirect($route);
})->setName('urls.checks.store');

$app->run();

function normalizeUrl(string $url): string
{
    $parsed = parse_url($url);
    $scheme = $parsed['scheme'] ?? '';
    $host = $parsed['host'] ?? "";

    return strtolower("{$scheme}://{$host}");
}

function parseHtmlData(string $html, string $url): array
{
    $data = [
        'h1' => '',
        'title' => '',
        'description' => ''
    ];

    if (empty($html)) {
        return $data;
    }

    $dom = new DOMDocument();

    try {
        $dom->loadHTML($html);

        $h1Tags = $dom->getElementsByTagName('h1');
        if ($h1Tags->length > 0 && $h1Tags->item(0) !== null) {
            $data['h1'] = trim($h1Tags->item(0)->textContent);
        }
        $titleTags = $dom->getElementsByTagName('title');
        if ($titleTags->length > 0 && $titleTags->item(0) !== null) {
            $data['title'] = trim($titleTags->item(0)->textContent);
        }
        $metaTags = $dom->getElementsByTagName('meta');
        foreach ($metaTags as $meta) {
            if ($meta->getAttribute('name') === 'description') {
                $data['description'] = trim($meta->getAttribute('content'));
                break;
            }
        }
    } catch (Exception $e) {
        error_log("Failed to load HTML: " . $e->getMessage());
    }
    return $data;
}
