<?php

/** BSC simulado para o teste de transporte (php -S). O primeiro segmento da URL é o cenário; o resto é a rota. */

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
[$scenario, $route] = array_pad(explode('/', ltrim($path, '/'), 2), 2, '');

$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$auth = $headers['authorization'] ?? '';

$count = function (string $key): int {
    $file = sys_get_temp_dir() . '/govbr-fake-' . preg_replace('/[^a-z0-9-]/i', '_', $key);
    $n = (int) @file_get_contents($file) + 1;
    file_put_contents($file, (string) $n);

    return $n;
};

header('Content-Type: application/json');

if ($route === 'token') {
    switch ($scenario) {
        case 'semtoken':
            header('Content-Type: text/html');
            echo '<html><body>login</body></html>';
            return;
        case 'tokenvazio':
            echo json_encode(['accessToken' => '']);
            return;
    }

    if (($headers['clientid'] ?? '') !== 'id-teste' || ($headers['clientsecret'] ?? '') !== 'segredo-teste') {
        http_response_code(401);
        echo json_encode(['message' => 'credencial inválida']);
        return;
    }

    echo json_encode(['accessToken' => 't' . $count("{$scenario}-token")]);
    return;
}

if ($route === 'api/avaliacao/completa') {
    $body = json_decode((string) file_get_contents('php://input'), true);

    switch ($scenario) {
        case 'ok':
            echo json_encode([
                'emailEnviado' => true,
                'protocolo' => 'P1',
                'recebido' => $body,
                'authorization' => $auth,
                'contentType' => $headers['content-type'] ?? '',
            ]);
            return;

        // t1 vale para um POST; no segundo uso está expirado
        case 'expira':
            if ($auth === 'Bearer t1' && $count("expira-uso-{$auth}") > 1) {
                http_response_code(401);
                echo json_encode(['message' => 'token expirado']);
                return;
            }

            echo json_encode(['emailEnviado' => true, 'protocolo' => 'P-' . substr($auth, 7)]);
            return;

        case 'credencial':
            http_response_code(401);
            echo json_encode(['message' => 'não autorizado']);
            return;

        case 'proxy503':
            http_response_code(503);
            header('Content-Type: text/plain');
            echo 'no healthy upstream';
            return;

        case 'recusa':
            http_response_code(400);
            echo json_encode([
                'status' => 'BAD_REQUEST',
                'message' => 'Parâmetro(s) de entrada inválido(s)',
                'subErrors' => [['message' => 'Favor preencher o campo linkBotao.']],
                'stackTrace' => [['classLoaderName' => 'app']],
            ]);
            return;

        case 'lento':
            sleep(3);
            echo json_encode(['emailEnviado' => true, 'protocolo' => 'tarde']);
            return;
    }
}

http_response_code(404);
echo json_encode(['message' => "rota desconhecida: {$path}"]);
