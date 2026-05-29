<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');
error_log("rxsoap api.php called");
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// APIキーはGitHub Actionsでデプロイ時に置換される
$apiKey = '__ANTHROPIC_API_KEY__';
$openaiApiKey = '__OPENAI_API_KEY__';

if (empty($apiKey) || $apiKey === '__ANTHROPIC_API_KEY__') {
    http_response_code(500);
    echo json_encode(['error' => 'APIキーが設定されていません']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$transcript = trim($input['transcript'] ?? '');
$audioData = $input['audio_data'] ?? '';
$audioType = $input['audio_type'] ?? 'audio/mp4';

if (empty($transcript) && empty($audioData)) {
    http_response_code(400);
    echo json_encode(['error' => '書き起こしテキストまたは音声データが必要です']);
    exit;
}

if (!empty($audioData)) {
    error_log("audio_type: " . $audioType . " audio_data length: " . strlen($audioData));

    // ① base64デコードして一時ファイルに保存
    $ext = 'm4a';
    if (strpos($audioType, 'mp3') !== false) $ext = 'mp3';
    elseif (strpos($audioType, 'mp4') !== false) $ext = 'mp4';
    elseif (strpos($audioType, 'wav') !== false) $ext = 'wav';
    elseif (strpos($audioType, 'ogg') !== false) $ext = 'ogg';
    elseif (strpos($audioType, 'webm') !== false) $ext = 'webm';

    // x-m4aはmp4として処理
    $tmpExt = $ext;
    if ($audioType === 'audio/x-m4a' || $audioType === 'audio/m4a') {
        $tmpExt = 'mp4';
    }
    $tmpFile = tempnam(sys_get_temp_dir(), 'audio_') . '.' . $tmpExt;
    file_put_contents($tmpFile, base64_decode($audioData));
    error_log("tmpFile: " . $tmpFile . " ext: " . $ext . " audioType: " . $audioType . " size: " . filesize($tmpFile));

    // ② Whisper APIで文字起こし（multipart/form-dataを手動で構築）
    $whisperFileName = 'audio.' . $ext;
    $whisperMime     = 'audio/mp4';
    if (strpos($audioType, 'mp3') !== false)       { $whisperFileName = 'audio.mp3';  $whisperMime = 'audio/mpeg'; }
    elseif (strpos($audioType, 'wav') !== false)   { $whisperFileName = 'audio.wav';  $whisperMime = 'audio/wav'; }
    elseif (strpos($audioType, 'webm') !== false)  { $whisperFileName = 'audio.webm'; $whisperMime = 'audio/webm'; }

    $boundary     = uniqid();
    $fileContents = file_get_contents($tmpFile);
    $body = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"file\"; filename=\"{$whisperFileName}\"\r\n"
        . "Content-Type: {$whisperMime}\r\n\r\n"
        . $fileContents . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"model\"\r\n\r\n"
        . "whisper-1\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"language\"\r\n\r\n"
        . "ja\r\n"
        . "--{$boundary}--\r\n";

    $whisperCh = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($whisperCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $openaiApiKey,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $whisperResponse = curl_exec($whisperCh);
    $whisperHttpCode = curl_getinfo($whisperCh, CURLINFO_HTTP_CODE);
    $whisperCurlErr  = curl_error($whisperCh);
    curl_close($whisperCh);

    error_log("whisper http: " . $whisperHttpCode . " response: " . substr($whisperResponse, 0, 200));

    if ($whisperHttpCode !== 200) {
        http_response_code(502);
        echo json_encode([
            'error'            => 'Whisper API エラー: HTTP ' . $whisperHttpCode,
            'response_body'    => substr($whisperResponse, 0, 1000),
            'curl_error'       => $whisperCurlErr,
            'audio_type'       => $audioType,
            'audio_data_size'  => strlen($audioData),
            'file_size'        => strlen(base64_decode($audioData)),
            'ext'              => $ext,
            'whisper_filename' => $whisperFileName,
            'whisper_mime'     => $whisperMime,
            'file_exists'      => file_exists($tmpFile) ? 'yes' : 'no (already deleted)',
        ]);
        exit;
    }

    // ③ 一時ファイルを削除
    unlink($tmpFile);

    $whisperData = json_decode($whisperResponse, true);
    $transcript  = $whisperData['text'] ?? '';

    if (empty($transcript)) {
        http_response_code(500);
        echo json_encode(['error' => '音声の文字起こしに失敗しました']);
        exit;
    }
}

// ④ transcriptをClaudeに渡してSOAP生成
$prompt = <<<PROMPT
あなたは婦人科専門クリニックの医療記録専門家です。
以下の診察音声の書き起こしを読み、SOAP形式でカルテを作成してください。

【書き起こし】
{$transcript}

【出力形式】
S（主訴・現病歴）：患者の訴え、症状の経過、既往歴など主観的情報
O（所見・検査）：医師が確認した客観的所見、検査結果、バイタル等
A（評価・アセスメント）：医師の診断・鑑別診断・病態評価
P（計画）：以下の4項目を必ず含めること
  ・処方内容（薬剤名・用量・用法・日数）
  ・処置内容（当日実施した検査・注射等）
  ・次回受診（時期・条件・確認事項を具体的に）
  ・患者指導（生活指導、検診指示、注意事項）

【注意事項】
・医療用語は正式名称で記載（略語は初出時にフルスペル併記）
・処方内容は薬剤名、用量、用法を具体的に記載
・会話の全体を通じて漏れなく情報を拾うこと（特に会話終盤のフォロー指示を見落とさない）
・会話中に明示されていない情報を補完する場合は【推定】と明記すること
・音声認識の誤認識（例：「子宮眼鏡試験」→「子宮頸癌検診」）は文脈から正しい医療用語に修正すること
・カルテ本文のみを出力すること。説明文・前置き・後書きは一切不要
・情報がない項目は「情報なし」と記載すること

必ず以下のJSON形式のみで返答してください。説明文・前置き・マークダウンは一切不要です。
{
  "S": ["主訴・現病歴の箇条書き"],
  "O": ["所見・検査の箇条書き"],
  "A": ["評価・アセスメントの箇条書き"],
  "P": ["処方内容", "処置内容", "次回受診", "患者指導"]
}
PROMPT;

$messages = [['role' => 'user', 'content' => $prompt]];

$payload = json_encode([
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => 2000,
    'messages'   => $messages
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);
error_log("http_code: " . $httpCode . " response: " . substr($response, 0, 500));

if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode([
        'error'         => 'Claude API エラー: HTTP ' . $httpCode,
        'response_body' => substr($response, 0, 1000),
        'audio_type'    => $audioType,
        'audio_size'    => strlen($audioData),
        'curl_error'    => $curlError
    ]);
    exit;
}

$apiData = json_decode($response, true);
$text = $apiData['content'][0]['text'] ?? '';

$text = trim(preg_replace('/```json|```/', '', $text));

$soap = json_decode($text, true);

if (!$soap || !isset($soap['S'])) {
    http_response_code(500);
    echo json_encode(['error' => 'SOAPのパースに失敗しました']);
    exit;
}

echo json_encode([
    'S'          => $soap['S'],
    'O'          => $soap['O'],
    'A'          => $soap['A'],
    'P'          => $soap['P'],
    'transcript' => $transcript
], JSON_UNESCAPED_UNICODE);
