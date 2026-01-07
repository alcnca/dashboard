<?php
// export_pdf.php
require __DIR__ . "/vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

$jsonUrl = "https://raw.githubusercontent.com/alcnca/mapa-semaforo/refs/heads/main/datos.json";

function fetchJson($url) {
  $ctx = stream_context_create(["http" => ["timeout" => 15]]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
function parseMoney($value) {
  if ($value === null) return 0.0;
  $s = str_replace(["$", ",", " "], "", (string)$value);
  return is_numeric($s) ? (float)$s : 0.0;
}
function toInt($value) {
  $s = str_replace([",", " "], "", trim((string)$value));
  return is_numeric($s) ? (int)$s : 0;
}
function urgencyLevel($row) {
  $diasAtraso = toInt($row["Dias de Atraso"] ?? 0);
  $diasSinGestion = toInt($row["Dias sin Gestion"] ?? 0);
  $fase = strtoupper(trim((string)($row["Fase"] ?? "")));
  $banda = strtoupper(trim((string)($row["Banda"] ?? "")));
  $saldo = parseMoney($row["Saldo Total"] ?? ($row["Total"] ?? "0"));

  $isBanda4 = preg_match("/BANDA\s*4|BANDA\s*5|BANDA\s*6|BANDA\s*7|BANDA\s*8/i", $banda) === 1;
  $isExtrajudicial = str_contains($fase, "EXTRAJUDICIAL");
  $isJudicial = str_contains($fase, "JUDICIAL");
  $isSaldoAlto = $saldo >= 200000;

  if ($diasAtraso >= 45 || $diasSinGestion > 7 || $isExtrajudicial || $isJudicial || $isBanda4 || $isSaldoAlto) return "ROJO";
  if (($diasAtraso >= 15 && $diasAtraso <= 44) || preg_match("/BANDA\s*2|BANDA\s*3/i", $banda) === 1) return "NARANJA";
  if ($diasAtraso >= 1) return "AMARILLO";
  return "VERDE";
}

$data = fetchJson($jsonUrl);

$selectedCoord = $_GET["coordinador"] ?? "TODOS";
$selectedSuc = $_GET["sucursal"] ?? "TODOS";
$selectedUrg = $_GET["urgencia"] ?? "TODAS";
$search = trim($_GET["q"] ?? "");

$filtered = [];
foreach ($data as $r) {
  $coord = trim((string)($r["Coordinador"] ?? ""));
  $suc = trim((string)($r["Sucursal"] ?? ""));
  $urg = urgencyLevel($r);

  if ($selectedCoord !== "TODOS" && $coord !== $selectedCoord) continue;
  if ($selectedSuc !== "TODOS" && $suc !== $selectedSuc) continue;
  if ($selectedUrg !== "TODAS" && $urg !== $selectedUrg) continue;

  if ($search !== "") {
    $hay = false;
    $needle = mb_strtolower($search);
    $fields = ["Nombre", "Socio", "Linea", "Ejecutivo", "Ejecutivo2", "Producto", "Fase", "Banda", "Resultado"];
    foreach ($fields as $f) {
      $v = mb_strtolower((string)($r[$f] ?? ""));
      if ($v !== "" && str_contains($v, $needle)) { $hay = true; break; }
    }
    if (!$hay) continue;
  }

  $r["_Urgencia"] = $urg;
  $filtered[] = $r;
}

// HTML para PDF
$total = count($filtered);
$sumSaldo = 0.0;
foreach ($filtered as $r) $sumSaldo += parseMoney($r["Saldo Total"] ?? ($r["Total"] ?? "0"));

$h = function($s){ return htmlspecialchars((string)$s); };

$html = "<html><head><meta charset='utf-8'>
<style>
  body{font-family: DejaVu Sans, sans-serif; font-size:10px; color:#111;}
  h2{margin:0 0 8px 0;}
  .meta{margin:0 0 10px 0; font-size:10px;}
  table{width:100%; border-collapse:collapse;}
  th,td{border:1px solid #ddd; padding:6px; vertical-align:top;}
  th{background:#f3f4f7;}
  .b{font-weight:bold;}
</style>
</head><body>";

$html .= "<h2>Reporte de Recuperación (Filtrado)</h2>";
$html .= "<div class='meta'>
  <div><span class='b'>Coordinador:</span> ".$h($selectedCoord)." | <span class='b'>Sucursal:</span> ".$h($selectedSuc)." | <span class='b'>Urgencia:</span> ".$h($selectedUrg)."</div>
  <div><span class='b'>Registros:</span> ".$h($total)." | <span class='b'>Saldo total estimado:</span> $".number_format($sumSaldo,2)."</div>
</div>";

$html .= "<table><thead><tr>
  <th>Urgencia</th><th>Sucursal</th><th>Coordinador</th><th>Socio</th><th>Línea</th><th>Nombre</th>
  <th>Días atraso</th><th>Días sin gestión</th><th>Fase</th><th>Banda</th><th>Saldo</th><th>Resultado</th>
</tr></thead><tbody>";

foreach ($filtered as $r) {
  $saldo = parseMoney($r["Saldo Total"] ?? ($r["Total"] ?? "0"));
  $html .= "<tr>
    <td>".$h($r["_Urgencia"] ?? "")."</td>
    <td>".$h($r["Sucursal"] ?? "")."</td>
    <td>".$h($r["Coordinador"] ?? "")."</td>
    <td>".$h($r["Socio"] ?? "")."</td>
    <td>".$h($r["Linea"] ?? "")."</td>
    <td>".$h($r["Nombre"] ?? "")."</td>
    <td>".$h($r["Dias de Atraso"] ?? "")."</td>
    <td>".$h($r["Dias sin Gestion"] ?? "")."</td>
    <td>".$h($r["Fase"] ?? "")."</td>
    <td>".$h($r["Banda"] ?? "")."</td>
    <td>$".number_format($saldo,2)."</td>
    <td>".$h($r["Resultado"] ?? "")."</td>
  </tr>";
}

$html .= "</tbody></table></body></html>";

$options = new Options();
$options->set("isRemoteEnabled", true);
$options->set("defaultFont", "DejaVu Sans");

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, "UTF-8");
$dompdf->setPaper("A4", "landscape");
$dompdf->render();

$filename = "reporte_recuperacion.pdf";
header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=\"$filename\"");
echo $dompdf->output();
exit;
