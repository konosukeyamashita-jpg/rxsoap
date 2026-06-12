<?php
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';
if ($user !== 'rxsoap' || $pass !== 'medask2024') {
    header('WWW-Authenticate: Basic realm="rxsoap logs"');
    header('HTTP/1.0 401 Unauthorized');
    echo '認証が必要です';
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=mysql3115.db.sakura.ne.jp;dbname=medask-clinic_rxscan_product;charset=utf8mb4',
        'medask-clinic_rxscan_product',
        '0Np5ZorT9jsAQcw09vXNbBaR_x8zs-As',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die('DB接続エラー: ' . htmlspecialchars($e->getMessage()));
}

$logs = $pdo->query("SELECT * FROM rxsoap_logs ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

$total   = (int)$pdo->query("SELECT COUNT(*) FROM rxsoap_logs")->fetchColumn();
$errors  = (int)$pdo->query("SELECT COUNT(*) FROM rxsoap_logs WHERE error_message IS NOT NULL")->fetchColumn();
$avgMs   = (int)$pdo->query("SELECT AVG(processing_time_ms) FROM rxsoap_logs WHERE processing_time_ms IS NOT NULL")->fetchColumn();

$visitLabels = ['shinsin' => '初診', 'saishin' => '再診', 'referral' => '紹介状'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>rxsoap ログ</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 13px; background: #f5f4f0; color: #1a1a1a; padding: 24px; }
  h1 { font-size: 18px; font-weight: 600; margin-bottom: 16px; }
  .summary { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
  .summary-card { background: #fff; border: 0.5px solid rgba(0,0,0,0.12); border-radius: 8px; padding: 12px 20px; min-width: 160px; }
  .summary-card .label { font-size: 11px; color: #888; margin-bottom: 4px; }
  .summary-card .value { font-size: 22px; font-weight: 600; }
  .summary-card.error .value { color: #e53935; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; border: 0.5px solid rgba(0,0,0,0.12); }
  th { background: #1a1a1a; color: #fff; text-align: left; padding: 8px 10px; font-size: 11px; font-weight: 600; letter-spacing: 0.05em; white-space: nowrap; }
  td { padding: 7px 10px; border-bottom: 0.5px solid #f0f0f0; vertical-align: top; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  tr:last-child td { border-bottom: none; }
  tr.row-error td { background: #fdecea; }
  tr.row-slow td { background: #fff8e1; }
  tr.row-error.row-slow td { background: #fdecea; }
  .badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; }
  .badge-ok { background: #e6f7f2; color: #1a9e7a; }
  .badge-err { background: #fdecea; color: #e53935; }
  .badge-warn { background: #fff8e1; color: #f59f00; }
  .err-cell { color: #e53935; font-size: 11px; max-width: 200px; }
  .time-cell { font-family: monospace; }
</style>
</head>
<body>
<h1>rxsoap ログ管理</h1>

<div class="summary">
  <div class="summary-card">
    <div class="label">総件数</div>
    <div class="value"><?= number_format($total) ?></div>
  </div>
  <div class="summary-card error">
    <div class="label">エラー件数</div>
    <div class="value"><?= number_format($errors) ?></div>
  </div>
  <div class="summary-card">
    <div class="label">平均処理時間</div>
    <div class="value"><?= number_format($avgMs) ?> <span style="font-size:13px;font-weight:400;color:#888;">ms</span></div>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>日時</th>
      <th>診察タイプ</th>
      <th>Whisper</th>
      <th>SOAP</th>
      <th>処理時間(ms)</th>
      <th>エラー</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($logs as $row):
    $hasError = $row['error_message'] !== null;
    $isSlow   = $row['processing_time_ms'] !== null && $row['processing_time_ms'] >= 30000;
    $rowClass = ($hasError ? 'row-error ' : '') . ($isSlow ? 'row-slow' : '');
    $whisperBadge = $row['whisper_status'] === null ? '' :
        ($row['whisper_status'] == 200
            ? '<span class="badge badge-ok">200</span>'
            : '<span class="badge badge-err">' . (int)$row['whisper_status'] . '</span>');
    $soapBadge = $row['soap_status'] === null ? '' :
        ($row['soap_status'] == 200
            ? '<span class="badge badge-ok">200</span>'
            : '<span class="badge badge-err">' . (int)$row['soap_status'] . '</span>');
    $msClass = $isSlow ? 'time-cell badge badge-warn' : 'time-cell';
  ?>
    <tr class="<?= htmlspecialchars($rowClass) ?>">
      <td><?= (int)$row['id'] ?></td>
      <td style="white-space:nowrap"><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
      <td><?= htmlspecialchars($visitLabels[$row['visit_type']] ?? $row['visit_type'] ?? '') ?></td>
      <td><?= $whisperBadge ?></td>
      <td><?= $soapBadge ?></td>
      <td><span class="<?= $msClass ?>"><?= $row['processing_time_ms'] !== null ? number_format((int)$row['processing_time_ms']) : '' ?></span></td>
      <td class="err-cell" title="<?= htmlspecialchars($row['error_message'] ?? '') ?>"><?= htmlspecialchars(mb_strimwidth($row['error_message'] ?? '', 0, 60, '…')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
