<?php
// ─── Configuração da Ligação ───────────────────────────────────────────────
$host   = 'localhost';
$dbname = 'tecnosoft';
$user   = 'root';
$pass   = '';

session_start();

$pdo = null;
$error_conn = null;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $error_conn = $e->getMessage();
}

// ─── Segurança CSRF ───────────────────────────────────────────────────────
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}
function verify_csrf_token(?string $token): bool {
    $expected = $_SESSION['csrf_token'] ?? '';
    return !empty($token) && !empty($expected) && hash_equals($expected, $token);
}

// ─── Funções de Validação ─────────────────────────────────────────────────
function sanitize_text(string $value): string { return trim($value); }
function validate_int($value, int $min = 1): int {
    $r = filter_var($value, FILTER_VALIDATE_INT);
    if ($r === false || $r < $min) throw new InvalidArgumentException('Valor inteiro inválido.');
    return $r;
}
function validate_decimal($value): float {
    if (!is_numeric($value)) throw new InvalidArgumentException('Valor numérico inválido.');
    return (float)$value;
}

$csrf_token = generate_csrf_token();
$action     = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['action'] ?? '') : '';
$msg        = '';
$msg_type   = 'success';

// ─── Lógica das Acções ────────────────────────────────────────────────────
if ($pdo && $action) {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? ''))
            throw new RuntimeException('Token CSRF inválido.');

        switch ($action) {

            // ── CATEGORIAS ──────────────────────────────────────────────
            case 'add_categoria':
                $nome = sanitize_text($_POST['nome'] ?? '');
                $desc = sanitize_text($_POST['descricao'] ?? '');
                if ($nome === '') throw new InvalidArgumentException('Nome da categoria obrigatório.');
                
                $check = $pdo->prepare("SELECT id FROM categorias WHERE nome = ?");
                $check->execute([$nome]);
                if ($check->fetch()) {
                    throw new InvalidArgumentException('Uma categoria com este nome já existe.');
                }
                
                $pdo->prepare("INSERT INTO categorias (nome, descricao) VALUES (?, ?)")->execute([$nome, $desc]);
                $msg = "Categoria adicionada! (ID: {$pdo->lastInsertId()})";
                break;

            case 'delete_categoria':
                $id = validate_int($_POST['id'] ?? '');
                $pdo->prepare("DELETE FROM categorias WHERE id = ?")->execute([$id]);
                $msg = "Categoria eliminada.";
                break;

            // ── MARCAS ───────────────────────────────────────────────────
            case 'add_marca':
                $nome = sanitize_text($_POST['nome'] ?? '');
                if ($nome === '') throw new InvalidArgumentException('Nome da marca obrigatório.');
                
                $check = $pdo->prepare("SELECT id FROM marcas WHERE nome = ?");
                $check->execute([$nome]);
                if ($check->fetch()) {
                    throw new InvalidArgumentException('Uma marca com este nome já existe.');
                }
                
                $pdo->prepare("INSERT INTO marcas (nome) VALUES (?)")->execute([$nome]);
                $msg = "Marca adicionada! (ID: {$pdo->lastInsertId()})";
                break;

            case 'delete_marca':
                $id = validate_int($_POST['id'] ?? '');
                $pdo->prepare("DELETE FROM marcas WHERE id = ?")->execute([$id]);
                $msg = "Marca eliminada.";
                break;

            // ── PRODUTOS ─────────────────────────────────────────────────
            case 'add_produto':
                $nome     = sanitize_text($_POST['nome'] ?? '');
                $tipo     = strtoupper(sanitize_text($_POST['tipo'] ?? ''));
                $cat_id   = validate_int($_POST['categoria_id'] ?? '');
                $marca_id = (isset($_POST['marca_id']) && $_POST['marca_id'] !== '')
                            ? validate_int($_POST['marca_id']) : null;
                $serie    = sanitize_text($_POST['numero_serie'] ?? '') ?: null;
                $preco    = validate_decimal($_POST['preco_unitario'] ?? '0');

                if ($nome === '' || !in_array($tipo, ['INFORMATICO','ACADEMICO','REDES'], true))
                    throw new InvalidArgumentException('Dados do produto inválidos.');

                $pdo->prepare("INSERT INTO produtos (nome, categoria_id, marca_id, numero_serie, tipo, preco_unitario) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$nome, $cat_id, $marca_id, $serie, $tipo, $preco]);
                $msg = "Produto adicionado! (ID: {$pdo->lastInsertId()})";
                break;

            case 'delete_produto':
                $id = validate_int($_POST['id'] ?? '');
                $pdo->prepare("DELETE FROM produtos WHERE id = ?")->execute([$id]);
                $msg = "Produto eliminado.";
                break;

            // ── ENTRADA DE ESTOQUE ───────────────────────────────────────
            case 'add_entrada':
                $produto_id = validate_int($_POST['produto_id'] ?? '');
                $quantidade = validate_int($_POST['quantidade'] ?? '');

                $pdo->beginTransaction();
                // Trigger trg_entrada_valor_total calcula valor_total automaticamente
                $pdo->prepare("INSERT INTO entrada_estoques (produto_id, quantidade) VALUES (?, ?)")
                    ->execute([$produto_id, $quantidade]);
                $entrada_id = $pdo->lastInsertId();

                $p = $pdo->prepare("SELECT tipo FROM produtos WHERE id = ?");
                $p->execute([$produto_id]);
                $tipo = strtolower((string)$p->fetchColumn());
                if ($tipo === '') throw new RuntimeException('Produto não encontrado.');

                $cols = ['informatico'=>0, 'academico'=>0, 'redes'=>0];
                if (isset($cols[$tipo])) $cols[$tipo] = $quantidade;

                $pdo->prepare("INSERT INTO estoques (entrada_id, informatico, academico, redes) VALUES (?, ?, ?, ?)")
                    ->execute([$entrada_id, $cols['informatico'], $cols['academico'], $cols['redes']]);

                $pdo->prepare("CALL AtualizarRelatorioDiario(?)")->execute([date('Y-m-d')]);
                $pdo->commit();

                $st = $pdo->prepare("SELECT COALESCE(SUM(ee.quantidade),0) - COALESCE((SELECT SUM(s.quantidade) FROM saida_estoques s WHERE s.produto_id=?),0) FROM entrada_estoques ee WHERE ee.produto_id=?");
                $st->execute([$produto_id, $produto_id]);
                $novo_stock = (int)$st->fetchColumn();
                $msg = "Entrada registada! (ID: $entrada_id) — Stock actual: $novo_stock un.";
                break;

            // ── SAÍDA / VENDA ────────────────────────────────────────────
            case 'add_saida':
                $produto_id = validate_int($_POST['produto_id'] ?? '');
                $quantidade = validate_int($_POST['quantidade'] ?? '');
                $descricao  = sanitize_text($_POST['descricao'] ?? '');

                $st = $pdo->prepare("SELECT COALESCE(SUM(ee.quantidade),0) - COALESCE((SELECT SUM(s.quantidade) FROM saida_estoques s WHERE s.produto_id=?),0) FROM entrada_estoques ee WHERE ee.produto_id=?");
                $st->execute([$produto_id, $produto_id]);
                $stock_atual = (int)$st->fetchColumn();
                if ($stock_atual < $quantidade)
                    throw new InvalidArgumentException("Stock insuficiente. Disponível: $stock_atual un.");

                $pdo->beginTransaction();
                // Trigger trg_saida_valor_total calcula valor automaticamente
                $pdo->prepare("INSERT INTO saida_estoques (produto_id, quantidade) VALUES (?, ?)")
                    ->execute([$produto_id, $quantidade]);
                $saida_id = $pdo->lastInsertId();

                // Trigger trg_venda_valores preenche valor_unitario e valor_total
                $pdo->prepare("INSERT INTO vendas (produto_id, quantidade, descricao, saida_id) VALUES (?, ?, ?, ?)")
                    ->execute([$produto_id, $quantidade, $descricao, $saida_id]);

                $pdo->prepare("CALL AtualizarRelatorioDiario(?)")->execute([date('Y-m-d')]);
                $pdo->commit();

                $msg = "Saída/Venda registada! (Saída ID: $saida_id) — Stock restante: " . ($stock_atual - $quantidade) . " un.";
                break;

            // ── RELATÓRIOS ───────────────────────────────────────────────
            case 'gerar_relatorio':
                $pdo->prepare("CALL AtualizarRelatorioDiario(?)")->execute([date('Y-m-d')]);
                $msg = "Relatório de hoje gerado/actualizado!";
                break;

            case 'relatorio_mensal':
                $ano = validate_int($_POST['ano'] ?? date('Y'), 2000);
                $mes = validate_int($_POST['mes'] ?? date('n'), 1);
                $_SESSION['rel_ano'] = $ano;
                $_SESSION['rel_mes'] = $mes;
                $msg = "Relatório mensal carregado para " . str_pad($mes,2,'0',STR_PAD_LEFT) . "/$ano.";
                break;

            case 'delete_relatorio':
                $id = validate_int($_POST['id'] ?? '');
                $pdo->prepare("DELETE FROM relatorios_diarios WHERE id = ?")->execute([$id]);
                $msg = "Relatório eliminado.";
                break;
        }
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        $msg      = "Erro: " . $e->getMessage();
        $msg_type = 'error';
    }
}

