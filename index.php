<?php
// index.php
// Dashboard PHP que consume JSON remoto y renderiza filtros + tabla + exportaciones

$jsonUrl = "https://raw.githubusercontent.com/alcnca/mapa-semaforo/refs/heads/main/datos.json";

function fetchJson($url) {
  $ctx = stream_context_create([
    "http" => ["timeout" => 15]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) {
    return [false, "No se pudo descargar el JSON desde: $url"];
  }
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return [false, "El JSON no es válido o no es un arreglo."];
  }
  return [true, $data];
}

function parseMoney($value) {
  // Soporta "$13,392.65" o "13312.71"
  if ($value === null) return 0.0;
  $s = (string)$value;
  $s = str_replace(["$", ",", " "], "", $s);
  return is_numeric($s) ? (float)$s : 0.0;
}

function toInt($value) {
  if ($value === null) return 0;
  if (is_int($value)) return $value;
  $s = trim((string)$value);
  $s = str_replace([",", " "], "", $s);
  return is_numeric($s) ? (int)$s : 0;
}

function urgencyLevel($row) {
  // Regla práctica (ajustable):
  // ROJO: atraso >=45 o fase extrajudicial/judicial o dias sin gestion >7 o banda 4+
  // NARANJA: atraso 15-44 o banda 2-3 o saldo alto
  // AMARILLO: atraso 1-14
  $diasAtraso = toInt($row["Dias de Atraso"] ?? 0);
  $diasSinGestion = toInt($row["Dias sin Gestion"] ?? 0);
  $fase = strtoupper(trim((string)($row["Fase"] ?? "")));
  $banda = strtoupper(trim((string)($row["Banda"] ?? "")));
  $saldo = parseMoney($row["Saldo Total"] ?? ($row["Total"] ?? "0"));

  $isBanda4 = preg_match("/BANDA\s*4|BANDA\s*5|BANDA\s*6|BANDA\s*7|BANDA\s*8/i", $banda) === 1;
  $isExtrajudicial = str_contains($fase, "EXTRAJUDICIAL");
  $isJudicial = str_contains($fase, "JUDICIAL");
  $isSaldoAlto = $saldo >= 200000;

  if ($diasAtraso >= 45 || $diasSinGestion > 7 || $isExtrajudicial || $isJudicial || $isBanda4 || $isSaldoAlto) {
    return ["ROJO", 3];
  }
  if (($diasAtraso >= 15 && $diasAtraso <= 44) || preg_match("/BANDA\s*2|BANDA\s*3/i", $banda) === 1) {
    return ["NARANJA", 2];
  }
  if ($diasAtraso >= 1) {
    return ["AMARILLO", 1];
  }
  return ["VERDE", 0];
}

list($ok, $payload) = fetchJson($jsonUrl);
if (!$ok) {
  http_response_code(500);
  echo "<h2>Error</h2><p>" . htmlspecialchars($payload) . "</p>";
  exit;
}

$data = $payload;

// Construir catálogos de filtros
$coordinadores = [];
$sucursales = [];

foreach ($data as $r) {
  $c = trim((string)($r["Coordinador"] ?? ""));
  $s = trim((string)($r["Sucursal"] ?? ""));
  if ($c !== "") $coordinadores[$c] = true;
  if ($s !== "") $sucursales[$s] = true;
}

$coordinadores = array_keys($coordinadores);
$sucursales = array_keys($sucursales);
sort($coordinadores);
sort($sucursales);

// Parámetros
$selectedCoord = $_GET["coordinador"] ?? "TODOS";
$selectedSuc = $_GET["sucursal"] ?? "TODOS";
$selectedUrg = $_GET["urgencia"] ?? "TODAS"; // ROJO, NARANJA, AMARILLO, VERDE, TODAS
$search = trim($_GET["q"] ?? "");

// Filtrar
$filtered = [];
foreach ($data as $r) {
  $coord = trim((string)($r["Coordinador"] ?? ""));
  $suc = trim((string)($r["Sucursal"] ?? ""));

  [$urg, $urgScore] = urgencyLevel($r);
  $r["_Urgencia"] = $urg;
  $r["_UrgenciaScore"] = $urgScore;

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

  $filtered[] = $r;
}

