<?php
/**
 * Exemplo de chamada:
 * php produtos.php
 */

echo PHP_EOL . PHP_EOL . PHP_EOL;

$props = parse_ini_file(__DIR__ . '/curl.env');



//
//
//if (!isset($argv[1])) {
//    die('Tipo de relatório não informado.' . PHP_EOL);
//}
//$tipoRelatorio = $argv[1];
//
//if (!isset($argv[2])) {
//    die('Nenhum arquivo informado.' . PHP_EOL);
//}
//$arquivo = $argv[2];
//
//if (!isset($argv[3]) || !in_array($argv[3], array('DEV', 'HOM', 'PROD'))) {
//    die('Ambiente não informado (DEV,HOM,PROD).' . PHP_EOL);
//}
//$ambiente = $argv[3];
//
//if (!file_exists(__DIR__ . '/curl.env')) {
//    die('curl.env não definido' . PHP_EOL);
//}

$props = parse_ini_file(__DIR__ . '/curl.env');
$token = $props['apiToken_' . $ambiente];

$endpoint = $props['uploadRel_' . $ambiente . '_endpoint'];

$ch = curl_init();

if (isset($argv[4]) && $argv[4] === 'log') {
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
}
if (isset($props['uploadRel_' . $ambiente . '_CURLOPT_CAINFO'])) {
    curl_setopt($ch, CURLOPT_CAINFO, $props['uploadRel_' . $ambiente . '_CURLOPT_CAINFO']);
}
curl_setopt($ch, CURLOPT_URL, $endpoint);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'X-Authorization: Bearer ' . $token
));

$encoded = base64_encode(gzencode(file_get_contents($arquivo)));

//Create a POST array with the file in it
$postData = array(
    'file' => $encoded,
    'filename' => $arquivo,
    'tipoRelatorio' => $tipoRelatorio
);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);


echo 'Executando...' . PHP_EOL;

// Execute the request
$response = curl_exec($ch);

if (isset($argv[4]) && $argv[4] === 'log') {
    $curlInfo = curl_getinfo($ch);
    print_r($curlInfo);
}

if ($response) {
    print_r($response);
} else {
    echo 'Não enviado.';
}

echo PHP_EOL;


