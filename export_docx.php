<?php
// export_docx.php — Gerador de Relatórios em DOCX (sem dependências externas)

session_start();

if (!isset($_GET['id']) && !isset($_GET['ano'])) {
    http_response_code(400);
    die('Parâmetros inválidos.');
}

$type   = $_GET['type'] ?? 'diario'; // 'diario' ou 'mensal'
$id     = $_GET['id'] ?? 0;
$ano    = (int)($_GET['ano'] ?? date('Y'));
$mes    = (int)($_GET['mes'] ?? date('n'));

// ─── Configuração da BD ────────────────────────────────────────
$host   = 'localhost';
$dbname = 'tecnosoft';
$user   = 'root';
$pass   = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die('Erro na ligação ao BD: ' . $e->getMessage());
}

// Função para criar DOCX (XML + ZIP)
function gerarDocx($titulo, $conteudo, $filename) {
    // Verificar se ZipArchive está disponível
    if (!class_exists('ZipArchive')) {
        // Fallback: Gerar HTML que pode ser aberto no Word
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . str_replace('.docx', '.html', $filename) . '"');
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($titulo) . '</title>
    <style>
        body { font-family: Calibri, Arial; margin: 40px; line-height: 1.6; }
        h1 { text-align: center; color: #1a1a2e; }
        h2 { color: #3b82f6; margin-top: 20px; border-bottom: 2px solid #e8eaed; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background-color: #3b82f6; color: white; padding: 10px; text-align: left; }
        td { border: 1px solid #ddd; padding: 8px; }
        tr:nth-child(even) { background-color: #f9fafb; }
        .total { font-weight: bold; background-color: #f0f0f0; }
        .footer { margin-top: 40px; font-size: 12px; color: #666; text-align: right; }
    </style>
</head>
<body>
    ' . $conteudo . '
    <div class="footer">Gerado em: ' . date('d/m/Y H:i:s') . '</div>
</body>
</html>';
        
        echo $html;
        exit;
    }
    
    // Se ZipArchive estiver disponível, usar a forma normal
    $zip = new ZipArchive();
    $tmpfile = sys_get_temp_dir() . '/' . uniqid() . '.zip';
    
    if ($zip->open($tmpfile, ZipArchive::CREATE) !== true) {
        die('Não foi possível criar o arquivo DOCX.');
    }
    
    // [Content_Types].xml
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>');
    
    // _rels/.rels
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>');
    
    // word/document.xml com conteúdo
    $zip->addFromString('word/document.xml', $conteudo);
    
    $zip->close();
    
    // Enviar arquivo
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Content-Length: ' . filesize($tmpfile));
    
    readfile($tmpfile);
    unlink($tmpfile);
    exit;
}

// Função para gerar XML do documento ou HTML
function gerarXmlDoc($titulo, $tabelas = [], $totais = []) {
    // Se ZipArchive não está disponível, gerar HTML
    if (!class_exists('ZipArchive')) {
        return gerarHtmlDoc($titulo, $tabelas, $totais);
    }
    
    // Caso contrário, gerar XML para DOCX
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<w:body>';
    
    // Cabeçalho
    $xml .= '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:sz w:val="36"/><w:b/></w:rPr><w:t>TECNOSOFT — Gestão de Stock</w:t></w:r></w:p>';
    $xml .= '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:sz w:val="28"/><w:b/></w:rPr><w:t>' . htmlspecialchars($titulo) . '</w:t></w:r></w:p>';
    
    // Tabelas
    foreach ($tabelas as $tabela) {
        $xml .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr>';
        
        // Header
        $xml .= '<w:tr>';
        foreach ($tabela['headers'] as $h) {
            $xml .= '<w:tc><w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="FFFFFF"/></w:rPr><w:t>' . htmlspecialchars($h) . '</w:t></w:r></w:p></w:tc>';
        }
        $xml .= '</w:tr>';
        
        // Rows
        foreach ($tabela['rows'] as $row) {
            $xml .= '<w:tr>';
            foreach ($row as $cell) {
                $xml .= '<w:tc><w:p><w:r><w:t>' . htmlspecialchars((string)$cell) . '</w:t></w:r></w:p></w:tc>';
            }
            $xml .= '</w:tr>';
        }
        
        $xml .= '</w:tbl>';
        $xml .= '<w:p><w:br/></w:p>';
    }
    
    // Totais
    if (!empty($totais)) {
        $xml .= '<w:p><w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:t>TOTAIS</w:t></w:r></w:p>';
        foreach ($totais as $label => $valor) {
            $xml .= '<w:p><w:r><w:t>' . htmlspecialchars($label) . ': ' . htmlspecialchars($valor) . '</w:t></w:r></w:p>';
        }
    }
    
    // Rodapé
    $xml .= '<w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:rPr><w:i/><w:sz w:val="20"/></w:rPr><w:t>Gerado em: ' . date('d/m/Y H:i:s') . '</w:t></w:r></w:p>';
    
    $xml .= '</w:body></w:document>';
    return $xml;
}

// Função para gerar HTML (fallback)
function gerarHtmlDoc($titulo, $tabelas = [], $totais = []) {
    $html = '<h1>' . htmlspecialchars($titulo) . '</h1>';
    
    // Tabelas
    foreach ($tabelas as $tabela) {
        $html .= '<table><tr>';
        foreach ($tabela['headers'] as $h) {
            $html .= '<th>' . htmlspecialchars($h) . '</th>';
        }
        $html .= '</tr>';
        
        foreach ($tabela['rows'] as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars((string)$cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    // Totais
    if (!empty($totais)) {
        $html .= '<h2>TOTAIS</h2>';
        foreach ($totais as $label => $valor) {
            $html .= '<p><strong>' . htmlspecialchars($label) . ':</strong> ' . htmlspecialchars($valor) . '</p>';
        }
    }
    
    return $html;
}

// ─── RELATÓRIO DIÁRIO ────────────────────────────────────────
if ($type === 'diario') {
    $stmt = $pdo->prepare("SELECT * FROM relatorios_diarios WHERE id = ?");
    $stmt->execute([$id]);
    $rel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rel) {
        http_response_code(404);
        die('Relatório não encontrado.');
    }
    
    $tabelas = [];
    
    // Resumo
    $tabelas[] = [
        'headers' => ['Métrica', 'Valor'],
        'rows' => [
            ['Total de Entradas', $rel['total_entradas'] . ' unidades'],
            ['Total de Saídas', $rel['total_saidas'] . ' unidades'],
            ['Total de Vendas', $rel['total_vendas'] . ' transações'],
            ['Valor Total', number_format($rel['valor_total_vendas'], 2, ',', '.') . ' MT']
        ]
    ];
    
    $totais = [
        'Entradas' => $rel['total_entradas'],
        'Saídas' => $rel['total_saidas'],
        'Vendas' => $rel['total_vendas'],
        'Valor Total' => number_format($rel['valor_total_vendas'], 2, ',', '.') . ' MT'
    ];
    
    $titulo = 'Relatório Diário — ' . date('d/m/Y', strtotime($rel['data_relatorio']));
    $xml = gerarXmlDoc($titulo, $tabelas, $totais);
    $filename = 'Relatorio_Diario_' . date('Ymd', strtotime($rel['data_relatorio'])) . '.docx';
    gerarDocx($titulo, $xml, $filename);
    
// ─── RELATÓRIO MENSAL ────────────────────────────────────────
} elseif ($type === 'mensal') {
    $stmt = $pdo->prepare("SELECT * FROM relatorios_diarios WHERE YEAR(data_relatorio)=? AND MONTH(data_relatorio)=? ORDER BY data_relatorio");
    $stmt->execute([$ano, $mes]);
    $rel_diarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rel_diarios)) {
        http_response_code(404);
        die('Nenhum dado para este período.');
    }
    
    $rows = [];
    $tot_e = $tot_s = $tot_v = $tot_val = 0;
    
    foreach ($rel_diarios as $r) {
        $tot_e += $r['total_entradas'];
        $tot_s += $r['total_saidas'];
        $tot_v += $r['total_vendas'];
        $tot_val += $r['valor_total_vendas'];
        
        $ticket = $r['total_vendas'] > 0 ? $r['valor_total_vendas'] / $r['total_vendas'] : 0;
        $rows[] = [
            date('d/m/Y', strtotime($r['data_relatorio'])),
            $r['total_entradas'],
            $r['total_saidas'],
            $r['total_vendas'],
            number_format($r['valor_total_vendas'], 2, ',', '.'),
            number_format($ticket, 2, ',', '.')
        ];
    }
    
    // Adicionar linha de totais
    $rows[] = [
        'TOTAL',
        $tot_e,
        $tot_s,
        $tot_v,
        number_format($tot_val, 2, ',', '.'),
        number_format($tot_v > 0 ? $tot_val / $tot_v : 0, 2, ',', '.')
    ];
    
    $tabelas = [[
        'headers' => ['Data', 'Entradas', 'Saídas', 'Vendas', 'Valor', 'Ticket Médio'],
        'rows' => $rows
    ]];
    
    $totais = [
        'Período' => str_pad($mes, 2, '0', STR_PAD_LEFT) . '/' . $ano,
        'Total Entradas' => $tot_e . ' un.',
        'Total Saídas' => $tot_s . ' un.',
        'Total Vendas' => $tot_v,
        'Valor Total' => number_format($tot_val, 2, ',', '.') . ' MT',
        'Ticket Médio' => number_format($tot_v > 0 ? $tot_val / $tot_v : 0, 2, ',', '.') . ' MT'
    ];
    
    $meses_pt = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    $titulo = 'Relatório Mensal — ' . $meses_pt[$mes] . ' ' . $ano;
    $xml = gerarXmlDoc($titulo, $tabelas, $totais);
    $filename = 'Relatorio_Mensal_' . str_pad($mes, 2, '0', STR_PAD_LEFT) . '_' . $ano . '.docx';
    gerarDocx($titulo, $xml, $filename);
    
} else {
    http_response_code(400);
    die('Tipo inválido.');
}

