<?php
// export_excel.php
require __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Recuperacion");

$headers = [
  "Urgencia","Sucursal","Coordinador","Socio","Linea","Nombre","Producto",
  "Dias de Atraso","Dias sin Gestion","Fase","Banda","Saldo Total","Cuota",
  "Cuotas","Cuotas Vencidas","Porcentaje Pagado","Resultado",
  "Fecha Ultima Gestion","Tipo de Gestion","Ejecutivo","Telefono Ejecutivo"
];

$sheet->fromArray($headers, null, "A1");

$rowNum = 2;
foreach ($filtered as $r) {
  $saldo = parseMoney($r["Saldo Total"] ?? ($r["Total"] ?? "0"));
  $cuota = parseMoney($r["Cuota"] ?? 0);

  $sheet->fromArray([
    $r["_Urgencia"] ?? "",
    $r["Sucursal"] ?? "",
    $r["Coordinador"] ?? "",
    $r["Socio"] ?? "",
    $r["Linea"] ?? "",
    $r["Nombre"] ?? "",
    $r["Producto"] ?? "",
    toInt($r["Dias de Atraso"] ?? 0),
    toInt($r["Dias sin Gestion"] ?? 0),
    $r["Fase"] ?? "",
    $r["Banda"] ?? "",
    $saldo,
    $cuota,
    $r["Cuotas"] ?? "",
    $r["Cuotas Vencidas"] ?? "",
    $r["Porcentaje Pagado"] ?? "",
    $r["Resultado"] ?? "",
    $r["Fecha Ultima Gestion"] ?? "",
    $r["Tipo de Gestion"] ?? "",
    $r["Ejecutivo"] ?? "",
    $r["Telefono Ejecutivo"] ?? "",
  ], null, "A{$rowNum}");

  $rowNum++;
}

foreach (range("A", "U") as $col) {
  $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = "reporte_recuperacion.xlsx";
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: max-age=0");

$writer = new Xlsx($spreadsheet);
$writer->save("php://output");
exit;
