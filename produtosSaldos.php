<?php

echo PHP_EOL . PHP_EOL . PHP_EOL;

$props = parse_ini_file(__DIR__ . '/curl.env');

$ambiente = $props['env'];
$log = $props['log'];

$token = $props['crosier.token.' . $ambiente];
$endpoint = $props['crosier.endpoint.' . $ambiente];

$dbhost = $props['db.host.' . $ambiente];
$dbdatabase = $props['db.database.' . $ambiente];
$dbuser = $props['db.user.' . $ambiente];
$dbpw = $props['db.pw.' . $ambiente];


$fUltimoCsv = @fopen('produtosSaldos.csv', 'r');
$arrUltimoCsv = [];
if ($fUltimoCsv) {
    while (($data = fgetcsv($fUltimoCsv)) !== FALSE) {
        if ($data[0] === 'erp_codigo') continue;
        $arrUltimoCsv[$data[0]] = $data[1];
    }
    fclose($fUltimoCsv);
}



$sql = <<<EOT
select 
    estoque.produto as erp_codigo,
    estoque.estoque as saldo 
from
    estoque, produto
where
    produto.codigo = estoque.produto and
    estoque.filial = 1
order by produto  
EOT;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($dbhost, $dbuser, $dbpw, $dbdatabase);

$cfile_produtoSaldos = fopen('produtosSaldos.csv', 'w');
$cfile_produtosSaldos_diff = fopen('produtosSaldos_diff.csv', 'w');

try {
    $mysqli->set_charset('utf8mb4');

    $rs = $mysqli->query($sql);
    $gerouCabecalho = false;

    while ($r = $rs->fetch_assoc()) {
        if (!$gerouCabecalho) {
            $campos = array_keys($r);
            fputcsv($cfile_produtoSaldos, $campos);
            fputcsv($cfile_produtosSaldos_diff, $campos);
            $gerouCabecalho = true;
        }
        if ($r['saldo'] !== ($arrUltimoCsv[$r['erp_codigo']] ?? -999999)) {
            fputcsv($cfile_produtosSaldos_diff, $r);
        }
        fputcsv($cfile_produtoSaldos, $r);
    }
    fclose($cfile_produtoSaldos);
    fclose($cfile_produtosSaldos_diff);


    $zip = new ZipArchive();
    $filename = "./produtosSaldos.zip";

    if ($zip->open($filename, ZipArchive::CREATE) !== TRUE) {
        exit("cannot open <$filename>\n");
    }

    $zip->addFile("produtosSaldos_diff.csv");
    $zip->close();


    $ch = curl_init();

    if ($log) {
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
    }
    if ($curloptCainfo = ($props['CURLOPT_CAINFO.' . $ambiente] ?? null)) {
        curl_setopt($ch, CURLOPT_CAINFO, $curloptCainfo);
    }
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Authorization: Bearer ' . $token]);

    $encoded = base64_encode(gzencode(file_get_contents('produtosSaldos.zip')));

    //Create a POST array with the file in it
    $postData = [
        'tipoArquivo' => 'est_produtos_saldos_csv',
        'filename' => 'produtosSaldos.zip',
        'substitutivo' => true, // salva lá como "ultimo.zip"
        'file' => $encoded,
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    echo 'Executando...' . PHP_EOL;

    // Execute the request
    $response = curl_exec($ch);

    if ($log) {
        $curlInfo = curl_getinfo($ch);
        print_r($curlInfo);
    }

    if ($response) {
        print_r($response);
    } else {
        echo 'Não enviado.'; // CASO CAIA AQUI DIRETO DEPOIS DE PRINTAR O print_r($curlInfo), VERIFIQUE O CERTIFICADO
    }

    echo PHP_EOL;


} finally {
    $mysqli->close();
}

