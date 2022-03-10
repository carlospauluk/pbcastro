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

$fUltimoCsv = @fopen('produtos.enviados.csv', 'r');
$arrJaEnviados = [];
if ($fUltimoCsv) {
    while (($data = fgetcsv($fUltimoCsv)) !== FALSE) {
        if ($data[0] === 'erp_codigo') continue; // pula a primeira linha
        $arrJaEnviados[$data[0]] = true; // aqui só para constar que já tem o código
    }
    fclose($fUltimoCsv);
}


$sql = <<<EOT
select 
    p.codigo as erp_codigo, 
    p.referencia as erp_referencia, 
    p.nome, 
    p.grupo as grupo_codigo, 
    g.descricao as grupo_nome, 
    p.sub_grupo as subgrupo_codigo, 
    sg.descricao as subgrupo_nome, 
    p.unidade, 
    p.ncm,
    p.custo as preco_custo, 
    p.venda as preco_tabela,
    f.codigo as fornecedor_codigo,
    f.razao_social as fornecedor_nome, 
    p.cadastro, 
    p.alteracao, 
    p.alteracao_preco
from
    produto p LEFT JOIN grupo_produto g ON p.grupo = g.codigo
     LEFT JOIN sub_grupo_produto sg ON p.sub_grupo = sg.codigo
     LEFT JOIN empresa f ON p.fabricante = f.codigo 
order by p.codigo
EOT;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($dbhost, $dbuser, $dbpw, $dbdatabase);


$cfile_aEnviar = fopen('produtos.enviar.csv', 'w');
$cfile_jaEnviados = fopen('produtos.enviados.csv', 'a');

try {
    $mysqli->set_charset('utf8');

    $rs = $mysqli->query($sql);
    $gerouCabecalho = false;

    $qtdeParaExportar = 0;
    while ($r = $rs->fetch_assoc()) {
        if ($arrJaEnviados[$r['erp_codigo']] ?? false) {
            continue; // pula os já exportados
        }
        if (!$gerouCabecalho) {
            $campos = array_keys($r);
            $campos[] = 'EAN';
            fputcsv($cfile_aEnviar, $campos);
            $gerouCabecalho = true;
        }
        $rEan = $mysqli->query('SELECT cod_barra FROM produto_cod_barra WHERE produto = ' . $r['erp_codigo'] . ' ORDER BY alteracao DESC LIMIT 1')->fetch_assoc();
        $r['ean'] = $rEan['cod_barra'] ?? '';
        $qtdeParaExportar++;
        fputcsv($cfile_aEnviar, $r);
        fputcsv($cfile_jaEnviados, $r);
    }
    fclose($cfile_aEnviar);
    fclose($cfile_jaEnviados);

    if ($qtdeParaExportar > 0) {

        $zip = new ZipArchive();
        $filename = "./produtos.zip";

        if ($zip->open($filename, ZipArchive::CREATE) !== TRUE) {
            exit("cannot open <$filename>\n");
        }

        $zip->addFile("produtos.enviar.csv");
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

        $encoded = base64_encode(gzencode(file_get_contents('produtos.zip')));

        //Create a POST array with the file in it
        $postData = [
            'tipoArquivo' => 'est_produtos_csv',
            'filename' => 'produtos.zip',
            'substitutivo' => false, // pois só envia os novos
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
            echo 'Não enviado.'; // CASO CAIA AQUI DIRETO DEPOIS DE PRINTAR O print_r($curlInfo), VERIFIQUE O CERTIFICADO
        }
    } else {
        echo 'Nada para exportar';
    }

    echo PHP_EOL;


} finally {
    $mysqli->close();
}

