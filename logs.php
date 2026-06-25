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

function explainError($row) {
    if (!$row['error_message']) {
        if ($row['processing_time_ms'] > 60000) {
            return '⚠️ 処理に60秒以上かかりました。通常の診察音声であれば問題ありませんが、極端に長い場合は録音を分けることをおすすめします。';
        }
        if ($row['processing_time_ms'] > 30000) {
            return '✅ 正常（やや長め: ' . round($row['processing_time_ms']/1000) . '秒）';
        }
        return '✅ 正常';
    }
    $err = $row['error_message'];
    if (strpos($err, 'タイムアウト') !== false || strpos($err, 'timeout') !== false) {
        return '⏱️ タイムアウト：音声が長すぎました。2〜3分以内に分けて録音してください。';
    }
    if (strpos($err, 'Whisper') !== false) {
        return '🎤 音声認識エラー：音声ファイルの形式が対応していないか、音声が短すぎます。mp3またはwav形式をお試しください。';
    }
    if ($row['whisper_status'] === null) {
        return '🎤 音声認識開始前にエラー：マイクへのアクセスが拒否されたか、音声データが空です。';
    }
    if ($row['whisper_transcript'] && !$row['soap_status']) {
        return '📝 SOAP生成エラー：音声認識は成功しましたが、カルテ生成に失敗しました。再度お試しください。';
    }
    if ($row['whisper_status'] == 400) {
        return '🎤 音声形式エラー：このファイル形式は対応していません。mp3またはwav形式をお試しください。';
    }
    if ($row['whisper_status'] == 429 || $row['soap_status'] == 429) {
        return '🚦 アクセス集中：しばらく待ってから再度お試しください。';
    }
    if ($row['soap_status'] == 500 || $row['soap_status'] == 502) {
        return '⚙️ サーバーエラー：一時的な問題が発生しました。しばらく待ってから再度お試しください。';
    }
    return '❓ 不明なエラー：開発者にお問い合わせください。';
}
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

<div style="background:#e8f4fd; border-radius:8px; padding:16px; margin-bottom:20px; font-size:14px;">
  <strong>📋 このページの使い方</strong><br><br>
  録音やSOAP生成がうまくいかなかった場合、このページで原因を確認できます。<br>
  「状況・対処法」列に原因と次回への対処方法が表示されます。<br>
  <span style="color:#e53935;">赤い行</span>はエラーが発生したケース、
  <span style="color:#f59f00;">黄色い行</span>は処理に時間がかかったケースです。
</div>

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
      <th>状況・対処法</th>
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
      <td style="white-space:normal; font-size:12px;"><?= htmlspecialchars(explainError($row)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
