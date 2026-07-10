<?php
declare(strict_types=1);
session_start();

$configPath = __DIR__ . '/config.php';
$config = file_exists($configPath) ? require $configPath : [];
$apiKey = trim((string)($config['fish_api_key'] ?? getenv('FISH_API_KEY') ?: ''));
$appPassword = (string)($config['app_password'] ?? getenv('APP_PASSWORD') ?: '');

$outputDir = __DIR__ . '/outputs';
$outputUrlBase = 'outputs';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

$defaultVoicePresets = [
    'Русские голоса' => [
        ['name' => 'RU — женский спокойный / аудиокнига', 'id' => '2a1036d645634680b3cc69aeeb60375b'],
        ['name' => 'RU — мужской рассказчик / объясняющие ролики', 'id' => '6ccdfb6b21e14501adc1fbcb44e50082'],
        ['name' => 'RU — молодой аналитик / обучающий стиль', 'id' => '868377a7b08f4c0d9acf8c9f059571aa'],
        ['name' => 'RU — Ирина Воробьева / объявления', 'id' => 'ecb6af88068f4e4dbba75eac7786bebc'],
    ],
    'Английские голоса' => [
        ['name' => 'EN — Adrian / male narrator', 'id' => 'bf322df2096a46f18c579d0baa36f41d'],
        ['name' => 'EN — Selene / calm female', 'id' => 'b347db033a6549378b48d00acb0d06cd'],
        ['name' => 'EN — Sarah / conversational female', 'id' => '933563129e564b19a115bedd57b7406a'],
        ['name' => 'EN — Ethan / male explainer', 'id' => '536d3a5e000945adb7038665781a4aca'],
    ],
];
$voicePresets = isset($config['voice_presets']) && is_array($config['voice_presets']) && $config['voice_presets'] !== []
    ? $config['voice_presets']
    : $defaultVoicePresets;

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_string(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function post_float(string $key, float $default, float $min, float $max): float {
    $raw = str_replace(',', '.', post_string($key, (string)$default));
    $value = is_numeric($raw) ? (float)$raw : $default;
    return max($min, min($max, $value));
}

function post_int(string $key, int $default, int $min, int $max): int {
    $raw = post_string($key, (string)$default);
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function voice_id_exists(array $voicePresets, string $voiceId): bool {
    foreach ($voicePresets as $voices) {
        if (!is_array($voices)) {
            continue;
        }
        foreach ($voices as $voice) {
            if (is_array($voice) && (string)($voice['id'] ?? '') === $voiceId) {
                return true;
            }
        }
    }
    return false;
}

function render_voice_options(array $voicePresets, string $selected, bool $includeDefault = true): string {
    $html = '';
    if ($includeDefault) {
        $html .= '<option value=""' . ($selected === '' ? ' selected' : '') . '>Голос по умолчанию</option>';
    }
    foreach ($voicePresets as $groupName => $voices) {
        if (!is_array($voices)) {
            continue;
        }
        $html .= '<optgroup label="' . h((string)$groupName) . '">';
        foreach ($voices as $voice) {
            if (!is_array($voice)) {
                continue;
            }
            $voiceId = (string)($voice['id'] ?? '');
            $voiceName = (string)($voice['name'] ?? $voiceId);
            if ($voiceId === '') {
                continue;
            }
            $html .= '<option value="' . h($voiceId) . '"' . ($selected === $voiceId ? ' selected' : '') . '>' . h($voiceName) . '</option>';
        }
        $html .= '</optgroup>';
    }
    $html .= '<option value="__custom__"' . ($selected === '__custom__' ? ' selected' : '') . '>Свой Voice ID…</option>';
    return $html;
}

function resolve_reference_id(array $voicePresets, string $presetField, string $customField, array &$errors, bool $required = false): string {
    $preset = post_string($presetField, '');
    $custom = post_string($customField, '');
    if ($preset !== '' && $preset !== '__custom__') {
        if (voice_id_exists($voicePresets, $preset)) {
            return $preset;
        }
        $errors[] = 'Выбранный голос не найден в списке пресетов.';
        return '';
    }
    if ($preset === '__custom__') {
        if ($custom === '' && $required) {
            $errors[] = 'Укажите свой Voice ID или выберите голос из списка.';
        }
        return $custom;
    }
    if ($required) {
        $errors[] = 'Выберите голос.';
    }
    return '';
}

function allowed_value(string $value, array $allowed, string $default): string {
    return in_array($value, $allowed, true) ? $value : $default;
}

function build_tts_payload(string $text, $referenceId, string $format, string $latency, float $temperature, float $topP, float $speed, float $volume, int $chunkLength, int $mp3Bitrate): array {
    $payload = [
        'text' => $text,
        'temperature' => $temperature,
        'top_p' => $topP,
        'prosody' => [
            'speed' => $speed,
            'volume' => $volume,
            'normalize_loudness' => true,
        ],
        'chunk_length' => $chunkLength,
        'normalize' => true,
        'format' => $format,
        'latency' => $latency,
        'condition_on_previous_chunks' => true,
    ];
    if (is_array($referenceId)) {
        $ids = array_values(array_filter(array_map('strval', $referenceId), static fn($id) => trim($id) !== ''));
        if ($ids !== []) {
            $payload['reference_id'] = $ids;
        }
    } elseif ((string)$referenceId !== '') {
        $payload['reference_id'] = (string)$referenceId;
    }
    if ($format === 'mp3') {
        $payload['sample_rate'] = 44100;
        $payload['mp3_bitrate'] = $mp3Bitrate;
    }
    if ($format === 'wav') {
        $payload['sample_rate'] = 44100;
    }
    return $payload;
}

function fish_tts_request(string $apiKey, string $model, array $payload): array {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init('https://api.fish.audio/v1/tts');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'model: ' . $model,
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 300,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return ['ok' => false, 'error' => 'Ошибка cURL: ' . $curlError, 'data' => null];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $decoded = json_decode((string)$response, true);
        $message = is_array($decoded) ? ($decoded['message'] ?? json_encode($decoded, JSON_UNESCAPED_UNICODE)) : (string)$response;
        return ['ok' => false, 'error' => 'Fish Audio вернул HTTP ' . $httpCode . ': ' . $message, 'data' => null];
    }
    return ['ok' => true, 'error' => '', 'data' => (string)$response];
}

function parse_dialogue_script(string $raw): array {
    $lines = preg_split('/\R/u', $raw) ?: [];
    $items = [];
    $lineNumber = 0;
    foreach ($lines as $line) {
        $lineNumber++;
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^(М|МУЖ|МУЖЧИНА|MALE|MAN|M|A|1)\s*[:：\-—]\s*(.+)$/iu', $line, $m)) {
            $speaker = 'male';
            $text = trim((string)$m[2]);
        } elseif (preg_match('/^(Ж|ЖЕН|ЖЕНЩИНА|FEMALE|WOMAN|F|B|2)\s*[:：\-—]\s*(.+)$/iu', $line, $m)) {
            $speaker = 'female';
            $text = trim((string)$m[2]);
        } else {
            return ['ok' => false, 'error' => 'Строка ' . $lineNumber . ' без роли. Начните её с М: или Ж:', 'items' => []];
        }

        if ($text === '') {
            continue;
        }

        $lastIndex = count($items) - 1;
        if ($lastIndex >= 0 && $items[$lastIndex]['speaker'] === $speaker) {
            $items[$lastIndex]['text'] .= "\n" . $text;
        } else {
            $items[] = ['speaker' => $speaker, 'text' => $text];
        }
    }

    if ($items === []) {
        return ['ok' => false, 'error' => 'Вставьте диалог в формате М: текст / Ж: текст.', 'items' => []];
    }

    return ['ok' => true, 'error' => '', 'items' => $items];
}

