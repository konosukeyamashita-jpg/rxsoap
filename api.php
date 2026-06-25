<?php
// 実行時間・メモリ制限の引き上げ（長い音声処理対応）
@ini_set('max_execution_time', '300');
@set_time_limit(300);
@ini_set('memory_limit', '512M');
@ini_set('post_max_size', '64M');
@ini_set('upload_max_filesize', '64M');

function maskPersonalInfo($transcript, $apiKey) {
    $maskPrompt = "以下のテキストから個人情報を検出し、マスキングしてください。\n\n"
        . "【マスキング対象】\n"
        . "・患者氏名 → 【氏名】\n"
        . "・生年月日・年齢 → 【生年月日】または【年齢】\n"
        . "・住所 → 【住所】\n"
        . "・電話番号 → 【電話番号】\n"
        . "・保険証番号・診察券番号 → 【ID番号】\n\n"
        . "【ルール】\n"
        . "・医療情報（病名・症状・処方・検査値）はマスキングしない\n"
        . "・マスキング済みテキストのみを返す（説明文不要）\n"
        . "・マスキング対象がない場合はそのまま返す\n\n"
        . "【テキスト】\n"
        . $transcript;

    $payload = json_encode([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 1000,
        'messages' => [['role' => 'user', 'content' => $maskPrompt]]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? $transcript;
}

function writeLog($pdo, $data) {
    if ($pdo === null) return;
    $sql = "INSERT INTO rxsoap_logs
        (visit_type, audio_type, audio_size, whisper_status,
         whisper_transcript, masked_transcript, soap_status,
         soap_response, error_message, processing_time_ms,
         whisper_time_ms, mask_time_ms, soap_time_ms)
        VALUES
        (:visit_type, :audio_type, :audio_size, :whisper_status,
         :whisper_transcript, :masked_transcript, :soap_status,
         :soap_response, :error_message, :processing_time_ms,
         :whisper_time_ms, :mask_time_ms, :soap_time_ms)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

try {
    $pdo = new PDO(
        'mysql:host=mysql3115.db.sakura.ne.jp;dbname=medask-clinic_rxscan_product;charset=utf8mb4',
        'medask-clinic_rxscan_product',
        '0Np5ZorT9jsAQcw09vXNbBaR_x8zs-As',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    // DB接続失敗してもSOAP処理は継続する
    $pdo = null;
}
$startTime = microtime(true);

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
$visitType = $input['visitType'] ?? 'shinsin';
$transcribeOnly = !empty($input['transcribeOnly']);
$soapOnly = !empty($input['soapOnly']);

$logData = [
    'visit_type' => $visitType,
    'audio_type' => $audioType ?? 'text',
    'audio_size' => strlen($audioData ?? ''),
    'whisper_status' => null,
    'whisper_transcript' => null,
    'masked_transcript' => null,
    'soap_status' => null,
    'soap_response' => null,
    'error_message' => null,
    'processing_time_ms' => null,
    'whisper_time_ms' => null,
    'mask_time_ms' => null,
    'soap_time_ms' => null,
];

if (empty($transcript) && empty($audioData)) {
    http_response_code(400);
    $logData['error_message'] = 'transcript/audio_data missing';
    $logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
    writeLog($pdo, $logData);
    echo json_encode(['error' => '書き起こしテキストまたは音声データが必要です']);
    exit;
}

if (!empty($audioData)) {
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

    // ② Whisper APIで文字起こし（multipart/form-dataを手動で構築）
    $whisperFileName = 'audio.' . $ext;
    $whisperMime     = 'audio/mp4';
    if (strpos($audioType, 'mp3') !== false)       { $whisperFileName = 'audio.mp3';  $whisperMime = 'audio/mpeg'; }
    elseif (strpos($audioType, 'wav') !== false)   { $whisperFileName = 'audio.wav';  $whisperMime = 'audio/wav'; }
    elseif (strpos($audioType, 'webm') !== false)  { $whisperFileName = 'audio.webm'; $whisperMime = 'audio/webm'; }

    $whisperPrompt = "医療クリニックの診察会話です。以下の専門用語が含まれます：高血圧、糖尿病、脂質異常症、心房細動、心不全、狭心症、心筋梗塞、気管支喘息、胃潰瘍、逆流性食道炎、慢性腎臓病、甲状腺機能亢進症、HbA1c、eGFR、子宮頸癌、子宮体癌、卵巣嚢腫、子宮筋腫、子宮内膜症、月経困難症、不正出血、妊娠週数、流産、切迫流産、HCG、経膣超音波、コルポスコピー、HPV、ピル、IUD、ミレーナ、更年期障害、変形性膝関節症、腰椎椎間板ヘルニア、脊柱管狭窄症、骨粗鬆症、半月板損傷、前十字靭帯、腱板断裂、RSウイルス、溶連菌、川崎病、アトピー性皮膚炎、白癬、帯状疱疹、前立腺肥大症、前立腺癌、PSA、過活動膀胱、副鼻腔炎、アレルギー性鼻炎、突発性難聴、メニエール病、緑内障、白内障、糖尿病網膜症、加齢黄斑変性、うつ病、双極性障害、統合失調症、パニック障害、ADHD、SSRI、不眠症";

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
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"prompt\"\r\n\r\n"
        . $whisperPrompt . "\r\n"
        . "--{$boundary}--\r\n";

    $whisperStart = microtime(true);
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
    $logData['whisper_time_ms'] = round((microtime(true) - $whisperStart) * 1000);

    if ($whisperHttpCode !== 200) {
        http_response_code(502);
        $logData['whisper_status'] = $whisperHttpCode;
        $logData['error_message'] = 'Whisper API エラー: HTTP ' . $whisperHttpCode;
        $logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
        writeLog($pdo, $logData);
        echo json_encode([
            'error'         => 'Whisper API エラー: HTTP ' . $whisperHttpCode,
            'response_body' => substr($whisperResponse, 0, 1000),
            'curl_error'    => $whisperCurlErr,
            'audio_type'    => $audioType,
            'ext'           => $ext,
            'file_exists'   => file_exists($tmpFile) ? 'yes' : 'no (already deleted)',
        ]);
        exit;
    }

    // ③ 一時ファイルを削除
    unlink($tmpFile);

    $whisperData = json_decode($whisperResponse, true);
    $transcript  = $whisperData['text'] ?? '';

    if (empty($transcript)) {
        http_response_code(500);
        $logData['whisper_status'] = $whisperHttpCode;
        $logData['error_message'] = '音声の文字起こしに失敗しました';
        $logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
        writeLog($pdo, $logData);
        echo json_encode(['error' => '音声の文字起こしに失敗しました']);
        exit;
    }

    $logData['whisper_status'] = $whisperHttpCode;
    $logData['whisper_transcript'] = $transcript;

    if ($transcribeOnly) {
        $logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
        writeLog($pdo, $logData);
        echo json_encode(['transcript' => $transcript]);
        exit;
    }

    $maskStart = microtime(true);
    $transcript = maskPersonalInfo($transcript, $apiKey);
    $logData['mask_time_ms'] = round((microtime(true) - $maskStart) * 1000);
    $logData['masked_transcript'] = $transcript;
}

if ($soapOnly) {
    $maskStart = microtime(true);
    $transcript = maskPersonalInfo($transcript, $apiKey);
    $logData['mask_time_ms'] = round((microtime(true) - $maskStart) * 1000);
    $logData['masked_transcript'] = $transcript;
}

// ④ transcriptをClaudeに渡して生成
if ($visitType === 'referral') {
    $referralPrompt = <<<PROMPT
あなたは医療クリニックの医師です。
以下の診察音声の書き起こしから紹介状（診療情報提供書）の下書きを作成してください。

【書き起こし】
{$transcript}

【出力形式】
以下のフォーマットで出力してください：

拝啓

貴院ますますご清祥のこととお慶び申し上げます。
下記の患者様をご紹介申し上げます。ご高診のほどよろしくお願い申し上げます。

【患者情報】
氏名：（音声から取得、不明の場合は「____」）
生年月日：（音声から取得、不明の場合は「____」）

【紹介目的】
（紹介の理由・目的を簡潔に）

【現病歴・経過】
（診察内容から経過を記載）

【既往歴】
（音声から取得、不明の場合は「特記事項なし」）

【内服薬】
（音声から取得、不明の場合は「なし」）

【検査結果・所見】
（音声から取得した検査値・所見、不明の場合は「添付資料参照」）

【お願い事項】
（紹介先へのお願い内容）

敬具

担当医：（音声から取得、不明の場合は「____」）

カルテ本文のみを出力してください。説明文・前置き・後書きは不要です。
PROMPT;

    $messages = [['role' => 'user', 'content' => $referralPrompt]];
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 2000,
        'messages'   => $messages
    ]);

    $soapStart = microtime(true);
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
    $logData['soap_time_ms'] = round((microtime(true) - $soapStart) * 1000);

    if ($httpCode !== 200) {
        http_response_code(502);
        $logData['soap_status'] = $httpCode;
        $logData['error_message'] = 'Claude API エラー: HTTP ' . $httpCode;
        $logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
        writeLog($pdo, $logData);
        echo json_encode([
            'error'         => 'Claude API エラー: HTTP ' . $httpCode,
            'response_body' => substr($response, 0, 1000),
            'curl_error'    => $curlError
        ]);
        exit;
    }

    $apiData = json_decode($response, true);
    $referralContent = $apiData['content'][0]['text'] ?? '';
    $logData['soap_status'] = $httpCode;
    $logData['soap_response'] = substr($referralContent, 0, 2000);
    $logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
    writeLog($pdo, $logData);
    echo json_encode(['referralText' => $referralContent, 'transcript' => $transcript], JSON_UNESCAPED_UNICODE);
    exit;
}

$visitInstruction = $visitType === 'saishin'
    ? "再診患者のカルテです。以下をコンパクトにまとめてください：\n前回からの経過・本日の訴え・処方変更・次回予定\nS/O/A/Pは簡潔に箇条書き2〜3項目程度に収めること"
    : "以下の項目を含めてください：\n【受診契機】【主訴】【現病歴】【既往歴】【家族歴】【アレルギー】【常用薬】\nS/O/A/Pの各項目を詳細に記載すること";

$prompt = <<<PROMPT
あなたは医療クリニックの医療記録専門家です。
以下の診察音声の書き起こしを読み、SOAP形式でカルテを作成してください。

{$visitInstruction}

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
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 2000,
    'messages'   => $messages
]);

