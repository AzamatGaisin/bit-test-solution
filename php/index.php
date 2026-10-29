<?php
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

// Текст из API выводим как текст, даже если в нем есть HTML-теги.
function escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Здесь нужен Production URL опубликованного workflow.
$url = getenv('N8N_WEBHOOK_URL') ?: 'http://127.0.0.1:5678/webhook/api/test.task/get.deals';
$deals = [];
$errors = [];
$pageError = '';

// PHP делает один GET-запрос. Список ID и детали сделок получает n8n.
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 60,
]);
$body = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($body === false) {
    $pageError = 'Не удалось связаться с n8n.';
} else {
    $result = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $pageError = 'n8n вернул некорректный JSON. HTTP ' . $status . '.';
    } elseif (!isset($result['data']) || !is_array($result['data'])) {
        $pageError = 'В ответе n8n нет списка сделок. HTTP ' . $status . '.';
    } else {
        // При частичной загрузке показываем и сделки, и сообщения об ошибках.
        $deals = array_filter($result['data'], 'is_array');
        $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : [];
        if (!$deals && !$errors && ($status >= 300 || ($result['success'] ?? true) === false)) {
            $pageError = 'Не удалось загрузить сделки. HTTP ' . $status . '.';
        }
    }
}

if ($pageError || (!$deals && $errors)) {
    http_response_code(502);
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сделки</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h2 mb-0">Сделки</h1>
        <a href="./" class="btn btn-outline-primary">Обновить</a>
    </div>

    <?php if ($pageError): ?>
        <div class="alert alert-danger" role="alert"><?= escape($pageError) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert <?= $deals ? 'alert-warning' : 'alert-danger' ?>" role="alert">
            <p class="mb-2"><?= $deals ? 'Часть сделок не загрузилась.' : 'Не удалось загрузить сделки.' ?></p>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li>
                        <?php if (is_array($error)): ?>
                            <?= isset($error['id']) ? 'Сделка ' . escape($error['id']) . ': ' : '' ?>
                            <?= escape($error['message'] ?? $error['error'] ?? 'Ошибка загрузки') ?>
                        <?php else: ?>
                            <?= escape($error) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$pageError): ?>
        <p class="text-secondary">Загружено сделок: <?= count($deals) ?></p>
    <?php endif; ?>

    <div class="table-responsive bg-white border rounded">
        <table class="table table-hover align-middle mb-0">
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
            <?php // Пустое поле в одной сделке не мешает показать остальные строки. ?>
            <?php foreach ($deals as $deal): ?>
                <tr>
                    <td><?= escape($deal['id'] ?? '') ?></td>
                    <td><?= escape($deal['title'] ?? '') ?></td>
                    <td class="text-end text-nowrap"><?= isset($deal['amount']) && is_numeric($deal['amount']) ? number_format($deal['amount'], 2, ',', ' ') : '' ?></td>
                    <td><?= escape($deal['responsible']['name'] ?? '') ?></td>
                    <td><?= escape($deal['company']['name'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$deals): ?>
                <tr>
                    <td colspan="5" class="text-center text-secondary py-4">
                        <?= !$pageError && !$errors ? 'Сделок пока нет.' : 'Нет данных для отображения.' ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
