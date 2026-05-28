<?php
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

if (empty($transcript)) {
    http_response_code(400);
    echo json_encode(['error' => '書き起こしテキストが空です']);
    exit;
}

$prompt = <<<PROMPT
あなたは医療記録の専門家です。
以下の診察音声の書き起こしを読み、SOAP形式でカルテを作成してください。

【書き起こし】
{$transcript}

【出力形式】
必ず以下のJSON形式のみで返答してください。説明文・前置き・マークダウンは一切不要です。
{
  "S": ["主観的情報の箇条書き（患者の訴え・症状）"],
  "O": ["客観的情報の箇条書き（所見・検査値）"],
  "A": ["評価・診断の箇条書き"],
  "P": ["治療計画・指示の箇条書き"]
}

各項目は2〜5個の箇条書きにまとめてください。情報がない項目は「情報なし」と1つだけ入れてください。
PROMPT;

$payload = json_encode([
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => 1000,
    'messages'   => [
        ['role' => 'user', 'content' => $prompt]
    ]
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
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Claude API エラー: HTTP ' . $httpCode]);
    exit;
}

$apiData = json_decode($response, true);
$text = $apiData['content'][0]['text'] ?? '';

$text = preg_replace('/```json|```/', '', $text);
$soap = json_decode(trim($text), true);

if (!$soap || !isset($soap['S'])) {
    http_response_code(500);
    echo json_encode(['error' => 'SOAPのパースに失敗しました']);
    exit;
}

echo json_encode($soap, JSON_UNESCAPED_UNICODE);