function dialogue_to_fish_speaker_text(array $items): string {
    $parts = [];
    foreach ($items as $item) {
        $speaker = (string)$item['speaker'];
        $tag = $speaker === 'male' ? '<|speaker:0|>' : '<|speaker:1|>';
        $parts[] = $tag . trim((string)$item['text']);
    }
    return implode("\n", $parts);
}

function wav_parse(string $bytes): array {
    if (strlen($bytes) < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
        $head = bin2hex(substr($bytes, 0, 24));
        return ['ok' => false, 'error' => 'Файл не похож на WAV/RIFF. Первые байты: ' . $head, 'fmt' => '', 'data' => ''];
    }

    $pos = 12;
    $len = strlen($bytes);
    $fmt = null;
    $data = null;

    while ($pos + 8 <= $len) {
        $chunkId = substr($bytes, $pos, 4);
        $chunkSizeData = unpack('V', substr($bytes, $pos + 4, 4));
        $chunkSize = (int)($chunkSizeData[1] ?? 0);
        $chunkStart = $pos + 8;

        // У некоторых API/стриминговых ответов размер чанка может быть записан
        // некорректно или как «до конца файла». Не обрываем разбор сразу.
        $available = max(0, $len - $chunkStart);
        $safeChunkSize = min($chunkSize, $available);
        if ($safeChunkSize < 0) {
            break;
        }

        $chunkData = substr($bytes, $chunkStart, $safeChunkSize);
        if ($chunkId === 'fmt ') {
            $fmt = $chunkData;
        } elseif ($chunkId === 'data') {
            $data = $chunkData;
        }

        if ($chunkSize <= 0 || $chunkStart + $chunkSize > $len) {
            break;
        }
        $pos = $chunkStart + $chunkSize + ($chunkSize % 2);
    }

    // Резервный более мягкий разбор: ищем fmt/data как маркеры внутри RIFF.
    // Это спасает случаи, когда Fish/прокси отдал WAV с нестандартным размером чанков.
    if ($fmt === null) {
        $fmtPos = strpos($bytes, 'fmt ');
        if ($fmtPos !== false && $fmtPos + 8 <= $len) {
            $fmtSizeData = unpack('V', substr($bytes, $fmtPos + 4, 4));
            $fmtSize = (int)($fmtSizeData[1] ?? 16);
            $fmtStart = $fmtPos + 8;
            if ($fmtStart < $len) {
                $fmt = substr($bytes, $fmtStart, min(max(16, $fmtSize), $len - $fmtStart));
            }
        }
    }
    if ($data === null) {
        $dataPos = strpos($bytes, 'data');
        if ($dataPos !== false && $dataPos + 8 <= $len) {
            $dataSizeData = unpack('V', substr($bytes, $dataPos + 4, 4));
            $dataSize = (int)($dataSizeData[1] ?? 0);
            $dataStart = $dataPos + 8;
            if ($dataStart < $len) {
                $available = $len - $dataStart;
                $data = substr($bytes, $dataStart, $dataSize > 0 ? min($dataSize, $available) : $available);
            }
        }
    }

    if ($fmt === null || $data === null || $fmt === '' || $data === '') {
        $head = bin2hex(substr($bytes, 0, 32));
        return ['ok' => false, 'error' => 'В WAV не найдены fmt/data chunks. Первые байты: ' . $head, 'fmt' => '', 'data' => ''];
    }

    return ['ok' => true, 'error' => '', 'fmt' => $fmt, 'data' => $data];
}

function wav_concat(array $wavBytesList, int $pauseMs): array {
    $fmt = null;
    $data = '';
    $blockAlign = 2;
    $sampleRate = 44100;

    foreach ($wavBytesList as $i => $bytes) {
        $parsed = wav_parse($bytes);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => 'Реплика #' . ($i + 1) . ': ' . $parsed['error'], 'data' => ''];
        }
        if ($fmt === null) {
            $fmt = $parsed['fmt'];
            if (strlen($fmt) >= 16) {
                $unpacked = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($fmt, 0, 16));
                $sampleRate = (int)($unpacked['sampleRate'] ?? 44100);
                $blockAlign = max(1, (int)($unpacked['blockAlign'] ?? 2));
            }
        } elseif ($fmt !== $parsed['fmt']) {
            return ['ok' => false, 'error' => 'WAV-реплики имеют разные аудио-параметры. Попробуйте тот же формат/модель или включите native Fish-диалог.', 'data' => ''];
        }
        $data .= $parsed['data'];
        if ($pauseMs > 0 && $i < count($wavBytesList) - 1) {
            $pauseBytes = (int)round($sampleRate * ($pauseMs / 1000) * $blockAlign);
            $pauseBytes -= $pauseBytes % $blockAlign;
            $data .= str_repeat("\0", max(0, $pauseBytes));
        }
    }

    if ($fmt === null) {
        return ['ok' => false, 'error' => 'Нет WAV-данных для склейки.', 'data' => ''];
    }

    $fmtChunkSize = strlen($fmt);
    $dataChunkSize = strlen($data);
    $riffSize = 4 + (8 + $fmtChunkSize) + (8 + $dataChunkSize);
    $wav = 'RIFF' . pack('V', $riffSize) . 'WAVE'
        . 'fmt ' . pack('V', $fmtChunkSize) . $fmt
        . 'data' . pack('V', $dataChunkSize) . $data;

    return ['ok' => true, 'error' => '', 'data' => $wav];
}

function function_available(string $function): bool {
    if (!function_exists($function)) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array($function, $disabled, true);
}