$soapStart = microtime(true);
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
$logData['soap_time_ms'] = round((microtime(true) - $soapStart) * 1000);

if ($httpCode !== 200) {
    http_response_code(502);
    $logData['soap_status'] = $httpCode;
    $logData['error_message'] = 'Claude API エラー: HTTP ' . $httpCode;
    $logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
    writeLog($pdo, $logData);
    echo json_encode([
        'error'         => 'Claude API エラー: HTTP ' . $httpCode,
        'response_body' => substr($response, 0, 1000),
        'curl_error'    => $curlError
    ]);
    exit;
}

$apiData = json_decode($response, true);
$text = $apiData['content'][0]['text'] ?? '';

// マークダウンのコードフェンスを除去
$text = trim(preg_replace('/```json|```/', '', $text));

// 最初の { から最後の } までを抽出（前後の余計なテキストを除去）
$start = strpos($text, '{');
$end = strrpos($text, '}');
if ($start !== false && $end !== false && $end > $start) {
    $text = substr($text, $start, $end - $start + 1);
}

$soap = json_decode($text, true);

if (!$soap || !isset($soap['S'])) {
    http_response_code(500);
    $logData['soap_status'] = $httpCode;
    $logData['soap_response'] = substr($text, 0, 2000);
    $logData['error_message'] = 'SOAPのパースに失敗しました';
    $logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
    writeLog($pdo, $logData);
    echo json_encode([
        'error'      => 'SOAPのパースに失敗しました',
        'debug_text' => substr($text, 0, 500),
        'json_error' => json_last_error_msg()
    ]);
    exit;
}

$logData['soap_status'] = $httpCode;
$logData['soap_response'] = substr($text, 0, 2000);
$logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
writeLog($pdo, $logData);
echo json_encode([
    'S'          => $soap['S'],
    'O'          => $soap['O'],
    'A'          => $soap['A'],
    'P'          => $soap['P'],
    'transcript' => $transcript,
], JSON_UNESCAPED_UNICODE);
