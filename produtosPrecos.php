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


$fUltimoCsv = @fopen('produtosPrecos.csv', 'r');
$arrUltimoCsv = [];
if ($fUltimoCsv) {
    while (($data = fgetcsv($fUltimoCsv)) !== FALSE) {
        if ($data[0] === 'erp_codigo') continue;
        $arrUltimoCsv[$data[0]] = $data;
    }
    fclose($fUltimoCsv);
}


$sql = <<<EOT
select 
    codigo, 
    venda,
    custo, 
    (venda * (1 - desconto/100)) as venda_com_desconto,
    promocao, 
    e_commerce_venda 
from 
    produto 
order by codigo  
EOT;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($dbhost, $dbuser, $dbpw, $dbdatabase);

$cfile_produtoPrecos = fopen('produtosPrecos.csv', 'w');
$cfile_produtosPrecos_diff = fopen('produtosPrecos_diff.csv', 'w');

try {
    $mysqli->set_charset('utf8');

    $rs = $mysqli->query($sql);
    $gerouCabecalho = false;

    $campos = ['erp_codigo', 'preco_ecommerce', 'preco_tabela', 'preco_custo', 'preco_venda_com_desconto', 'preco_promocao'];
    fputcsv($cfile_produtoPrecos, $campos);
    fputcsv($cfile_produtosPrecos_diff, $campos);

    while ($t = $rs->fetch_assoc()) {

        $preco_tabela = (float)bcmul($t['venda'], 1, 2);
        $preco_custo = (float)bcmul($t['custo'], 1, 2);
        $preco_venda_com_desconto = (float)bcmul($t['venda_com_desconto'], 1, 2);
        $preco_promocao = (float)bcmul($t['promocao'], 1, 2);
        $preco_ecommerce = (float)bcmul($t['e_commerce_venda'], 1, 2);

        $r = [
            $t['codigo'],
            $preco_ecommerce,
            $preco_tabela,
            $preco_custo,
            $preco_venda_com_desconto,
            $preco_promocao,
        ];

        if (
            $preco_ecommerce !== ($arrUltimoCsv[$t['codigo']]['preco_ecommerce'] ?? -999999) ||
            $preco_tabela !== ($arrUltimoCsv[$t['codigo']]['preco_tabela'] ?? -999999) ||
            $preco_custo !== ($arrUltimoCsv[$t['codigo']]['preco_custo'] ?? -999999) ||
            $preco_venda_com_desconto !== ($arrUltimoCsv[$t['codigo']]['preco_venda_com_desconto'] ?? -999999) ||
            $preco_promocao !== ($arrUltimoCsv[$t['codigo']]['preco_promocao'] ?? -999999)) {
            fputcsv($cfile_produtosPrecos_diff, $r);
        }

        fputcsv($cfile_produtoPrecos, $r);
    }

    fclose($cfile_produtoPrecos);
    fclose($cfile_produtosPrecos_diff);


    $zip = new ZipArchive();
    $filename = "./produtosPrecos.zip";

    if ($zip->open($filename, ZipArchive::CREATE) !== TRUE) {
        exit("cannot open <$filename>\n");
    }

    $zip->addFile("produtosPrecos_diff.csv");
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

    $encoded = base64_encode(gzencode(file_get_contents('produtosPrecos.zip')));

    //Create a POST array with the file in it
    $postData = [
        'tipoArquivo' => 'est_produtos_precos_csv',
        'filename' => 'produtosPrecos.zip',
        'substitutivo' => false, // n??o pode ser, pois s?? envia o diff, ent??o pode passar batido algum
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
        print_r(curl_error($ch));
        echo 'N??o enviado.'; // CASO CAIA AQUI DIRETO DEPOIS DE PRINTAR O print_r($curlInfo), VERIFIQUE O CERTIFICADO
    }

    echo PHP_EOL;


} finally {
    $mysqli->close();
}