function ffmpeg_available(): bool {
    if (!function_available('shell_exec')) {
        return false;
    }
    $result = @shell_exec('command -v ffmpeg 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

function convert_wav_to_mp3_if_possible(string $wavPath, string $mp3Path): bool {
    if (!function_available('shell_exec')) {
        return false;
    }
    $cmd = 'ffmpeg -y -hide_banner -loglevel error -i ' . escapeshellarg($wavPath) . ' -codec:a libmp3lame -b:a 128k ' . escapeshellarg($mp3Path) . ' 2>&1';
    @shell_exec($cmd);
    return file_exists($mp3Path) && filesize($mp3Path) > 1000;
}

function synthesize_segmented_dialogue(string $apiKey, string $model, array $items, string $maleReferenceId, string $femaleReferenceId, string $finalFormat, int $pauseMs, string $outputDir, string $outputUrlBase, string $latency, float $temperature, float $topP, float $speed, float $volume, int $chunkLength, int $mp3Bitrate): array {
    $wavSegments = [];
    $segmentLinks = [];
    $notes = [];
    $base = 'dialogue_segments_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

    foreach ($items as $index => $item) {
        $speaker = (string)$item['speaker'];
        $lineText = (string)$item['text'];
        $voiceId = $speaker === 'male' ? $maleReferenceId : $femaleReferenceId;
        $payload = build_tts_payload($lineText, $voiceId, 'wav', $latency, $temperature, $topP, $speed, $volume, $chunkLength, $mp3Bitrate);
        $result = fish_tts_request($apiKey, $model, $payload);
        if (!$result['ok']) {
            return [
                'ok' => false,
                'error' => 'Реплика #' . ($index + 1) . ' (' . ($speaker === 'male' ? 'М' : 'Ж') . '): ' . $result['error'],
                'audioUrl' => null,
                'audioLabel' => null,
                'success' => null,
                'segmentLinks' => $segmentLinks,
                'notes' => $notes,
            ];
        }

        $segmentFilename = $base . '_seg_' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '_' . $speaker . '.wav';
        file_put_contents($outputDir . '/' . $segmentFilename, $result['data']);
        $segmentLinks[] = [
            'label' => 'Реплика #' . ($index + 1) . ' — ' . ($speaker === 'male' ? 'мужчина' : 'женщина'),
            'url' => $outputUrlBase . '/' . rawurlencode($segmentFilename),
        ];
        $wavSegments[] = (string)$result['data'];
    }

    $concat = wav_concat($wavSegments, $pauseMs);
    if (!$concat['ok']) {
        return [
            'ok' => false,
            'error' => $concat['error'],
            'audioUrl' => null,
            'audioLabel' => null,
            'success' => null,
            'segmentLinks' => $segmentLinks,
            'notes' => $notes,
        ];
    }

    $wavFilename = $base . '_final.wav';
    $wavPath = $outputDir . '/' . $wavFilename;
    file_put_contents($wavPath, $concat['data']);

    if ($finalFormat === 'mp3') {
        $mp3Filename = $base . '_final.mp3';
        $mp3Path = $outputDir . '/' . $mp3Filename;
        if (convert_wav_to_mp3_if_possible($wavPath, $mp3Path)) {
            return [
                'ok' => true,
                'error' => '',
                'audioUrl' => $outputUrlBase . '/' . rawurlencode($mp3Filename),
                'audioLabel' => 'Скачать итоговый MP3',
                'success' => 'Готово. Диалог собран склейкой реплик и сохранён в outputs/' . $mp3Filename,
                'segmentLinks' => $segmentLinks,
                'notes' => $notes,
            ];
        }
        $notes[] = 'MP3 не собран: на сервере не найден ffmpeg или запрещён shell_exec. WAV работает без ffmpeg.';
    }

    return [
        'ok' => true,
        'error' => '',
        'audioUrl' => $outputUrlBase . '/' . rawurlencode($wavFilename),
        'audioLabel' => 'Скачать итоговый WAV',
        'success' => 'Готово. Диалог собран склейкой реплик и сохранён в outputs/' . $wavFilename,
        'segmentLinks' => $segmentLinks,
        'notes' => $notes,
    ];
}

$errors = [];
$success = null;
$audioUrl = null;
$audioLabel = null;
$segmentLinks = [];
$dialogueNotes = [];

$defaultSingleText = "Сегодня разберём слово [English]rainforest.\n\nДословно его иногда переводят как “дождевой лес”, но по-русски естественнее сказать: влажный тропический лес.\n\nА [English]API лучше читать как эй-пи-ай, а не по буквам на русском АПИ.";
$defaultDialogueText = "М: Привет. Сегодня разберём слово [English]rainforest.\nЖ: Дословно его иногда переводят как “дождевой лес”, но по-русски естественнее сказать: влажный тропический лес.\nМ: А [English]API лучше читать как эй-пи-ай [pause] а не по буквам на русском АПИ.\nЖ: Вот теперь звучит нормально. [laugh]";

$lastText = (string)($_POST['text'] ?? $defaultSingleText);
$lastDialogueText = (string)($_POST['dialogue_text'] ?? $defaultDialogueText);

if ($appPassword !== '' && isset($_POST['login_password'])) {
    if (hash_equals($appPassword, (string)$_POST['login_password'])) {
        $_SESSION['fish_tts_logged_in'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $errors[] = 'Неверный пароль.';
}

$isLoggedIn = ($appPassword === '') || !empty($_SESSION['fish_tts_logged_in']);
$ffmpegStatus = ffmpeg_available();

$selectedModel = allowed_value(post_string('model', 's2.1-pro-free'), ['s2.1-pro-free', 's2.1-pro', 's2-pro', 's1'], 's2.1-pro-free');
$selectedFormat = allowed_value(post_string('format', 'mp3'), ['mp3', 'wav', 'opus'], 'mp3');
$selectedLatency = allowed_value(post_string('latency', 'normal'), ['normal', 'balanced', 'low'], 'normal');
$selectedVoicePreset = post_string('voice_preset', '');
$customReferenceId = post_string('custom_reference_id', post_string('reference_id'));

$selectedMalePreset = post_string('male_voice_preset', '6ccdfb6b21e14501adc1fbcb44e50082');
$selectedFemalePreset = post_string('female_voice_preset', '2a1036d645634680b3cc69aeeb60375b');
$customMaleReferenceId = post_string('custom_male_reference_id', '');
$customFemaleReferenceId = post_string('custom_female_reference_id', '');
$dialogueMode = allowed_value(post_string('dialogue_mode', 'auto'), ['auto', 'native', 'segments'], 'auto');
$dialogueOutputFormat = allowed_value(post_string('dialogue_output_format', 'mp3'), ['mp3', 'wav'], 'mp3');
$activeTab = allowed_value(post_string('active_tab', post_string('action') === 'synthesize' ? 'single' : 'dialogue'), ['dialogue', 'single'], 'dialogue');

if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post_string('action'), ['synthesize', 'synthesize_dialogue'], true)) {
    if ($apiKey === '' || str_contains($apiKey, 'PASTE_YOUR')) {
        $errors[] = 'Не указан Fish Audio API key. Скопируйте config.example.php в config.php и вставьте ключ.';
    }

    $model = $selectedModel;
    $latency = $selectedLatency;
    $temperature = post_float('temperature', 0.7, 0.0, 1.0);
    $topP = post_float('top_p', 0.7, 0.0, 1.0);
    $speed = post_float('speed', 1.0, 0.5, 2.0);
    $volume = post_float('volume', 0.0, -10.0, 10.0);
    $chunkLength = post_int('chunk_length', 300, 100, 300);
    $mp3Bitrate = post_int('mp3_bitrate', 128, 64, 192);
    if (!in_array($mp3Bitrate, [64, 128, 192], true)) {
        $mp3Bitrate = 128;
    }

    if (post_string('action') === 'synthesize') {
        $text = trim((string)($_POST['text'] ?? ''));
        $lastText = $text;
        if ($text === '') {
            $errors[] = 'Вставьте текст для озвучки.';
        }
        if (mb_strlen($text, 'UTF-8') > 20000) {
            $errors[] = 'Текст слишком длинный для этой простой версии. Разбейте его на части до 20 000 символов.';
        }

        $format = $selectedFormat;
        $referenceId = resolve_reference_id($voicePresets, 'voice_preset', 'custom_reference_id', $errors, false);
        if (post_string('voice_preset') === '' && post_string('reference_id') !== '') {
            $referenceId = post_string('reference_id');
            $selectedVoicePreset = '__custom__';
            $customReferenceId = $referenceId;
        }

        if (!$errors) {
            $payload = build_tts_payload($text, $referenceId, $format, $latency, $temperature, $topP, $speed, $volume, $chunkLength, $mp3Bitrate);
            $result = fish_tts_request($apiKey, $model, $payload);
            if (!$result['ok']) {
                $errors[] = $result['error'];
            } else {
                $filename = 'tts_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $format;
                file_put_contents($outputDir . '/' . $filename, $result['data']);
                $audioUrl = $outputUrlBase . '/' . rawurlencode($filename);
                $audioLabel = 'Скачать аудио';
                $success = 'Готово. Аудио сохранено в outputs/' . $filename;
            }
        }
    }

    if (post_string('action') === 'synthesize_dialogue') {
        $rawDialogue = trim((string)($_POST['dialogue_text'] ?? ''));
        $lastDialogueText = $rawDialogue;
        if (mb_strlen($rawDialogue, 'UTF-8') > 30000) {
            $errors[] = 'Диалог слишком длинный для этой простой версии. Разбейте его на части до 30 000 символов.';
        }

        $maleReferenceId = resolve_reference_id($voicePresets, 'male_voice_preset', 'custom_male_reference_id', $errors, true);
        $femaleReferenceId = resolve_reference_id($voicePresets, 'female_voice_preset', 'custom_female_reference_id', $errors, true);
        $pauseMs = post_int('pause_ms', 350, 0, 3000);
        $parse = parse_dialogue_script($rawDialogue);
        if (!$parse['ok']) {
            $errors[] = $parse['error'];
        }
        $items = $parse['items'] ?? [];
        if (count($items) > 80) {
            $errors[] = 'Слишком много реплик. Для этой версии максимум 80 реплик за один запуск.';
        }

        if (!$errors) {
            $canTryNative = in_array($model, ['s2.1-pro-free', 's2.1-pro', 's2-pro'], true) && in_array($dialogueMode, ['auto', 'native'], true);
            if (!$canTryNative && $dialogueMode === 'native') {
                $errors[] = 'Native multi-speaker работает только для S2/S2.1. Выберите s2.1-pro-free, s2.1-pro или s2-pro.';
            }

            if (!$errors && $canTryNative) {
                $speakerText = dialogue_to_fish_speaker_text($items);
                $payload = build_tts_payload($speakerText, [$maleReferenceId, $femaleReferenceId], $dialogueOutputFormat, $latency, $temperature, $topP, $speed, $volume, $chunkLength, $mp3Bitrate);
                $result = fish_tts_request($apiKey, $model, $payload);
                if ($result['ok']) {
                    $filename = 'dialogue_native_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $dialogueOutputFormat;
                    file_put_contents($outputDir . '/' . $filename, $result['data']);
                    $audioUrl = $outputUrlBase . '/' . rawurlencode($filename);
                    $audioLabel = 'Скачать native-диалог';
                    $success = 'Готово. Native multi-speaker диалог Fish Audio сохранён в outputs/' . $filename;
                    $dialogueNotes[] = 'Использован один запрос Fish Audio с <|speaker:0|> и <|speaker:1|>. Склейка не понадобилась.';
                } elseif ($dialogueMode === 'native') {
                    $errors[] = 'Native multi-speaker не сработал: ' . $result['error'];
                } else {
                    $dialogueNotes[] = 'Native multi-speaker не сработал, включён резервный режим склейки. Причина: ' . $result['error'];
                }
            }

            if (!$errors && $audioUrl === null && in_array($dialogueMode, ['auto', 'segments'], true)) {
                $segmentResult = synthesize_segmented_dialogue($apiKey, $model, $items, $maleReferenceId, $femaleReferenceId, $dialogueOutputFormat, $pauseMs, $outputDir, $outputUrlBase, $latency, $temperature, $topP, $speed, $volume, $chunkLength, $mp3Bitrate);
                $segmentLinks = $segmentResult['segmentLinks'] ?? [];
                foreach (($segmentResult['notes'] ?? []) as $note) {
                    $dialogueNotes[] = (string)$note;
                }
                if (!$segmentResult['ok']) {
                    $errors[] = (string)$segmentResult['error'];
                } else {
                    $audioUrl = $segmentResult['audioUrl'];
                    $audioLabel = $segmentResult['audioLabel'];
                    $success = $segmentResult['success'];
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Fish Audio TTS</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-soft: #f8f9fc;
            --text: #172033;
            --muted: #667085;
            --line: #e5e8ef;
            --primary: #5b5cf6;
            --primary-dark: #4647d9;
            --primary-soft: #eeeeff;
            --success-bg: #ecfdf3;
            --success-line: #abefc6;
            --success-text: #067647;
            --error-bg: #fff1f3;
            --error-line: #fecdd6;
            --error-text: #b42318;
            --note-bg: #fffaeb;
            --note-line: #fedf89;
            --note-text: #93370d;
            --radius: 18px;
            --shadow: 0 12px 34px rgba(16, 24, 40, .08);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }
        * { box-sizing: border-box; }
        html { min-height: 100%; -webkit-text-size-adjust: 100%; }
        body {
            min-height: 100%;
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #f8f9ff 0, var(--bg) 320px);
            color: var(--text);
        }
        button, input, select, textarea { font: inherit; }
        button { touch-action: manipulation; }
        .app-shell { width: min(100%, 1240px); margin: 0 auto; padding: 16px 12px calc(104px + var(--safe-bottom)); }
        .app-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 8px 4px 18px;
        }
        .brand-kicker { margin: 0 0 4px; color: var(--primary-dark); font-size: 13px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: clamp(28px, 7vw, 42px); line-height: 1.02; letter-spacing: -.04em; }
        .header-desc { max-width: 650px; margin: 8px 0 0; color: var(--muted); line-height: 1.5; }
        .header-link { flex: 0 0 auto; color: var(--primary-dark); font-weight: 800; text-decoration: none; padding: 10px 0; }
        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .status-stack { display: grid; gap: 10px; margin-bottom: 14px; }
        .alert { border: 1px solid transparent; border-radius: 14px; padding: 12px 14px; line-height: 1.45; }
        .ok { background: var(--success-bg); border-color: var(--success-line); color: var(--success-text); }
        .error { background: var(--error-bg); border-color: var(--error-line); color: var(--error-text); white-space: pre-wrap; }
        .note { background: var(--note-bg); border-color: var(--note-line); color: var(--note-text); }
        .result-card { padding: 16px; margin-bottom: 14px; }
        .result-card audio { width: 100%; margin-top: 12px; }
        .result-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
        .segments { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .segments a { color: var(--primary-dark); background: var(--primary-soft); padding: 8px 10px; border-radius: 999px; text-decoration: none; font-size: 13px; font-weight: 750; }

        .mode-tabs {
            position: sticky;
            top: 8px;
            z-index: 40;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px;
            padding: 5px;
            margin-bottom: 14px;
            border: 1px solid rgba(91,92,246,.16);
            border-radius: 16px;
            background: rgba(255,255,255,.94);
            box-shadow: 0 10px 28px rgba(16,24,40,.10);
            backdrop-filter: blur(16px);
        }
        .mode-tab {
            min-height: 50px;
            border: 0;
            border-radius: 12px;
            background: transparent;
            color: #4b5565;
            font-weight: 850;
            cursor: pointer;
        }
        .mode-tab small { display: block; margin-top: 1px; font-size: 11px; font-weight: 650; opacity: .72; }
        .mode-tab.is-active { color: #fff; background: linear-gradient(135deg, var(--primary), #7c5cf5); box-shadow: 0 8px 18px rgba(91,92,246,.25); }
        .tab-panel[hidden] { display: none !important; }

        .workspace { display: grid; gap: 14px; }
        .editor-card, .settings-card { padding: 16px; }
        .section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        .section-title { margin: 0; font-size: 21px; line-height: 1.2; letter-spacing: -.025em; }
        .section-desc { margin: 5px 0 0; color: var(--muted); font-size: 14px; line-height: 1.45; }
        label { display: block; margin: 14px 0 7px; font-weight: 800; color: #273043; }
        textarea, input, select {
            width: 100%;
            min-height: 50px;
            border: 1px solid #d7dce6;
            border-radius: 13px;
            background: #fff;
            color: var(--text);
            padding: 13px 14px;
            font-size: 16px;
            outline: none;
            transition: border-color .16s ease, box-shadow .16s ease;
        }
        textarea { min-height: 280px; line-height: 1.55; resize: vertical; }
        .dialogue-textarea { min-height: 330px; }
        textarea:focus, input:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(91,92,246,.12); }
        .counter-row { display: flex; justify-content: space-between; gap: 12px; margin-top: 7px; color: var(--muted); font-size: 12px; }

        .editor-tools {
            margin-top: 12px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: var(--surface-soft);
            overflow: hidden;
        }
        .quick-tools { display: flex; gap: 8px; overflow-x: auto; padding: 10px; scrollbar-width: thin; -webkit-overflow-scrolling: touch; }
        .tool-btn {
            flex: 0 0 auto;
            min-height: 42px;
            border: 1px solid #dcdffd;
            border-radius: 999px;
            background: #fff;
            color: #3f42c9;
            padding: 9px 12px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: none;
        }
        .tool-btn.speaker-m { color: #175cd3; border-color: #b2ccff; background: #eff8ff; }
        .tool-btn.speaker-f { color: #c11574; border-color: #fcceee; background: #fdf2fa; }
        .tool-btn.utility { color: #344054; border-color: #d0d5dd; }
        .tool-btn:active { transform: translateY(1px); }
        .tag-library { border-top: 1px solid var(--line); }
        .tag-library summary {
            min-height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 0 12px;
            cursor: pointer;
            font-weight: 800;
            list-style: none;
        }
        .tag-library summary::-webkit-details-marker { display: none; }
        .tag-library summary::after { content: "+"; font-size: 22px; color: var(--muted); }
        .tag-library[open] summary::after { content: "−"; }
        .tag-library-body { display: grid; gap: 14px; padding: 0 10px 12px; }
        .tag-group-title { margin-bottom: 7px; color: #475467; font-size: 13px; font-weight: 850; }
        .chips { display: flex; flex-wrap: wrap; gap: 7px; }
        .insert-chip {
            min-height: 40px;
            border: 1px solid #dcdffd;
            border-radius: 999px;
            background: #fff;
            color: #3f42c9;
            padding: 8px 11px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
        }
        .preset-list { display: grid; gap: 8px; }
        .preset-btn {
            width: 100%;
            min-height: 48px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--text);
            padding: 10px 12px;
            text-align: left;
            cursor: pointer;
        }
        .preset-btn span { color: var(--muted); font-size: 12px; font-weight: 700; white-space: nowrap; }
        .utility-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .text-button {
            min-height: 42px;
            border: 1px solid #d0d5dd;
            border-radius: 11px;
            background: #fff;
            color: #344054;
            padding: 9px 12px;
            font-weight: 800;
            cursor: pointer;
        }
        .helper-text { color: var(--muted); font-size: 12px; line-height: 1.45; }

        .settings-card h3 { margin: 0 0 4px; font-size: 18px; }
        .settings-card .section-desc { margin-bottom: 8px; }
        .settings-grid { display: grid; gap: 10px; }
        .custom-voice { display: none; }
        .custom-voice.is-visible { display: block; }
        .inline-help {
            margin-top: 14px;
            border-radius: 13px;
            background: #f8f9fc;
            border: 1px solid var(--line);
            padding: 12px;
            color: #475467;
            font-size: 13px;
            line-height: 1.5;
        }
        .guide-lines { display: grid; gap: 10px; }
        .guide-line { display: grid; gap: 5px; }
        .guide-label { color: #344054; font-weight: 850; }
        code {
            display: block;
            width: 100%;
            border-radius: 9px;
            background: var(--primary-soft);
            color: #3f42c9;
            padding: 7px 9px;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            line-height: 1.45;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        .advanced-settings { margin-top: 14px; border: 1px solid var(--line); border-radius: 13px; overflow: hidden; }
        .advanced-settings summary { min-height: 48px; display: flex; align-items: center; padding: 0 12px; cursor: pointer; font-weight: 850; }
        .advanced-content { padding: 0 12px 12px; }
        .field-grid { display: grid; gap: 10px; }
        .desktop-submit { display: none; }
        .primary-button, .secondary-button, .download-button {
            min-height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 13px;
            padding: 12px 17px;
            font-weight: 900;
            cursor: pointer;
            text-decoration: none;
        }
        .primary-button { color: #fff; background: linear-gradient(135deg, var(--primary), #7c5cf5); box-shadow: 0 10px 22px rgba(91,92,246,.24); }
        .secondary-button, .download-button { color: #fff; background: #1f2937; }
        .desktop-submit .primary-button { width: 100%; margin-top: 14px; }

        .mobile-action {
            position: fixed;
            left: 10px;
            right: 10px;
            bottom: calc(8px + var(--safe-bottom));
            z-index: 60;
            display: none;
            align-items: center;
            gap: 10px;
            padding: 8px;
            border: 1px solid rgba(91,92,246,.20);
            border-radius: 17px;
            background: rgba(255,255,255,.95);
            box-shadow: 0 16px 44px rgba(16,24,40,.22);
            backdrop-filter: blur(16px);
        }
        .mobile-action.is-active { display: flex; }
        .mobile-action .primary-button { width: 100%; min-height: 56px; }
        .mobile-action-label { display: none; }
        .login-card { max-width: 520px; padding: 18px; margin: 30px auto 0; }
        .app-footer { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 22px 4px 8px; color: var(--muted); font-size: 13px; }
        .app-footer a { color: var(--primary-dark); font-weight: 850; text-decoration: none; }

        @media (min-width: 900px) {
            .app-shell { padding: 26px 20px 36px; }
            .mode-tabs { position: static; width: 420px; grid-template-columns: 1fr 1fr; margin-bottom: 18px; }
            .workspace { grid-template-columns: minmax(0, 1fr) 360px; align-items: start; gap: 18px; }
            .editor-card, .settings-card { padding: 22px; }
            .settings-card { position: sticky; top: 18px; }
            textarea { min-height: 420px; }
            .dialogue-textarea { min-height: 470px; }
            .quick-tools { flex-wrap: wrap; overflow: visible; }
            .field-grid.two { grid-template-columns: 1fr 1fr; }
            .desktop-submit { display: block; }
            .mobile-action { display: none !important; }
            .tag-library-body { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .tag-library-body .wide { grid-column: 1 / -1; }
        }
        @media (max-width: 520px) {
            .app-shell { padding-inline: 8px; }
            .app-header { padding-inline: 4px; }
            .header-link { display: none; }
            .editor-card, .settings-card { padding: 14px; }
            .section-title { font-size: 19px; }
            textarea { min-height: 250px; }
            .dialogue-textarea { min-height: 300px; }
            .mode-tab { font-size: 14px; }
            .preset-btn { display: block; }
            .preset-btn span { display: block; margin-top: 5px; white-space: normal; }
        }
        @media (prefers-color-scheme: dark) {
            :root {
                color-scheme: dark;
                --bg: #0c1220;
                --surface: #111827;
                --surface-soft: #161f2f;
                --text: #f3f4f6;
                --muted: #aab2c0;
                --line: #293244;
                --primary-soft: #22234f;
            }
            body { background: linear-gradient(180deg, #11152a 0, #0c1220 320px); }
            .mode-tabs, .mobile-action { background: rgba(17,24,39,.95); }
            textarea, input, select, .tool-btn, .insert-chip, .preset-btn, .text-button { background: #0f172a; color: #f3f4f6; border-color: #344054; }
            label, .guide-label, .section-title { color: #f3f4f6; }
            .inline-help { background: #161f2f; color: #cbd5e1; }
            .tool-btn.speaker-m { background: #102a4d; color: #b2ddff; border-color: #175cd3; }
            .tool-btn.speaker-f { background: #4a1235; color: #fcceee; border-color: #c11574; }
            .insert-chip { color: #c7d2fe; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <header class="app-header">
        <div>
            <p class="brand-kicker">Fish Audio</p>
            <h1>Студия озвучки</h1>
            <p class="header-desc">Один голос для дикторского текста или полноценный диалог с двумя голосами. Каждый режим — в своей вкладке и со своими инструментами.</p>
        </div>
        <a class="header-link" href="https://it-uu.ru" target="_blank" rel="noopener noreferrer">it-uu.ru</a>
    </header>

    <?php if ($errors || $dialogueNotes): ?>
        <div class="status-stack">
            <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
            <?php foreach ($dialogueNotes as $note): ?><div class="alert note"><?= h($note) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$isLoggedIn): ?>
        <div class="card login-card">
            <h2 class="section-title">Вход</h2>
            <form method="post">
                <label for="login_password">Пароль</label>
                <input id="login_password" name="login_password" type="password" autocomplete="current-password" autofocus>
                <button class="primary-button" style="width:100%;margin-top:14px" type="submit">Войти</button>
            </form>
        </div>
    <?php else: ?>
        <?php if (!file_exists($configPath)): ?>
            <div class="alert error" style="margin-bottom:14px">Нет config.php. Скопируйте config.example.php в config.php и вставьте Fish Audio API key.</div>
        <?php endif; ?>

        <?php if ($success): ?>
            <section class="card result-card">
                <div class="alert ok"><?= h($success) ?></div>
                <?php if ($audioUrl): ?>
                    <audio controls src="<?= h($audioUrl) ?>"></audio>
                    <div class="result-actions"><a class="download-button" href="<?= h($audioUrl) ?>" download><?= h((string)$audioLabel ?: 'Скачать аудио') ?></a></div>
                <?php endif; ?>
                <?php if ($segmentLinks): ?>
                    <div class="segments">
                        <?php foreach ($segmentLinks as $link): ?><a href="<?= h($link['url']) ?>" download><?= h($link['label']) ?></a><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <nav class="mode-tabs" role="tablist" aria-label="Режим озвучки">
            <button type="button" class="mode-tab <?= $activeTab === 'dialogue' ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'dialogue' ? 'true' : 'false' ?>" aria-controls="dialogue-panel" data-tab="dialogue">Диалог<small>два голоса</small></button>
            <button type="button" class="mode-tab <?= $activeTab === 'single' ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'single' ? 'true' : 'false' ?>" aria-controls="single-panel" data-tab="single">Один голос<small>дикторский текст</small></button>
        </nav>

        <section id="dialogue-panel" class="tab-panel" role="tabpanel" data-tab-panel="dialogue" <?= $activeTab === 'dialogue' ? '' : 'hidden' ?>>
            <form id="dialogue-form" method="post">
                <input type="hidden" name="action" value="synthesize_dialogue">
                <input type="hidden" name="active_tab" value="dialogue">
                <div class="workspace">
                    <main class="card editor-card">
                        <div class="section-head">
                            <div>
                                <h2 class="section-title">Сценарий диалога</h2>
                                <p class="section-desc">Каждая реплика начинается с <strong>М:</strong> или <strong>Ж:</strong>. Теги и готовые фразы вставляются прямо в это поле в позицию курсора.</p>
                            </div>
                        </div>
                        <textarea id="dialogue_text" class="dialogue-textarea js-counted" name="dialogue_text" maxlength="30000" required><?= h($lastDialogueText) ?></textarea>
                        <div class="counter-row"><span>Формат: М: текст / Ж: текст</span><span data-counter-for="dialogue_text"></span></div>

                        <div class="editor-tools" aria-label="Инструменты сценария">
                            <div class="quick-tools">
                                <button type="button" class="tool-btn speaker-m js-insert-line" data-target="dialogue_text" data-insert="М: ">М: реплика</button>
                                <button type="button" class="tool-btn speaker-f js-insert-line" data-target="dialogue_text" data-insert="Ж: ">Ж: реплика</button>
                                <button type="button" class="tool-btn js-insert" data-target="dialogue_text" data-insert="[calm] ">[calm]</button>
                                <button type="button" class="tool-btn js-insert" data-target="dialogue_text" data-insert="[pause] ">[pause]</button>
                                <button type="button" class="tool-btn js-insert" data-target="dialogue_text" data-insert="[English]">[English]</button>
                                <button type="button" class="tool-btn utility js-tag-english" data-target="dialogue_text">Пометить латиницу</button>
                            </div>
                            <details class="tag-library">
                                <summary>Все интонации, эффекты и заготовки</summary>
                                <div class="tag-library-body">
                                    <div>
                                        <div class="tag-group-title">Интонация</div>
                                        <div class="chips">
                                            <?php foreach (['[calm] ', '[confident] ', '[excited] ', '[curious] ', '[sarcastic] ', '[sad] ', '[surprised] ', '[angry] ', '[panicked] ', '[terrified] '] as $tag): ?>
                                                <button type="button" class="insert-chip js-insert" data-target="dialogue_text" data-insert="<?= h($tag) ?>"><?= h(trim($tag)) ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="tag-group-title">Подача и звуки</div>
                                        <div class="chips">
                                            <?php foreach (['[whisper] ', '[emphasis] ', '[softly] ', '[speaking slowly] ', '[teacher tone] ', '[laugh] ', '[sigh] ', '[gasp] ', '[inhale] ', '[exhale] ', '[pause] '] as $tag): ?>
                                                <button type="button" class="insert-chip js-insert" data-target="dialogue_text" data-insert="<?= h($tag) ?>"><?= h(trim($tag)) ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="wide">
                                        <div class="tag-group-title">Готовые заготовки для диалога</div>
                                        <div class="preset-list">
                                            <button type="button" class="preset-btn js-insert-block" data-target="dialogue_text" data-insert="М: [calm] Привет. Сегодня разберём слово [English]rainforest.&#10;Ж: [curious] А почему его нельзя переводить дословно?&#10;"><strong>Учебный диалог</strong><span>вставить 2 реплики</span></button>
                                            <button type="button" class="preset-btn js-insert-block" data-target="dialogue_text" data-insert="М: [confident] Добрый день! Чем можем помочь?&#10;Ж: [calm] Нужна консультация по автоматизации бизнеса.&#10;"><strong>Деловой диалог</strong><span>вставить 2 реплики</span></button>
                                            <button type="button" class="preset-btn js-insert-block" data-target="dialogue_text" data-insert="М: [terrified, shouting] Бежим! Он уже здесь!&#10;Ж: [panicked] Где выход?! Быстрее!&#10;"><strong>Паника / персонажи</strong><span>вставить 2 реплики</span></button>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        </div>
                    </main>

                    <aside class="card settings-card">
                        <h3>Голоса и параметры</h3>
                        <p class="section-desc">Мужчина — speaker:0, женщина — speaker:1.</p>
                        <label for="male_voice_preset">Мужской голос</label>
                        <select id="male_voice_preset" name="male_voice_preset"><?= render_voice_options($voicePresets, $selectedMalePreset, false) ?></select>
                        <div id="customMaleVoiceWrap" class="custom-voice <?= $selectedMalePreset === '__custom__' ? 'is-visible' : '' ?>">
                            <label for="custom_male_reference_id">Voice ID мужчины</label>
                            <input id="custom_male_reference_id" name="custom_male_reference_id" value="<?= h($customMaleReferenceId) ?>">
                        </div>
                        <label for="female_voice_preset">Женский голос</label>
                        <select id="female_voice_preset" name="female_voice_preset"><?= render_voice_options($voicePresets, $selectedFemalePreset, false) ?></select>
                        <div id="customFemaleVoiceWrap" class="custom-voice <?= $selectedFemalePreset === '__custom__' ? 'is-visible' : '' ?>">
                            <label for="custom_female_reference_id">Voice ID женщины</label>
                            <input id="custom_female_reference_id" name="custom_female_reference_id" value="<?= h($customFemaleReferenceId) ?>">
                        </div>
                        <div class="field-grid two">
                            <div><label for="dialogue_mode">Режим</label><select id="dialogue_mode" name="dialogue_mode"><option value="auto" <?= $dialogueMode === 'auto' ? 'selected' : '' ?>>Авто: native → склейка</option><option value="native" <?= $dialogueMode === 'native' ? 'selected' : '' ?>>Только native</option><option value="segments" <?= $dialogueMode === 'segments' ? 'selected' : '' ?>>Только склейка</option></select></div>
                            <div><label for="dialogue_output_format">Формат</label><select id="dialogue_output_format" name="dialogue_output_format"><option value="mp3" <?= $dialogueOutputFormat === 'mp3' ? 'selected' : '' ?>>MP3</option><option value="wav" <?= $dialogueOutputFormat === 'wav' ? 'selected' : '' ?>>WAV</option></select></div>
                        </div>
                        <label for="model_dialogue">Модель</label>
                        <select id="model_dialogue" name="model"><?php foreach (['s2.1-pro-free', 's2.1-pro', 's2-pro', 's1'] as $m): ?><option value="<?= h($m) ?>" <?= $selectedModel === $m ? 'selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select>
                        <label for="pause_ms">Пауза при резервной склейке, мс</label>
                        <input id="pause_ms" name="pause_ms" type="number" min="0" max="3000" step="50" value="<?= h(post_string('pause_ms', '350')) ?>">

                        <?php $controlScope = 'dialogue'; include __DIR__ . '/shared_controls.php'; unset($controlScope); ?>

                        <div class="inline-help guide-lines">
                            <div class="guide-line"><span class="guide-label">Пишите так</span><code>М: [calm] Привет.&#10;Ж: [excited] Начинаем!</code></div>
                            <div class="guide-line"><span class="guide-label">Fish получит</span><code>&lt;|speaker:0|&gt;...&#10;&lt;|speaker:1|&gt;...</code></div>
                            <div class="helper-text">ffmpeg: <strong><?= $ffmpegStatus ? 'доступен' : 'не найден' ?></strong>. Для native-диалога он не нужен.</div>
                        </div>
                        <div class="desktop-submit"><button class="primary-button" type="submit">Озвучить диалог</button></div>
                    </aside>
                </div>
            </form>
        </section>

        <section id="single-panel" class="tab-panel" role="tabpanel" data-tab-panel="single" <?= $activeTab === 'single' ? '' : 'hidden' ?>>
            <form id="single-form" method="post">
                <input type="hidden" name="action" value="synthesize">
                <input type="hidden" name="active_tab" value="single">
                <div class="workspace">
                    <main class="card editor-card">
                        <div class="section-head">
                            <div>
                                <h2 class="section-title">Текст одним голосом</h2>
                                <p class="section-desc">Дикторская озвучка, автоответчик, статья или аудиокнига. Все кнопки ниже вставляют теги именно в это поле.</p>
                            </div>
                        </div>
                        <textarea id="text" class="js-counted" name="text" maxlength="20000" required><?= h($lastText) ?></textarea>
                        <div class="counter-row"><span>До 20 000 символов</span><span data-counter-for="text"></span></div>

                        <div class="editor-tools" aria-label="Инструменты текста">
                            <div class="quick-tools">
                                <button type="button" class="tool-btn js-insert" data-target="text" data-insert="[calm] ">[calm]</button>
                                <button type="button" class="tool-btn js-insert" data-target="text" data-insert="[confident] ">[confident]</button>
                                <button type="button" class="tool-btn js-insert" data-target="text" data-insert="[pause] ">[pause]</button>
                                <button type="button" class="tool-btn js-insert" data-target="text" data-insert="[English]">[English]</button>
                                <button type="button" class="tool-btn utility js-tag-english" data-target="text">Пометить латиницу</button>
                            </div>
                            <details class="tag-library">
                                <summary>Все интонации, эффекты и заготовки</summary>
                                <div class="tag-library-body">
                                    <div>
                                        <div class="tag-group-title">Интонация</div>
                                        <div class="chips">
                                            <?php foreach (['[calm] ', '[confident] ', '[excited] ', '[curious] ', '[sarcastic] ', '[sad] ', '[surprised] ', '[angry] ', '[panicked] ', '[terrified] '] as $tag): ?>
                                                <button type="button" class="insert-chip js-insert" data-target="text" data-insert="<?= h($tag) ?>"><?= h(trim($tag)) ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="tag-group-title">Подача и звуки</div>
                                        <div class="chips">
                                            <?php foreach (['[whisper] ', '[emphasis] ', '[softly] ', '[speaking slowly] ', '[teacher tone] ', '[professional narrator, calm, confident] ', '[laugh] ', '[sigh] ', '[gasp] ', '[inhale] ', '[exhale] ', '[pause] '] as $tag): ?>
                                                <button type="button" class="insert-chip js-insert" data-target="text" data-insert="<?= h($tag) ?>"><?= h(trim($tag)) ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="wide">
                                        <div class="tag-group-title">Готовые заготовки</div>
                                        <div class="preset-list">
                                            <button type="button" class="preset-btn js-insert-block" data-target="text" data-insert="[professional narrator, calm, confident] &#10;"><strong>Новостной диктор</strong><span>вставить подачу</span></button>
                                            <button type="button" class="preset-btn js-insert-block" data-target="text" data-insert="[calm] Здравствуйте! Вы позвонили в «Ай-ти-тек». [pause]&#10;&#10;Мы помогаем бизнесу с техникой, сайтами, серверами и автоматизацией. [pause]&#10;&#10;Пожалуйста, дождитесь ответа специалиста.&#10;"><strong>Автоответчик</strong><span>вставить пример</span></button>
                                            <button type="button" class="preset-btn js-insert-block" data-target="text" data-insert="[terrified, screaming] А-а-а! Нет! Не подходи!&#10;"><strong>Крик в ужасе</strong><span>вставить пример</span></button>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        </div>
                    </main>

                    <aside class="card settings-card">
                        <h3>Голос и параметры</h3>
                        <p class="section-desc">Выберите голос и формат результата.</p>
                        <label for="voice_preset">Голос</label>
                        <select id="voice_preset" name="voice_preset"><?= render_voice_options($voicePresets, $selectedVoicePreset, true) ?></select>
                        <div id="customVoiceWrap" class="custom-voice <?= $selectedVoicePreset === '__custom__' ? 'is-visible' : '' ?>">
                            <label for="custom_reference_id">Свой Voice ID</label>
                            <input id="custom_reference_id" name="custom_reference_id" value="<?= h($customReferenceId) ?>">
                        </div>
                        <div class="field-grid two">
                            <div><label for="model_single">Модель</label><select id="model_single" name="model"><?php foreach (['s2.1-pro-free', 's2.1-pro', 's2-pro', 's1'] as $m): ?><option value="<?= h($m) ?>" <?= $selectedModel === $m ? 'selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select></div>
                            <div><label for="format">Формат</label><select id="format" name="format"><?php foreach (['mp3', 'wav', 'opus'] as $f): ?><option value="<?= h($f) ?>" <?= $selectedFormat === $f ? 'selected' : '' ?>><?= h(strtoupper($f)) ?></option><?php endforeach; ?></select></div>
                        </div>

                        <?php $controlScope = 'single'; include __DIR__ . '/shared_controls.php'; unset($controlScope); ?>

                        <div class="inline-help guide-lines">
                            <div class="guide-line"><span class="guide-label">Английское слово</span><code>Сегодня разберём [English]rainforest.</code></div>
                            <div class="guide-line"><span class="guide-label">Дикторская подача</span><code>[professional narrator, calm, confident]</code></div>
                        </div>
                        <div class="desktop-submit"><button class="primary-button" type="submit">Озвучить текст</button></div>
                    </aside>
                </div>
            </form>
        </section>

        <div id="mobile-action-dialogue" class="mobile-action <?= $activeTab === 'dialogue' ? 'is-active' : '' ?>" data-mobile-action="dialogue">
            <button type="button" class="primary-button js-submit-form" data-form="dialogue-form">Озвучить диалог</button>
        </div>
        <div id="mobile-action-single" class="mobile-action <?= $activeTab === 'single' ? 'is-active' : '' ?>" data-mobile-action="single">
            <button type="button" class="primary-button js-submit-form" data-form="single-form">Озвучить текст</button>
        </div>
    <?php endif; ?>

    <footer class="app-footer">
        <span>Fish Audio TTS Web</span>
        <span>Автор: <a href="https://it-uu.ru" target="_blank" rel="noopener noreferrer">it-uu.ru</a></span>
    </footer>
</div>
<script>
(function () {
    const tabs = Array.from(document.querySelectorAll('.mode-tab'));
    const panels = Array.from(document.querySelectorAll('[data-tab-panel]'));
    const mobileActions = Array.from(document.querySelectorAll('[data-mobile-action]'));

    function activateTab(name, persist = true) {
        tabs.forEach((tab) => {
            const active = tab.dataset.tab === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach((panel) => { panel.hidden = panel.dataset.tabPanel !== name; });
        mobileActions.forEach((bar) => bar.classList.toggle('is-active', bar.dataset.mobileAction === name));
        if (persist) { try { localStorage.setItem('fish_tts_active_tab', name); } catch (e) {} }
    }
    tabs.forEach((tab) => tab.addEventListener('click', () => activateTab(tab.dataset.tab)));
    try {
        const saved = localStorage.getItem('fish_tts_active_tab');
        if (saved && !document.querySelector('.alert.ok, .alert.error')) activateTab(saved, false);
    } catch (e) {}

    function toggleCustom(selectId, wrapId) {
        const select = document.getElementById(selectId);
        const wrap = document.getElementById(wrapId);
        if (!select || !wrap) return;
        const update = () => wrap.classList.toggle('is-visible', select.value === '__custom__');
        select.addEventListener('change', update);
        update();
    }
    toggleCustom('voice_preset', 'customVoiceWrap');
    toggleCustom('male_voice_preset', 'customMaleVoiceWrap');
    toggleCustom('female_voice_preset', 'customFemaleVoiceWrap');

    function setCursor(target, position) {
        target.focus({preventScroll: true});
        target.setSelectionRange(position, position);
        target.dispatchEvent(new Event('input', {bubbles: true}));
    }
    function insertAtCursor(target, text) {
        const start = target.selectionStart ?? target.value.length;
        const end = target.selectionEnd ?? start;
        target.value = target.value.slice(0, start) + text + target.value.slice(end);
        setCursor(target, start + text.length);
    }
    function insertLine(target, prefix) {
        const cursor = target.selectionStart ?? target.value.length;
        const before = target.value.slice(0, cursor);
        let insertion = prefix;
        if (before.length && !before.endsWith('\n')) insertion = '\n' + insertion;
        insertAtCursor(target, insertion);
    }
    function insertBlock(target, block) {
        const cursor = target.selectionStart ?? target.value.length;
        const before = target.value.slice(0, cursor);
        let insertion = block;
        if (before.trim() !== '' && !before.endsWith('\n\n')) insertion = (before.endsWith('\n') ? '\n' : '\n\n') + insertion;
        insertAtCursor(target, insertion);
    }

    document.querySelectorAll('.js-insert').forEach((button) => button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.target);
        if (target) insertAtCursor(target, button.dataset.insert || '');
    }));
    document.querySelectorAll('.js-insert-line').forEach((button) => button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.target);
        if (target) insertLine(target, button.dataset.insert || '');
    }));
    document.querySelectorAll('.js-insert-block').forEach((button) => button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.target);
        if (target) insertBlock(target, button.dataset.insert || '');
    }));

    function tagEnglish(text) {
        return text.replace(/\b[A-Za-z][A-Za-z0-9+.#'’\-]*\b/g, function (match, offset, full) {
            const before = full.slice(Math.max(0, offset - 9), offset);
            const after = full.slice(offset + match.length, offset + match.length + 1);
            const prev = full.slice(Math.max(0, offset - 1), offset);
            if (match === 'English' && prev === '[' && after === ']') return match;
            if (before === '[English]') return match;
            if (/^(https?|www|mailto|speaker)$/i.test(match)) return match;
            return '[English]' + match;
        });
    }
    document.querySelectorAll('.js-tag-english').forEach((button) => button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.target);
        if (!target) return;
        const cursor = target.selectionStart ?? target.value.length;
        target.value = tagEnglish(target.value);
        setCursor(target, Math.min(cursor, target.value.length));
    }));

    document.querySelectorAll('.js-submit-form').forEach((button) => button.addEventListener('click', () => {
        const form = document.getElementById(button.dataset.form);
        if (form) form.requestSubmit();
    }));

    function updateCounter(textarea) {
        const counter = document.querySelector('[data-counter-for="' + textarea.id + '"]');
        if (counter) counter.textContent = textarea.value.length.toLocaleString('ru-RU') + ' символов';
    }
    document.querySelectorAll('.js-counted').forEach((textarea) => {
        textarea.addEventListener('input', () => updateCounter(textarea));
        updateCounter(textarea);
    });
})();
</script>
</body>
</html>
