<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

function escape(string|int|float $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validParty(mixed $value): bool
{
    return is_array($value)
        && is_int($value['id'] ?? null) && $value['id'] > 0
        && is_string($value['name'] ?? null);
}

function validResponse(mixed $value): bool
{
    if (!is_array($value)
        || !is_bool($value['success'] ?? null)
        || !is_int($value['total'] ?? null)
        || !is_array($value['data'] ?? null) || !array_is_list($value['data'])
        || !is_array($value['errors'] ?? null) || !array_is_list($value['errors'])
        || $value['total'] !== count($value['data'])) {
        return false;
    }

    foreach ($value['data'] as $deal) {
        if (!is_array($deal)
            || !is_int($deal['id'] ?? null) || $deal['id'] <= 0
            || !is_string($deal['title'] ?? null)
            || !(is_int($deal['amount'] ?? null) || is_float($deal['amount'] ?? null))
            || !is_finite((float) $deal['amount'])
            || !validParty($deal['responsible'] ?? null)
            || !validParty($deal['company'] ?? null)) {
            return false;
        }
    }

    foreach ($value['errors'] as $error) {
        if (!is_array($error) || !array_key_exists('id', $error)
            || !($error['id'] === null || (is_int($error['id']) && $error['id'] > 0))
            || !is_string($error['code'] ?? null)
            || !is_string($error['message'] ?? null)) {
            return false;
        }
    }

    return $value['success'] === ($value['errors'] === []);
}

$deals = [];
$errors = [];
$pageError = null;
$loaded = false;
$url = getenv('N8N_WEBHOOK_URL') ?: 'http://127.0.0.1:5678/webhook/api/test.task/get.deals';

try {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('В PHP не включено расширение cURL.');
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)
        || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        throw new RuntimeException('Неверный адрес в N8N_WEBHOOK_URL.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        // Редиректы отключены: на открытие страницы приходится один запрос к n8n.
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($body === false) {
        error_log('Ошибка соединения с n8n: ' . $curlError);
        throw new RuntimeException('Не удалось связаться с сервисом сделок. Попробуйте обновить страницу позже.');
    }
    if (!in_array($status, [200, 502], true)) {
        throw new RuntimeException('Сервис сделок вернул HTTP ' . $status . '. Проверьте адрес и запуск workflow.');
    }

    $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!validResponse($result)
        || ($status === 502 && ($result['success'] || $result['data'] !== []))) {
        throw new RuntimeException('Сервис сделок вернул данные в неожиданном формате.');
    }

    $deals = $result['data'];
    $errors = $result['errors'];
    $loaded = true;
    if (!$result['success'] && $deals === []) {
        http_response_code(502);
    }
} catch (JsonException $exception) {
    http_response_code(502);
    $pageError = 'Сервис сделок вернул некорректный JSON.';
} catch (RuntimeException $exception) {
    http_response_code(502);
    $pageError = $exception->getMessage();
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сделки</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h2 mb-1">Сделки</h1>
            <p class="text-secondary mb-0">Список сделок отдела продаж</p>
        </div>
        <a href="./" class="btn btn-outline-primary">Обновить</a>
    </div>

    <?php if ($pageError !== null): ?>
        <div class="alert alert-danger" role="alert"><?= escape($pageError) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert <?= $deals === [] ? 'alert-danger' : 'alert-warning' ?>" role="alert">
            <p class="fw-semibold mb-2"><?= $deals === [] ? 'Не удалось загрузить сделки.' : 'Часть сделок не загрузилась.' ?></p>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li>
                        <?= $error['id'] === null ? 'Список сделок' : 'Сделка ' . escape($error['id']) ?>:
                        <?= escape($error['message']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($loaded): ?>
        <p class="text-secondary">Загружено сделок: <?= count($deals) ?></p>
    <?php endif; ?>

    <div class="table-responsive bg-white border rounded">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden">Сделки с суммами, ответственными и компаниями</caption>
            <thead class="table-light">
            <tr>
                <th scope="col">ID</th>
                <th scope="col">Название</th>
                <th scope="col" class="text-end">Сумма</th>
                <th scope="col">Ответственный</th>
                <th scope="col">Компания</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($deals as $deal): ?>
                <tr>
                    <td><?= escape($deal['id']) ?></td>
                    <td><?= escape($deal['title']) ?></td>
                    <td class="text-end text-nowrap"><?= escape(number_format((float) $deal['amount'], 2, ',', ' ')) ?></td>
                    <td><?= escape($deal['responsible']['name']) ?></td>
                    <td><?= escape($deal['company']['name']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($deals === []): ?>
                <tr>
                    <td colspan="5" class="text-center text-secondary py-4">
                        <?= $loaded && $errors === [] ? 'Сделок пока нет.' : 'Нет данных для отображения.' ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