// ─── Leitura dos Dados ────────────────────────────────────────────────────
$categorias = $pdo ? $pdo->query("SELECT * FROM categorias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC) : [];
$marcas     = $pdo ? $pdo->query("SELECT * FROM marcas ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC) : [];

$produtos = $pdo ? $pdo->query("
    SELECT p.*, c.nome AS categoria_nome, m.nome AS marca_nome
    FROM produtos p
    LEFT JOIN categorias c ON c.id = p.categoria_id
    LEFT JOIN marcas m ON m.id = p.marca_id
    ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC) : [];

$entradas = $pdo ? $pdo->query("
    SELECT ee.*, p.nome, p.tipo, p.preco_unitario
    FROM entrada_estoques ee
    JOIN produtos p ON p.id = ee.produto_id
    ORDER BY ee.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) : [];

$saidas = $pdo ? $pdo->query("
    SELECT se.*, p.nome, p.preco_unitario
    FROM saida_estoques se
    JOIN produtos p ON p.id = se.produto_id
    ORDER BY se.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) : [];

$vendas = $pdo ? $pdo->query("
    SELECT v.*, p.nome
    FROM vendas v
    JOIN produtos p ON p.id = v.produto_id
    ORDER BY v.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) : [];

$estoques = $pdo ? $pdo->query("
    SELECT e.*, ee.hora_entrada, p.nome, p.tipo, c.nome AS categoria_nome, m.nome AS marca_nome
    FROM estoques e
    JOIN entrada_estoques ee ON ee.id = e.entrada_id
    JOIN produtos p ON p.id = ee.produto_id
    LEFT JOIN categorias c ON c.id = p.categoria_id
    LEFT JOIN marcas m ON m.id = p.marca_id
    ORDER BY e.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) : [];

$relatorios = $pdo ? $pdo->query("SELECT * FROM relatorios_diarios ORDER BY data_relatorio DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC) : [];

// Views
$vw_estoque = [];
$vw_vendas  = [];
if ($pdo) {
    try { $vw_estoque = $pdo->query("SELECT * FROM vw_estoque_atual")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try { $vw_vendas  = $pdo->query("SELECT * FROM vw_vendas_resumo ORDER BY dia DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}

// Relatório mensal
$rel_mensal = [];
$rel_ano = (int)($_SESSION['rel_ano'] ?? date('Y'));
$rel_mes = (int)($_SESSION['rel_mes'] ?? date('n'));
if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM relatorios_diarios WHERE YEAR(data_relatorio)=? AND MONTH(data_relatorio)=? ORDER BY data_relatorio");
        $stmt->execute([$rel_ano, $rel_mes]);
        $rel_mensal = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// KPIs
$total_produtos = count($produtos);
$entradas_hoje  = $pdo ? (int)$pdo->query("SELECT COALESCE(SUM(quantidade),0) FROM entrada_estoques WHERE DATE(hora_entrada)=CURDATE()")->fetchColumn() : 0;
$saidas_hoje    = $pdo ? (int)$pdo->query("SELECT COALESCE(SUM(quantidade),0) FROM saida_estoques WHERE DATE(hora_saida)=CURDATE()")->fetchColumn() : 0;
$vendas_hoje    = $pdo ? (float)$pdo->query("SELECT COALESCE(SUM(valor_total),0) FROM vendas WHERE DATE(data_venda)=CURDATE()")->fetchColumn() : 0;
$vendas_mes     = $pdo ? (float)$pdo->query("SELECT COALESCE(SUM(valor_total),0) FROM vendas WHERE MONTH(data_venda)=MONTH(CURDATE()) AND YEAR(data_venda)=YEAR(CURDATE())")->fetchColumn() : 0;

// Stock summary com joins completos
$stock_summary = [];
$valor_total_stock = 0;
if ($pdo) {
    try {
        $stock_summary = $pdo->query("
            SELECT p.id, p.nome, p.tipo, p.preco_unitario, p.numero_serie,
                   c.nome AS categoria_nome, m.nome AS marca_nome,
                   COALESCE(SUM(ee.quantidade),0) AS total_entrada,
                   COALESCE((SELECT SUM(s.quantidade) FROM saida_estoques s WHERE s.produto_id=p.id),0) AS total_saida,
                   COALESCE(SUM(ee.quantidade),0) - COALESCE((SELECT SUM(s.quantidade) FROM saida_estoques s WHERE s.produto_id=p.id),0) AS stock_actual
            FROM produtos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN marcas m ON m.id = p.marca_id
            LEFT JOIN entrada_estoques ee ON ee.produto_id = p.id
            GROUP BY p.id, p.nome, p.tipo, p.preco_unitario, p.numero_serie, c.nome, m.nome
            ORDER BY p.tipo, p.nome")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stock_summary as $item)
            $valor_total_stock += max(0, (int)$item['stock_actual']) * (float)$item['preco_unitario'];
    } catch (Throwable $e) {}
}

// Distribuição por categoria
$por_categoria = $pdo ? $pdo->query("
    SELECT c.nome, COUNT(p.id) AS qtd, COALESCE(AVG(p.preco_unitario),0) AS preco_medio
    FROM categorias c LEFT JOIN produtos p ON p.categoria_id=c.id
    GROUP BY c.id ORDER BY qtd DESC")->fetchAll(PDO::FETCH_ASSOC) : [];

$tipo_badge = ['INFORMATICO'=>'badge-blue','ACADEMICO'=>'badge-green','REDES'=>'badge-amber'];
$meses_pt = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TecnoSoft — Gestão de Stock</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f4f5f7;color:#1a1a2e;font-size:15px}

.sidebar{position:fixed;top:0;left:0;width:230px;height:100vh;background:#1a1a2e;display:flex;flex-direction:column;z-index:100;overflow-y:auto}
.sidebar-logo{padding:24px 20px 14px;border-bottom:1px solid rgba(255,255,255,.08)}
.sidebar-logo h1{font-size:17px;font-weight:700;color:#fff}
.sidebar-logo p{font-size:11px;color:rgba(255,255,255,.35);margin-top:3px}
.nav{padding:12px 10px;flex:1}
.nav-group{font-size:10px;font-weight:600;color:rgba(255,255,255,.28);letter-spacing:1px;text-transform:uppercase;padding:12px 10px 4px}
.nav-item{display:flex;align-items:center;gap:9px;padding:8px 11px;border-radius:7px;cursor:pointer;color:rgba(255,255,255,.6);font-size:13px;font-weight:500;transition:background .15s,color .15s;margin-bottom:1px}
.nav-item:hover{background:rgba(255,255,255,.07);color:#fff}
.nav-item.active{background:rgba(99,179,237,.18);color:#63b3ed}

.main{margin-left:230px;min-height:100vh;padding:26px 30px}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
.topbar h2{font-size:19px;font-weight:700}
.status-dot{width:7px;height:7px;border-radius:50%;background:#48bb78;display:inline-block;margin-right:5px}
.status-txt{font-size:13px;color:#777}

.cards-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.kcard{background:#fff;border-radius:11px;padding:16px 18px;border:1px solid #e8eaed}
.kcard-label{font-size:11px;color:#aaa;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.kcard-value{font-size:24px;font-weight:700;color:#1a1a2e;line-height:1.1}
.kcard-sub{font-size:12px;color:#ccc;margin-top:4px}
.kcard.t-blue{border-top:3px solid #3b82f6}
.kcard.t-green{border-top:3px solid #22c55e}
.kcard.t-amber{border-top:3px solid #f59e0b}
.kcard.t-purple{border-top:3px solid #8b5cf6}

.section{background:#fff;border-radius:11px;border:1px solid #e8eaed;margin-bottom:20px;overflow:hidden}
.section-head{padding:13px 18px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px}
.section-head h3{font-size:14px;font-weight:600}
.section-body{padding:18px}

.form-row{display:grid;gap:11px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:12px}
.form-group{display:flex;flex-direction:column;gap:4px}
label{font-size:11px;font-weight:600;color:#777;text-transform:uppercase;letter-spacing:.4px}
input,select,textarea{border:1px solid #dde1e7;border-radius:6px;padding:7px 10px;font-size:14px;color:#1a1a2e;background:#fafbfc;transition:border .15s;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:#3b82f6;background:#fff}
textarea{resize:vertical;min-height:52px}
.form-hint{font-size:11px;color:#bbb}

.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 15px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:background .15s,transform .1s;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-primary{background:#3b82f6;color:#fff}.btn-primary:hover{background:#2563eb}
.btn-success{background:#22c55e;color:#fff}.btn-success:hover{background:#16a34a}
.btn-danger{background:#ef4444;color:#fff;padding:4px 9px;font-size:12px}.btn-danger:hover{background:#dc2626}
.btn-info{background:#8b5cf6;color:#fff}.btn-info:hover{background:#7c3aed}
.btn-amber{background:#f59e0b;color:#fff}.btn-amber:hover{background:#d97706}
.btn-outline{background:transparent;color:#3b82f6;border:1px solid #3b82f6}.btn-outline:hover{background:#eff6ff}

.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead tr{border-bottom:2px solid #f0f0f0}
th{font-size:11px;font-weight:600;color:#aaa;text-transform:uppercase;letter-spacing:.4px;padding:8px 10px;text-align:left;white-space:nowrap}
td{padding:8px 10px;border-bottom:1px solid #f8f9fa;color:#333;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafbfc}
tfoot td{border-top:2px solid #e8eaed;background:#f8f9fa;font-weight:600}

.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.badge-green{background:#dcfce7;color:#15803d}
.badge-amber{background:#fef3c7;color:#92400e}
.badge-gray{background:#f1f5f9;color:#475569}
.badge-purple{background:#ede9fe;color:#5b21b6}

.alert{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-size:14px;font-weight:500}
.alert-success{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.alert-conn-error{background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:20px;border-radius:11px}

.s-ok{color:#22c55e;font-weight:700}
.s-low{color:#f59e0b;font-weight:700}
.s-zero{color:#ef4444;font-weight:700}
.price{font-family:'Courier New',monospace;font-weight:600;white-space:nowrap}
.price-blue{color:#3b82f6}
.serie-pill{font-family:'Courier New',monospace;font-size:11px;background:#f1f5f9;color:#475569;padding:2px 7px;border-radius:4px;display:inline-block}

.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.empty-state{text-align:center;padding:32px;color:#ccc;font-size:14px}
code{background:#f1f5f9;padding:1px 6px;border-radius:4px;font-size:12px;color:#475569}

@media(max-width:900px){
  .sidebar{width:100%;height:auto;position:relative}
  .main{margin-left:0;padding:14px}
  .cards-grid,.grid-2{grid-template-columns:1fr 1fr}
}
@media(max-width:560px){
  .cards-grid,.grid-2{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <h1>⚙ TecnoSoft</h1>
    <p>Gestão de Stock v3.0</p>
  </div>
  <nav class="nav">
    <div class="nav-group">Principal</div>
    <div class="nav-item active" onclick="showTab('dashboard',this)">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Dashboard
    </div>
    <div class="nav-group">Catálogo</div>
    <div class="nav-item" onclick="showTab('categorias',this)">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      Categorias & Marcas
    </div>
    <div class="nav-item" onclick="showTab('produtos',this)">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
      Produtos
    </div>
    <div class="nav-group">Movimentos</div>
    <div class="nav-item" onclick="showTab('entradas',this)">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
      Entradas de Stock
    </div>
    <div class="nav-item" onclick="showTab('saidas',this)">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
      Saídas / Vendas
    </div>
    <div class="nav-group">Relatórios & Views</div>
    <div class="nav-item" onclick="showTab('estoques',this)">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
      Tabela Estoques
    </div>
    <div class="nav-item" onclick="showTab('views',this)">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      Views do BD
    </div>
    <div class="nav-item" onclick="showTab('relatorios',this)">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Relatórios Diários
    </div>
  </nav>
</aside>

<!-- MAIN -->
<main class="main">

<?php if ($error_conn): ?>
<div class="alert alert-conn-error">
  <strong>⚠ Falha na ligação ao banco de dados</strong><br>
  <small><?= htmlspecialchars($error_conn) ?></small><br><br>
  Verifica <code>$host</code>, <code>$user</code>, <code>$pass</code>, <code>$dbname</code> no topo do ficheiro.<br>
  Certifica-te também que executaste o script SQL completo (tabelas, triggers e procedures).
</div>
<?php else: ?>

<div class="topbar">
  <h2 id="page-title">Dashboard</h2>
  <span><span class="status-dot"></span><span class="status-txt">Ligado a <strong><?= $dbname ?></strong> @ <?= $host ?></span></span>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════ DASHBOARD -->
<div id="tab-dashboard">
  <div class="cards-grid">
    <div class="kcard t-blue">
      <div class="kcard-label">Total Produtos</div>
      <div class="kcard-value"><?= $total_produtos ?></div>
      <div class="kcard-sub"><?= count($categorias) ?> cat. · <?= count($marcas) ?> marcas</div>
    </div>
    <div class="kcard t-green">
      <div class="kcard-label">Entradas Hoje</div>
      <div class="kcard-value"><?= $entradas_hoje ?></div>
      <div class="kcard-sub">unidades recebidas</div>
    </div>
    <div class="kcard t-amber">
      <div class="kcard-label">Vendas Hoje</div>
      <div class="kcard-value price"><?= number_format($vendas_hoje,2,',','.') ?></div>
      <div class="kcard-sub"><?= $saidas_hoje ?> un. saídas · MT</div>
    </div>
    <div class="kcard t-purple">
      <div class="kcard-label">Vendas do Mês</div>
      <div class="kcard-value price"><?= number_format($vendas_mes,2,',','.') ?></div>
      <div class="kcard-sub">acumulado · MT</div>
    </div>
  </div>

  <div class="section">
    <div class="section-head">
      <h3>Stock Actual por Produto</h3>
      <span style="font-size:13px;color:#888">Valor total: <strong class="price price-blue"><?= number_format($valor_total_stock,2,',','.') ?> MT</strong></span>
    </div>
    <div class="section-body">
      <?php if (empty($stock_summary)): ?>
        <div class="empty-state">Nenhum produto cadastrado.</div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>Produto</th><th>Tipo</th><th>Categoria</th><th>Marca</th><th>Nº Série</th>
            <th>Preço Unit.</th><th>Entradas</th><th>Saídas</th><th>Stock</th><th>Valor em Stock</th>
          </tr></thead>
          <tbody>
          <?php foreach ($stock_summary as $r):
            $s = (int)$r['stock_actual'];
            $scls = $s <= 0 ? 's-zero' : ($s < 5 ? 's-low' : 's-ok');
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['nome']) ?></strong></td>
            <td><span class="badge <?= $tipo_badge[$r['tipo']] ?? 'badge-gray' ?>"><?= htmlspecialchars($r['tipo'] ?? 'DESCONHECIDO') ?></span></td>
            <td><?= htmlspecialchars($r['categoria_nome'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['marca_nome'] ?? '—') ?></td>
            <td><?= $r['numero_serie'] ? '<span class="serie-pill">'.htmlspecialchars($r['numero_serie']).'</span>' : '<span style="color:#ddd">—</span>' ?></td>
            <td class="price"><?= number_format($r['preco_unitario'],2,',','.') ?> MT</td>
            <td><?= $r['total_entrada'] ?></td>
            <td><?= $r['total_saida'] ?></td>
            <td><span class="<?= $scls ?>"><?= $s ?> un.</span></td>
            <td class="price price-blue"><?= number_format(max(0,$s)*$r['preco_unitario'],2,',','.') ?> MT</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($por_categoria)): ?>
  <div class="section">
    <div class="section-head"><h3>Produtos por Categoria</h3></div>
    <div class="section-body">
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Categoria</th><th>Nº Produtos</th><th>Preço Médio</th></tr></thead>
          <tbody>
          <?php foreach ($por_categoria as $c): ?>
          <tr>
            <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
            <td><?= $c['qtd'] ?></td>
            <td class="price"><?= number_format($c['preco_medio'],2,',','.') ?> MT</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════ CATEGORIAS & MARCAS -->
<div id="tab-categorias" style="display:none">
  <div class="grid-2">
    <div class="section">
      <div class="section-head"><h3>Nova Categoria</h3></div>
      <div class="section-body">
        <form method="POST">
          <input type="hidden" name="action" value="add_categoria">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <div class="form-group" style="margin-bottom:10px">
            <label>Nome</label>
            <input type="text" name="nome" placeholder="Ex: Periféricos" required maxlength="50">
          </div>
          <div class="form-group" style="margin-bottom:12px">
            <label>Descrição (opcional)</label>
            <textarea name="descricao" placeholder="Ex: Mouse, teclado, etc."></textarea>
          </div>
          <button type="submit" class="btn btn-primary">+ Adicionar Categoria</button>
        </form>
      </div>
    </div>
    <div class="section">
      <div class="section-head"><h3>Nova Marca</h3></div>
      <div class="section-body">
        <form method="POST">
          <input type="hidden" name="action" value="add_marca">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <div class="form-group" style="margin-bottom:12px">
            <label>Nome da Marca</label>
            <input type="text" name="nome" placeholder="Ex: Logitech" required maxlength="100">
          </div>
          <button type="submit" class="btn btn-info">+ Adicionar Marca</button>
        </form>
      </div>
    </div>
  </div>

  <div class="grid-2">
    <div class="section">
      <div class="section-head"><h3>Categorias (<?= count($categorias) ?>)</h3></div>
      <div class="section-body">
        <?php if (empty($categorias)): ?>
          <div class="empty-state">Nenhuma categoria.</div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>ID</th><th>Nome</th><th>Descrição</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($categorias as $c): ?>
            <tr>
              <td><small style="color:#ccc"><?= $c['id'] ?></small></td>
              <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
              <td><small style="color:#999"><?= htmlspecialchars($c['descricao'] ?? '—') ?></small></td>
              <td>
                <form method="POST" onsubmit="return confirm('Eliminar categoria?')" style="display:inline">
                  <input type="hidden" name="action" value="delete_categoria">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                  <input type="hidden" name="id" value="<?= $c['id'] ?>">
                  <button type="submit" class="btn btn-danger">✕</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="section">
      <div class="section-head"><h3>Marcas (<?= count($marcas) ?>)</h3></div>
      <div class="section-body">
        <?php if (empty($marcas)): ?>
          <div class="empty-state">Nenhuma marca.</div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>ID</th><th>Nome</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($marcas as $m): ?>
            <tr>
              <td><small style="color:#ccc"><?= $m['id'] ?></small></td>
              <td><strong><?= htmlspecialchars($m['nome']) ?></strong></td>
              <td>
                <form method="POST" onsubmit="return confirm('Eliminar marca?')" style="display:inline">
                  <input type="hidden" name="action" value="delete_marca">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <button type="submit" class="btn btn-danger">✕</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════ PRODUTOS -->
<div id="tab-produtos" style="display:none">
  <div class="section">
    <div class="section-head">
      <h3>Adicionar Produto</h3>
      <small style="color:#bbb">Cria <code>categorias</code> e <code>marcas</code> antes de adicionar produtos</small>
    </div>
    <div class="section-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_produto">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-row">
          <div class="form-group">
            <label>Nome</label>
            <input type="text" name="nome" placeholder="Ex: Mouse Ótico USB" required maxlength="50">
          </div>
          <div class="form-group">
            <label>Tipo</label>
            <select name="tipo" required>
              <option value="">Selecionar...</option>
              <option value="INFORMATICO">INFORMÁTICO</option>
              <option value="ACADEMICO">ACADÉMICO</option>
              <option value="REDES">REDES</option>
            </select>
          </div>
          <div class="form-group">
            <label>Categoria *</label>
            <select name="categoria_id" required>
              <option value="">Selecionar...</option>
              <?php foreach ($categorias as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Marca (opcional)</label>
            <select name="marca_id">
              <option value="">— Sem marca —</option>
              <?php foreach ($marcas as $m): ?>
              <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Nº Série (opcional)</label>
            <input type="text" name="numero_serie" placeholder="Ex: MS001-LOG-2024" maxlength="50">
            <span class="form-hint">Deve ser único no sistema</span>
          </div>
          <div class="form-group">
            <label>Preço Unitário (MT)</label>
            <input type="number" name="preco_unitario" placeholder="0.00" min="0" step="0.01" value="0.00" required>
            <span class="form-hint">Usado pelos triggers automaticamente</span>
          </div>
        </div>
        <button type="submit" class="btn btn-primary">+ Adicionar Produto</button>
      </form>
    </div>
  </div>

  <div class="section">
    <div class="section-head"><h3>Lista de Produtos (<?= count($produtos) ?>)</h3></div>
    <div class="section-body">
      <?php if (empty($produtos)): ?>
        <div class="empty-state">Nenhum produto cadastrado.</div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>ID</th><th>Nome</th><th>Tipo</th><th>Categoria</th><th>Marca</th>
            <th>Nº Série</th><th>Preço Unit.</th><th>Criado em</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($produtos as $p): ?>
          <tr>
            <td><small style="color:#ccc"><?= $p['id'] ?></small></td>
            <td><strong><?= htmlspecialchars($p['nome']) ?></strong></td>
            <td><span class="badge <?= $tipo_badge[$p['tipo']] ?? 'badge-gray' ?>"><?= htmlspecialchars($p['tipo'] ?? 'DESCONHECIDO') ?></span></td>
            <td><?= htmlspecialchars($p['categoria_nome'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['marca_nome'] ?? '—') ?></td>
            <td><?= $p['numero_serie'] ? '<span class="serie-pill">'.htmlspecialchars($p['numero_serie']).'</span>' : '<span style="color:#ddd">—</span>' ?></td>
            <td class="price"><?= number_format($p['preco_unitario'],2,',','.') ?> MT</td>
            <td><small style="color:#bbb"><?= $p['created_at'] ?></small></td>
            <td>
              <form method="POST" onsubmit="return confirm('Confirmas a eliminação?')" style="display:inline">
                <input type="hidden" name="action" value="delete_produto">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-danger">Eliminar</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════ ENTRADAS -->
<div id="tab-entradas" style="display:none">
  <div class="section">
    <div class="section-head">
      <h3>Registar Entrada de Stock</h3>
      <small style="color:#bbb">Trigger <code>trg_entrada_valor_total</code> calcula o valor automaticamente</small>
    </div>
    <div class="section-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_entrada">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-row">
          <div class="form-group" style="grid-column:span 2">
            <label>Produto</label>
            <select name="produto_id" required>
              <option value="">Selecionar produto...</option>
              <?php foreach ($produtos as $p): ?>
              <option value="<?= $p['id'] ?>">
                [<?= $p['tipo'] ?>] <?= htmlspecialchars($p['nome']) ?>
                <?= $p['marca_nome'] ? ' · '.$p['marca_nome'] : '' ?>
                — <?= number_format($p['preco_unitario'],2,',','.') ?> MT
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Quantidade</label>
            <input type="number" name="quantidade" min="1" placeholder="0" required>
          </div>
          <div class="form-group" style="justify-content:flex-end">
            <button type="submit" class="btn btn-success" style="margin-top:auto">↓ Registar Entrada</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="section">
    <div class="section-head"><h3>Histórico de Entradas (últimas 30)</h3></div>
    <div class="section-body">
      <?php if (empty($entradas)): ?>
        <div class="empty-state">Nenhuma entrada registada.</div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>ID</th><th>Produto</th><th>Tipo</th><th>Qtd</th><th>Preço Unit.</th><th>Valor Total (trigger)</th><th>Data/Hora</th></tr></thead>
          <tbody>
          <?php foreach ($entradas as $e): ?>
          <tr>
            <td><small style="color:#ccc"><?= $e['id'] ?></small></td>
            <td><?= htmlspecialchars($e['nome']) ?></td>
            <td><span class="badge <?= $tipo_badge[$e['tipo']] ?? 'badge-gray' ?>"><?= htmlspecialchars($e['tipo'] ?? 'DESCONHECIDO') ?></span></td>
            <td><strong><?= $e['quantidade'] ?></strong></td>
            <td class="price"><?= number_format($e['preco_unitario'],2,',','.') ?> MT</td>
            <td class="price price-blue"><?= number_format($e['valor_total'],2,',','.') ?> MT</td>
            <td><small><?= $e['hora_entrada'] ?></small></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════ SAÍDAS -->
<div id="tab-saidas" style="display:none">
  <div class="section">
    <div class="section-head">
      <h3>Registar Saída / Venda</h3>
      <small style="color:#bbb">Triggers <code>trg_saida_valor_total</code> e <code>trg_venda_valores</code> aplicam-se automaticamente</small>
    </div>
    <div class="section-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_saida">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-row">
          <div class="form-group" style="grid-column:span 2">
            <label>Produto</label>
            <select name="produto_id" required>
              <option value="">Selecionar produto...</option>
              <?php foreach ($stock_summary as $p): $s=(int)$p['stock_actual']; ?>
              <option value="<?= $p['id'] ?>" <?= $s<=0 ? 'style="color:#ccc"' : '' ?>>
                [<?= $p['tipo'] ?>] <?= htmlspecialchars($p['nome']) ?>
                — Stock: <?= $s ?> un. — <?= number_format($p['preco_unitario'],2,',','.') ?> MT
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Quantidade</label>
            <input type="number" name="quantidade" min="1" placeholder="0" required>
          </div>
          <div class="form-group">
            <label>Descrição da Venda</label>
            <input type="text" name="descricao" placeholder="Ex: Venda ao cliente João" maxlength="100">
          </div>
          <div class="form-group" style="justify-content:flex-end">
            <button type="submit" class="btn btn-amber" style="margin-top:auto">↑ Registar Saída</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="grid-2">
    <div class="section">
      <div class="section-head"><h3>Histórico de Saídas</h3></div>
      <div class="section-body">
        <?php if (empty($saidas)): ?>
          <div class="empty-state">Nenhuma saída.</div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>ID</th><th>Produto</th><th>Qtd</th><th>Valor Total</th><th>Data/Hora</th></tr></thead>
            <tbody>
            <?php foreach ($saidas as $s): ?>
            <tr>
              <td><small style="color:#ccc"><?= $s['id'] ?></small></td>
              <td><?= htmlspecialchars($s['nome']) ?></td>
              <td><strong><?= $s['quantidade'] ?></strong></td>
              <td class="price price-blue"><?= number_format($s['valor_total'],2,',','.') ?> MT</td>
              <td><small><?= $s['hora_saida'] ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="section">
      <div class="section-head"><h3>Registo de Vendas</h3></div>
      <div class="section-body">
        <?php if (empty($vendas)): ?>
          <div class="empty-state">Nenhuma venda.</div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>ID</th><th>Produto</th><th>Qtd</th><th>Unit.</th><th>Total</th><th>Descrição</th><th>Data</th></tr></thead>
            <tbody>
            <?php foreach ($vendas as $v): ?>
            <tr>
              <td><small style="color:#ccc"><?= $v['id'] ?></small></td>
              <td><?= htmlspecialchars($v['nome']) ?></td>
              <td><strong><?= $v['quantidade'] ?></strong></td>
              <td class="price"><?= number_format($v['valor_unitario'],2,',','.') ?> MT</td>
              <td class="price price-blue"><?= number_format($v['valor_total'],2,',','.') ?> MT</td>
              <td><small><?= htmlspecialchars($v['descricao'] ?? '—') ?></small></td>
              <td><small><?= $v['data_venda'] ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════ TABELA ESTOQUES -->
<div id="tab-estoques" style="display:none">
  <div class="section">
    <div class="section-head"><h3>Tabela <code>estoques</code> — Detalhe por Categoria de Produto</h3></div>
    <div class="section-body">
      <?php if (empty($estoques)): ?>
        <div class="empty-state">Nenhum registo de estoque.</div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>ID</th><th>Produto</th><th>Tipo</th><th>Categoria</th><th>Marca</th>
            <th>Informático</th><th>Académico</th><th>Redes</th><th>Data Entrada</th>
          </tr></thead>
          <tbody>
          <?php foreach ($estoques as $e): ?>
          <tr>
            <td><small style="color:#ccc"><?= $e['id'] ?></small></td>
            <td><?= htmlspecialchars($e['nome']) ?></td>
            <td><span class="badge <?= $tipo_badge[$e['tipo']] ?? 'badge-gray' ?>"><?= htmlspecialchars($e['tipo'] ?? 'DESCONHECIDO') ?></span></td>
            <td><?= htmlspecialchars($e['categoria_nome'] ?? '—') ?></td>
            <td><?= htmlspecialchars($e['marca_nome'] ?? '—') ?></td>
            <td><?= $e['informatico']>0 ? '<strong>'.$e['informatico'].'</strong>' : '<span style="color:#e5e7eb">0</span>' ?></td>
            <td><?= $e['academico']>0  ? '<strong>'.$e['academico'].'</strong>'  : '<span style="color:#e5e7eb">0</span>' ?></td>
            <td><?= $e['redes']>0      ? '<strong>'.$e['redes'].'</strong>'      : '<span style="color:#e5e7eb">0</span>' ?></td>
            <td><small><?= $e['hora_entrada'] ?></small></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════ VIEWS DO BD -->
<div id="tab-views" style="display:none">
  <div class="section">
    <div class="section-head">
      <h3>VIEW: <code>vw_estoque_atual</code></h3>
      <span class="badge badge-purple">SQL View</span>
    </div>
    <div class="section-body">
      <?php if (empty($vw_estoque)): ?>
        <div class="empty-state">View sem dados ou não criada ainda.</div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>Produto</th><th>Categoria</th><th>Marca</th><th>Nº Série</th>
            <th>Tipo</th><th>Preço Unit.</th><th>Stock Actual</th><th>Valor em Stock</th>
          </tr></thead>
          <tbody>
          <?php foreach ($vw_estoque as $r):
            $s = (int)($r['estoque_atual'] ?? 0);
            $scls = $s <= 0 ? 's-zero' : ($s < 5 ? 's-low' : 's-ok');
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['nome']) ?></strong></td>
            <td><?= htmlspecialchars($r['categoria'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['marca'] ?? '—') ?></td>
            <td><?= $r['numero_serie'] ? '<span class="serie-pill">'.htmlspecialchars($r['numero_serie']).'</span>' : '—' ?></td>
            <td><span class="badge <?= $tipo_badge[$r['tipo']] ?? 'badge-gray' ?>"><?= htmlspecialchars($r['tipo'] ?? 'DESCONHECIDO') ?></span></td>
            <td class="price"><?= number_format($r['preco_unitario'],2,',','.') ?> MT</td>
            <td><span class="<?= $scls ?>"><?= $s ?> un.</span></td>
            <td class="price price-blue"><?= number_format($r['valor_estoque'] ?? 0,2,',','.') ?> MT</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="section-head">
      <h3>VIEW: <code>vw_vendas_resumo</code></h3>
      <span class="badge badge-purple">SQL View</span>
    </div>
    <div class="section-body">
      <?php if (empty($vw_vendas)): ?>
        <div class="empty-state">Sem vendas ainda.</div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>Dia</th><th>Produto</th><th>Categoria</th><th>Marca</th>
            <th>Nº Série</th><th>Qtd Vendida</th><th>Receita</th>
          </tr></thead>
          <tbody>
          <?php foreach ($vw_vendas as $r): ?>
          <tr>
            <td><?= $r['dia'] ?></td>
            <td><?= htmlspecialchars($r['nome']) ?></td>
            <td><?= htmlspecialchars($r['categoria'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['marca'] ?? '—') ?></td>
            <td><?= $r['numero_serie'] ? '<span class="serie-pill">'.htmlspecialchars($r['numero_serie']).'</span>' : '—' ?></td>
            <td><strong><?= $r['qtd_vendida'] ?? 0 ?></strong></td>
            <td class="price price-blue"><?= number_format($r['receita'] ?? 0,2,',','.') ?> MT</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════ RELATÓRIOS -->
<div id="tab-relatorios" style="display:none">
  <div class="grid-2">
    <div class="section">
      <div class="section-head">
        <h3>Relatório de Hoje</h3>
        <span class="badge badge-purple">PROCEDURE</span>
      </div>
      <div class="section-body">
        <form method="POST">
          <input type="hidden" name="action" value="gerar_relatorio">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <p style="color:#888;font-size:13px;margin-bottom:12px">
            Executa <code>CALL AtualizarRelatorioDiario(CURDATE())</code> para hoje: <strong><?= date('d/m/Y') ?></strong>
          </p>
          <button type="submit" class="btn btn-info">📊 Gerar Relatório de Hoje</button>
        </form>
      </div>
    </div>

    <div class="section">
      <div class="section-head">
        <h3>Relatório Mensal</h3>
        <span class="badge badge-purple">PROCEDURE</span>
      </div>
      <div class="section-body">
        <form method="POST">
          <input type="hidden" name="action" value="relatorio_mensal">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <div class="form-row" style="margin-bottom:12px">
            <div class="form-group">
              <label>Ano</label>
              <input type="number" name="ano" value="<?= $rel_ano ?>" min="2020" max="2099" required>
            </div>
            <div class="form-group">
              <label>Mês</label>
              <select name="mes">
                <?php for ($i=1;$i<=12;$i++): ?>
                <option value="<?= $i ?>" <?= $i==$rel_mes?'selected':'' ?>><?= $meses_pt[$i] ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
          <div style="display:flex;gap:12px">
            <button type="submit" class="btn btn-outline">📅 Ver Relatório Mensal</button>
            <?php if (!empty($rel_mensal)): ?>
              <a href="export_docx.php?type=mensal&ano=<?= $rel_ano ?>&mes=<?= $rel_mes ?>" class="btn btn-info">📄 Download DOCX</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if (!empty($rel_mensal)): ?>
  <div class="section">
    <div class="section-head">
      <h3>Resultado — <?= $meses_pt[$rel_mes] ?> <?= $rel_ano ?></h3>
    </div>
    <div class="section-body">
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Data</th><th>Entradas</th><th>Saídas</th><th>Vendas (qtd)</th><th>Valor Vendas</th><th>Ticket Médio</th></tr></thead>
          <tbody>
          <?php $tot_e=$tot_s=$tot_v=$tot_val=0;
          foreach ($rel_mensal as $r):
            $ticket = $r['total_vendas']>0 ? round($r['valor_total_vendas']/$r['total_vendas'],2) : 0;
            $tot_e+=$r['total_entradas']; $tot_s+=$r['total_saidas'];
            $tot_v+=$r['total_vendas'];   $tot_val+=$r['valor_total_vendas'];
          ?>
          <tr>
            <td><?= date('d/m/Y', strtotime($r['data_relatorio'])) ?></td>
            <td><?= $r['total_entradas'] ?></td>
            <td><?= $r['total_saidas'] ?></td>
            <td><?= $r['total_vendas'] ?></td>
            <td class="price price-blue"><?= number_format($r['valor_total_vendas'],2,',','.') ?> MT</td>
            <td class="price"><?= number_format($ticket,2,',','.') ?> MT</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td>TOTAL</td>
              <td><?= $tot_e ?></td>
              <td><?= $tot_s ?></td>
              <td><?= $tot_v ?></td>
              <td class="price price-blue"><?= number_format($tot_val,2,',','.') ?> MT</td>
              <td class="price"><?= $tot_v>0 ? number_format($tot_val/$tot_v,2,',','.') : '0,00' ?> MT</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="section">
    <div class="section-head"><h3>Últimos 15 Relatórios Diários</h3></div>
    <div class="section-body">
      <?php if (empty($relatorios)): ?>
        <div class="empty-state">Nenhum relatório gerado.</div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>ID</th><th>Data</th><th>Entradas</th><th>Saídas</th><th>Vendas</th><th>Valor Total</th><th>Gerado em</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($relatorios as $r): ?>
          <tr>
            <td><small style="color:#ccc"><?= $r['id'] ?></small></td>
            <td><strong><?= date('d/m/Y', strtotime($r['data_relatorio'])) ?></strong></td>
            <td><?= $r['total_entradas'] ?></td>
            <td><?= $r['total_saidas'] ?></td>
            <td><?= $r['total_vendas'] ?></td>
            <td class="price price-blue"><?= number_format($r['valor_total_vendas'],2,',','.') ?> MT</td>
            <td><small style="color:#bbb"><?= $r['created_at'] ?></small></td>
            <td style="display:flex;gap:6px;align-items:center;justify-content:flex-end">
              <a href="export_docx.php?type=diario&id=<?= $r['id'] ?>" class="btn btn-info" title="Exportar para DOCX" style="font-size:12px;padding:5px 10px">📄 DOCX</a>
              <form method="POST" onsubmit="return confirm('Eliminar relatório?')" style="display:inline">
                <input type="hidden" name="action" value="delete_relatorio">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-danger">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php endif; ?>
</main>

<script>
const tabs = ['dashboard','categorias','produtos','entradas','saidas','estoques','views','relatorios'];
const titles = {
  dashboard:'Dashboard', categorias:'Categorias & Marcas', produtos:'Produtos',
  entradas:'Entradas de Stock', saidas:'Saídas / Vendas',
  estoques:'Tabela Estoques', views:'Views do Banco de Dados', relatorios:'Relatórios Diários'
};
function showTab(name, el) {
  tabs.forEach(t => {
    const tab = document.getElementById('tab-'+t);
    if (tab) tab.style.display = (t===name) ? 'block' : 'none';
  });
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (el) el.classList.add('active');
  const title = document.getElementById('page-title');
  if (title) title.textContent = titles[name] || name;
}
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('input[step="0.01"]').forEach(inp => {
    inp.addEventListener('blur', function() {
      if (this.value) this.value = parseFloat(this.value).toFixed(2);
    });
  });
});
</script>
</body>
</html>
