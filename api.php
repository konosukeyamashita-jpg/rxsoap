<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_log("rxsoap api.php called");
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// .envからAPIキーを読み込む
$env = parse_ini_file(__DIR__ . '/.env');
$apiKey = $env['ANTHROPIC_API_KEY'] ?? '';

if (empty($apiKey)) {
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

if (!empty($audioData)) {
    error_log("audio_type: " . $audioType . " audio_data length: " . strlen($audioData));
    $audioPrompt = <<<APROMPT
あなたは婦人科専門クリニックの医療記録専門家です。
添付の音声ファイルの診察内容をSOAP形式でカルテにまとめてください。

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
・音声認識の誤認識は文脈から正しい医療用語に修正すること
・カルテ本文のみを出力すること。説明文・前置き・後書きは一切不要
・情報がない項目は「情報なし」と記載すること
APROMPT;

    $messages = [[
        'role'    => 'user',
        'content' => [
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $audioType, 'data' => $audioData]],
            ['type' => 'text',     'text'   => $audioPrompt]
        ]
    ]];
} else {
    $messages = [['role' => 'user', 'content' => $prompt]];
}

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

if (!empty($audioData)) {
    echo json_encode(['soapText' => $text], JSON_UNESCAPED_UNICODE);
    exit;
}

$soap = json_decode($text, true);

if (!$soap || !isset($soap['S'])) {
    http_response_code(500);
    echo json_encode(['error' => 'SOAPのパースに失敗しました']);
    exit;
}

echo json_encode($soap, JSON_UNESCAPED_UNICODE);