// Ordenar: urgencia desc, dias atraso desc, saldo desc
usort($filtered, function($a, $b) {
  if (($b["_UrgenciaScore"] ?? 0) !== ($a["_UrgenciaScore"] ?? 0)) {
    return ($b["_UrgenciaScore"] ?? 0) <=> ($a["_UrgenciaScore"] ?? 0);
  }
  $da = toInt($a["Dias de Atraso"] ?? 0);
  $db = toInt($b["Dias de Atraso"] ?? 0);
  if ($db !== $da) return $db <=> $da;

  $sa = parseMoney($a["Saldo Total"] ?? ($a["Total"] ?? "0"));
  $sb = parseMoney($b["Saldo Total"] ?? ($b["Total"] ?? "0"));
  return $sb <=> $sa;
});

// KPIs
$totalCreditos = count($filtered);
$sumSaldo = 0.0;
$kpi = ["ROJO"=>0,"NARANJA"=>0,"AMARILLO"=>0,"VERDE"=>0];
foreach ($filtered as $r) {
  $sumSaldo += parseMoney($r["Saldo Total"] ?? ($r["Total"] ?? "0"));
  $kpi[$r["_Urgencia"]] = ($kpi[$r["_Urgencia"]] ?? 0) + 1;
}

function h($s){ return htmlspecialchars((string)$s); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Dashboard Recuperación - Por Coordinador</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; margin:0; background:#f6f7fb; color:#111;}
    .wrap{max-width:1200px; margin:0 auto; padding:18px;}
    .topbar{display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:space-between;}
    .card{background:#fff; border:1px solid #e7e8ee; border-radius:14px; padding:14px; box-shadow: 0 1px 1px rgba(0,0,0,.03);}
    .filters{display:grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap:10px;}
    .filters label{font-size:12px; color:#444;}
    .filters select,.filters input{width:100%; padding:10px; border:1px solid #d9dbe6; border-radius:10px; background:#fff;}
    .btns{display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;}
    .btn{display:inline-block; padding:10px 12px; border-radius:10px; border:1px solid #cfd2e2; background:#111; color:#fff; text-decoration:none; font-size:14px;}
    .btn.secondary{background:#fff; color:#111;}
    .kpis{display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:10px; margin-top:12px;}
    .kpi{display:flex; flex-direction:column; gap:6px;}
    .kpi .v{font-size:22px; font-weight:700;}
    table{width:100%; border-collapse:collapse; margin-top:12px; background:#fff; border:1px solid #e7e8ee; border-radius:14px; overflow:hidden;}
    th,td{padding:10px; border-bottom:1px solid #eef0f6; vertical-align:top; font-size:13px;}
    th{background:#fafbff; text-align:left; font-size:12px; color:#333; position:sticky; top:0;}
    .badge{display:inline-block; padding:4px 8px; border-radius:999px; font-size:12px; border:1px solid #ddd;}
    .rojo{background:#ffe8e8; border-color:#ffb3b3;}
    .naranja{background:#fff2e3; border-color:#ffd1a6;}
    .amarillo{background:#fffadf; border-color:#ffef9f;}
    .verde{background:#eaffea; border-color:#b8f0b8;}
    .muted{color:#666; font-size:12px;}
    .footer{margin-top:14px; color:#666; font-size:12px;}
    @media (max-width: 980px){
      .filters{grid-template-columns: repeat(2, minmax(0, 1fr));}
      .kpis{grid-template-columns: repeat(2, minmax(0, 1fr));}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <h2 style="margin:0;">Dashboard de Recuperación (Consulta por Coordinador)</h2>
      <div class="btns">
        <?php
          $q = $_GET;
          $q["export"] = "xlsx";
          $xlsxUrl = "export_excel.php?" . http_build_query($q);
          $q["export"] = "pdf";
          $pdfUrl = "export_pdf.php?" . http_build_query($q);
        ?>
        <a class="btn secondary" href="<?=h($xlsxUrl)?>">Exportar Excel</a>
        <a class="btn secondary" href="<?=h($pdfUrl)?>">Exportar PDF</a>
      </div>
    </div>

    <div class="card" style="margin-top:12px;">
      <form method="get">
        <div class="filters">
          <div>
            <label>Coordinador</label>
            <select name="coordinador">
              <option value="TODOS" <?= $selectedCoord==="TODOS"?"selected":"" ?>>TODOS</option>
              <?php foreach($coordinadores as $c): ?>
                <option value="<?=h($c)?>" <?= $selectedCoord===$c?"selected":"" ?>><?=h($c)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Sucursal</label>
            <select name="sucursal">
              <option value="TODOS" <?= $selectedSuc==="TODOS"?"selected":"" ?>>TODAS</option>
              <?php foreach($sucursales as $s): ?>
                <option value="<?=h($s)?>" <?= $selectedSuc===$s?"selected":"" ?>><?=h($s)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Urgencia</label>
            <select name="urgencia">
              <?php
                $opts = ["TODAS","ROJO","NARANJA","AMARILLO","VERDE"];
                foreach($opts as $o){
                  $sel = $selectedUrg===$o ? "selected" : "";
                  echo "<option value='".h($o)."' $sel>".h($o)."</option>";
                }
              ?>
            </select>
          </div>
          <div>
            <label>Buscar (Nombre, Socio, Línea, Producto…)</label>
            <input name="q" value="<?=h($search)?>" placeholder="Ej. NAH CHAN, 0410..., PERSONAL, COMPROMISO...">
          </div>
          <div style="display:flex; align-items:flex-end; gap:10px;">
            <button class="btn" type="submit" style="border:none; cursor:pointer;">Aplicar filtros</button>
            <a class="btn secondary" href="index.php">Limpiar</a>
          </div>
        </div>
      </form>
    </div>

    <div class="kpis">
      <div class="card kpi">
        <div class="muted">Créditos (filtrados)</div>
        <div class="v"><?=h($totalCreditos)?></div>
        <div class="muted">Saldo total estimado: <?=h(number_format($sumSaldo,2))?></div>
      </div>
      <div class="card kpi">
        <div class="muted">Urgentes (ROJO)</div>
        <div class="v"><?=h($kpi["ROJO"] ?? 0)?></div>
        <div class="muted">Prioridad inmediata</div>
      </div>
      <div class="card kpi">
        <div class="muted">Altos (NARANJA)</div>
        <div class="v"><?=h($kpi["NARANJA"] ?? 0)?></div>
        <div class="muted">Seguimiento prioritario</div>
      </div>
      <div class="card kpi">
        <div class="muted">Medios (AMARILLO)</div>
        <div class="v"><?=h($kpi["AMARILLO"] ?? 0)?></div>
        <div class="muted">Prevención / contención</div>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Urgencia</th>
          <th>Sucursal</th>
          <th>Coordinador</th>
          <th>Socio / Línea</th>
          <th>Nombre</th>
          <th>Días atraso</th>
          <th>Días sin gestión</th>
          <th>Fase / Banda</th>
          <th>Saldo</th>
          <th>Resultado / Última gestión</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($filtered) === 0): ?>
          <tr><td colspan="10" class="muted">Sin resultados con los filtros actuales.</td></tr>
        <?php else: ?>
          <?php foreach($filtered as $r): ?>
            <?php
              $u = $r["_Urgencia"];
              $cls = match($u){
                "ROJO" => "badge rojo",
                "NARANJA" => "badge naranja",
                "AMARILLO" => "badge amarillo",
                "VERDE" => "badge verde",
                default => "badge"
              };
              $saldo = parseMoney($r["Saldo Total"] ?? ($r["Total"] ?? "0"));
            ?>
            <tr>
              <td><span class="<?=h($cls)?>"><?=h($u)?></span></td>
              <td><?=h($r["Sucursal"] ?? "")?></td>
              <td><?=h($r["Coordinador"] ?? "")?></td>
              <td>
                <div><b>Socio:</b> <?=h($r["Socio"] ?? "")?></div>
                <div class="muted"><b>Línea:</b> <?=h($r["Linea"] ?? "")?></div>
              </td>
              <td><?=h($r["Nombre"] ?? "")?></td>
              <td><?=h($r["Dias de Atraso"] ?? "")?></td>
              <td><?=h($r["Dias sin Gestion"] ?? "")?></td>
              <td>
                <div><?=h($r["Fase"] ?? "")?></div>
                <div class="muted"><?=h($r["Banda"] ?? "")?></div>
              </td>
              <td>$<?=h(number_format($saldo,2))?></td>
              <td>
                <div><b><?=h($r["Resultado"] ?? "")?></b></div>
                <div class="muted"><?=h($r["Fecha Ultima Gestion"] ?? "")?> — <?=h($r["Tipo de Gestion"] ?? "")?></div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="footer">
      Fuente JSON: <?=h($jsonUrl)?> (consumo en tiempo real). Reglas de urgencia son configurables en <code>urgencyLevel()</code>.
    </div>
  </div>
</body>
</html>
