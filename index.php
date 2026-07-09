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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fish Audio TTS</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #eef3ff;
            --bg-2: #f8fbff;
            --surface: rgba(255, 255, 255, .88);
            --surface-strong: #ffffff;
            --text: #101827;
            --muted: #667085;
            --line: rgba(25, 39, 70, .12);
            --primary: #5b5cf6;
            --primary-2: #8b5cf6;
            --primary-3: #00a6d6;
            --dark: #111827;
            --success-bg: #ecfdf3;
            --success-line: #abefc6;
            --success-text: #067647;
            --error-bg: #fff1f3;
            --error-line: #fecdd6;
            --error-text: #b42318;
            --note-bg: #fffaeb;
            --note-line: #fedf89;
            --note-text: #93370d;
            --radius-lg: 24px;
            --radius: 16px;
            --tap: 52px;
            --shadow: 0 18px 55px rgba(16, 24, 40, .12);
            --shadow-soft: 0 10px 28px rgba(16, 24, 40, .08);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }
        * { box-sizing: border-box; }
        html { min-height: 100%; scroll-behavior: smooth; }
        body {
            min-height: 100%;
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at 10% 0%, rgba(91, 92, 246, .22), transparent 22rem),
                radial-gradient(circle at 95% 8%, rgba(0, 166, 214, .18), transparent 22rem),
                linear-gradient(135deg, var(--bg), var(--bg-2) 58%, #fff7ed);
            color: var(--text);
            padding-bottom: calc(18px + var(--safe-bottom));
            -webkit-text-size-adjust: 100%;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image: linear-gradient(rgba(16, 24, 40, .032) 1px, transparent 1px), linear-gradient(90deg, rgba(16, 24, 40, .032) 1px, transparent 1px);
            background-size: 38px 38px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.5), transparent 62%);
        }
        .wrap {
            position: relative;
            z-index: 1;
            width: min(100%, 1180px);
            margin: 0 auto;
            padding: 12px 10px calc(28px + var(--safe-bottom));
        }
        .card {
            position: relative;
            overflow: hidden;
            background: var(--surface);
            border: 1px solid rgba(255, 255, 255, .72);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin: 0 0 14px;
            box-shadow: var(--shadow-soft);
            backdrop-filter: blur(16px);
        }
        .card::after {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            pointer-events: none;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.86);
        }
        .hero-card {
            padding: 18px;
            background:
                linear-gradient(135deg, rgba(255,255,255,.96), rgba(255,255,255,.76)),
                radial-gradient(circle at 95% 14%, rgba(91,92,246,.20), transparent 18rem);
            box-shadow: var(--shadow);
        }
        .hero-card::before {
            content: "";
            position: absolute;
            top: -118px;
            right: -128px;
            width: 260px;
            height: 260px;
            border-radius: 999px;
            background: conic-gradient(from 160deg, rgba(91,92,246,.16), rgba(0,166,214,.18), rgba(139,92,246,.14), rgba(91,92,246,.16));
            filter: blur(2px);
        }
        h1, h2 { letter-spacing: -.035em; }
        h1 { position: relative; font-size: clamp(30px, 10vw, 50px); line-height: .98; margin: 0 0 8px; }
        h2 { font-size: clamp(22px, 7vw, 30px); line-height: 1.05; margin: 0 0 8px; }
        p { line-height: 1.52; }
        form.voice-form { display: flex; flex-direction: column; }
        label { display: block; font-weight: 850; margin: 14px 0 7px; color: #182230; }
        textarea, input, select {
            width: 100%;
            min-height: var(--tap);
            border: 1px solid rgba(16, 24, 40, .14);
            border-radius: 16px;
            padding: 14px 15px;
            font-size: 16px;
            background: rgba(255, 255, 255, .92);
            color: var(--text);
            box-shadow: inset 0 1px 1px rgba(16, 24, 40, .03);
            outline: none;
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease, transform .18s ease;
        }
        textarea {
            min-height: clamp(210px, 42dvh, 420px);
            resize: vertical;
            line-height: 1.52;
            scroll-margin-bottom: 110px;
        }
        .dialogue-textarea { min-height: clamp(230px, 46dvh, 460px); }
        textarea:focus, input:focus, select:focus {
            border-color: rgba(91, 92, 246, .72);
            box-shadow: 0 0 0 4px rgba(91, 92, 246, .12), inset 0 1px 1px rgba(16,24,40,.03);
            background: #fff;
        }
        audio { width: 100%; margin-top: 14px; filter: drop-shadow(0 12px 20px rgba(16,24,40,.08)); }
        button, .button {
            min-height: var(--tap);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border: 0;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary), var(--primary-2));
            color: #fff;
            padding: 14px 18px;
            font-weight: 900;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 13px 26px rgba(91, 92, 246, .24);
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
            touch-action: manipulation;
        }
        button:hover, .button:hover { transform: translateY(-1px); box-shadow: 0 16px 32px rgba(91, 92, 246, .30); filter: saturate(1.06); }
        button:active, .button:active { transform: translateY(0); }
        button.secondary, .button.secondary { background: linear-gradient(135deg, #101827, #344054); box-shadow: 0 13px 24px rgba(16,24,40,.20); }
        .actions { display: flex; gap: 10px; align-items: center; margin-top: 16px; flex-wrap: wrap; }
        .utility-actions { margin-top: 10px; }
        .submit-zone {
            position: sticky;
            bottom: calc(10px + var(--safe-bottom));
            z-index: 20;
            align-items: stretch;
            margin: 18px -4px 4px;
            padding: 10px;
            border: 1px solid rgba(91, 92, 246, .16);
            border-radius: 22px;
            background: rgba(255, 255, 255, .84);
            backdrop-filter: blur(18px);
            box-shadow: 0 18px 42px rgba(16, 24, 40, .18);
        }
        .submit-zone button, .submit-zone .button { width: 100%; min-height: 58px; font-size: 17px; }
        .grid, .grid2 { display: grid; grid-template-columns: 1fr; gap: 10px; align-items: end; }
        .dialogue-form > label[for="dialogue_text"] { order: 1; }
        .dialogue-form > #dialogue_text { order: 2; }
        .dialogue-form > .utility-actions { order: 3; }
        .dialogue-form > .dialogue-voices { order: 4; }
        .dialogue-form > .submit-zone { order: 5; }
        .dialogue-form > .dialogue-settings { order: 6; }
        .dialogue-form > .dialogue-pause { order: 7; }
        .dialogue-form > .hint { order: 8; }
        .dialogue-form > .advanced-settings { order: 9; }
        .single-form > label[for="text"] { order: 1; }
        .single-form > #text { order: 2; }
        .single-form > .utility-actions { order: 3; }
        .single-form > .tag-panel { order: 4; }
        .single-form > .single-settings { order: 5; }
        .single-form > .custom-voice { order: 6; }
        .single-form > .submit-zone { order: 7; }
        .single-form > .advanced-settings { order: 8; }
        .single-form > .hint { order: 9; }
        .muted { color: var(--muted); margin-top: 0; }
        .small { color: var(--muted); font-size: 13px; line-height: 1.45; }
        .alert {
            border-radius: 18px;
            padding: 13px 14px;
            margin-top: 12px;
            border: 1px solid transparent;
            box-shadow: 0 8px 20px rgba(16,24,40,.05);
        }
        .ok { background: var(--success-bg); border-color: var(--success-line); color: var(--success-text); }
        .error { background: var(--error-bg); border-color: var(--error-line); color: var(--error-text); white-space: pre-wrap; }
        .note { background: var(--note-bg); border-color: var(--note-line); color: var(--note-text); }
        .mode-tabs {
            position: sticky;
            top: 8px;
            z-index: 25;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding: 6px;
            margin: 0 0 14px;
            border: 1px solid rgba(255,255,255,.70);
            border-radius: 18px;
            background: rgba(255,255,255,.86);
            backdrop-filter: blur(16px);
            box-shadow: 0 12px 28px rgba(16,24,40,.10);
        }
        .mode-tab {
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 14px;
            color: #3538cd;
            background: transparent;
            text-decoration: none;
            font-weight: 900;
            font-size: 15px;
            box-shadow: none;
        }
        .mode-tab.is-active {
            background: linear-gradient(135deg, var(--primary), var(--primary-2));
            color: #fff;
            box-shadow: 0 10px 22px rgba(91,92,246,.24);
        }
        .tab-panel[hidden] { display: none; }
        .mode-card { scroll-margin-top: 86px; }
        .hint, .tag-panel, .advanced-settings {
            margin-top: 14px;
            padding: 14px;
            border: 1px solid rgba(16, 24, 40, .10);
            border-radius: 20px;
            background: rgba(248, 250, 252, .82);
            color: #344054;
            line-height: 1.55;
        }
        .hint-rows { display: grid; gap: 8px; }
        .hint-row { display: grid; gap: 5px; }
        .hint-label { font-weight: 900; color: #182230; }
        .advanced-settings { padding: 0; overflow: hidden; }
        .advanced-settings summary {
            min-height: var(--tap);
            display: flex;
            align-items: center;
            padding: 0 14px;
            font-weight: 900;
            cursor: pointer;
            list-style-position: inside;
            user-select: none;
        }
        .advanced-settings .advanced-content { padding: 0 14px 14px; }
        code {
            display: inline-block;
            max-width: 100%;
            background: rgba(91, 92, 246, .10);
            color: #3538cd;
            border: 1px solid rgba(91, 92, 246, .12);
            border-radius: 9px;
            padding: 3px 7px;
            margin: 1px 0;
            line-height: 1.35;
            vertical-align: baseline;
            overflow-wrap: anywhere;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }
        .custom-voice { display: none; }
        .custom-voice.is-visible { display: block; }
        .segments { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
        .segments a {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            color: #3538cd;
            background: rgba(91,92,246,.09);
            border: 1px solid rgba(91,92,246,.12);
            padding: 8px 10px;
            border-radius: 999px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
        }
        .tag-panel-title { margin: 0 0 4px; font-weight: 900; font-size: 17px; color: #182230; }
        .tag-panel-desc { margin: 0 0 12px; color: var(--muted); }
        .tag-group { margin-top: 14px; }
        .tag-group-title { font-weight: 900; margin-bottom: 8px; color: #344054; }
        .chips { display: flex; flex-wrap: nowrap; gap: 8px; overflow-x: auto; padding-bottom: 4px; scroll-snap-type: x proximity; -webkit-overflow-scrolling: touch; }
        .chip {
            flex: 0 0 auto;
            min-height: 44px;
            width: auto;
            background: rgba(91, 92, 246, .09);
            color: #3538cd;
            border: 1px solid rgba(91, 92, 246, .18);
            padding: 9px 12px;
            border-radius: 999px;
            font-size: 14px;
            box-shadow: none;
            scroll-snap-align: start;
            white-space: nowrap;
        }
        .chip:hover { box-shadow: 0 8px 18px rgba(91,92,246,.14); }
        .chip.is-copied { background: #dcfae6; color: #067647; border-color: #75e0a7; }
        .sample-row { display: grid; grid-template-columns: 1fr; gap: 8px; margin-top: 10px; }
        .sample-row code { padding: 8px 10px; line-height: 1.5; overflow-x: auto; }
        .app-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 6px 6px 18px;
            color: rgba(52, 64, 84, .82);
            font-size: 14px;
        }
        .app-footer a {
            color: #3538cd;
            font-weight: 900;
            text-decoration: none;
            border-bottom: 1px solid rgba(53,56,205,.25);
        }
        .app-footer a:hover { border-bottom-color: currentColor; }
        @media (min-width: 821px) {
            :root { --radius-lg: 28px; --tap: 48px; }
            .wrap { padding: 34px 18px; }
            .card { padding: 28px; margin-bottom: 22px; }
            .hero-card { padding: 34px; }
            .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
            .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
            textarea { min-height: 260px; }
            .dialogue-textarea { min-height: 230px; }
            button, .button { font-size: 15px; min-height: 48px; }
            .submit-zone {
                position: static;
                margin: 20px 0 0;
                padding: 0;
                border: 0;
                border-radius: 0;
                background: transparent;
                backdrop-filter: none;
                box-shadow: none;
            }
            .submit-zone button, .submit-zone .button { width: auto; min-height: 48px; font-size: 15px; }
            .mode-tabs { position: static; display: inline-grid; grid-template-columns: 170px 170px; border-radius: 18px; margin-bottom: 22px; }
            .mode-tab { min-width: 150px; }
            .chips { flex-wrap: wrap; overflow: visible; }
            .sample-row { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
        }
        @media (max-width: 420px) {
            .wrap { padding-inline: 8px; }
            .card { padding: 14px; border-radius: 22px; }
            .hero-card { padding: 16px; }
            h1 { font-size: 30px; }
            .submit-zone { margin-inline: -2px; }
            .mode-tab { font-size: 14px; }
        }
        @media (prefers-color-scheme: dark) {
            :root { color-scheme: dark; --surface: rgba(15, 23, 42, .84); --text: #eef2ff; --muted: #aab3c5; --line: rgba(255,255,255,.12); }
            body { background: radial-gradient(circle at 8% 4%, rgba(91,92,246,.30), transparent 30rem), radial-gradient(circle at 88% 8%, rgba(0,166,214,.22), transparent 30rem), linear-gradient(135deg, #020617, #0b1020 58%, #111827); color: var(--text); }
            body::before { background-image: linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px); }
            .card { background: var(--surface); border-color: rgba(255,255,255,.10); box-shadow: 0 22px 70px rgba(0,0,0,.28); }
            .hero-card { background: linear-gradient(135deg, rgba(15,23,42,.92), rgba(15,23,42,.72)), radial-gradient(circle at 90% 20%, rgba(91,92,246,.28), transparent 24rem); }
            label, .tag-panel-title { color: #f8fafc; }
            textarea, input, select { background: rgba(2, 6, 23, .72); color: #f8fafc; border-color: rgba(255,255,255,.14); }
            textarea:focus, input:focus, select:focus { background: rgba(2,6,23,.95); }
            .muted, .small, .tag-panel-desc { color: var(--muted); }
            .hint, .tag-panel, .advanced-settings { background: rgba(2, 6, 23, .48); border-color: rgba(255,255,255,.10); color: #cbd5e1; }
            .mode-tabs { background: rgba(15,23,42,.86); border-color: rgba(255,255,255,.12); }
            .mode-tab { color: #e0e7ff; }
            .mode-tab.is-active { color: #fff; }
            .hint-label { color: #f8fafc; }
            .submit-zone { background: rgba(15, 23, 42, .82); border-color: rgba(129,140,248,.22); }
            .tag-group-title { color: #e2e8f0; }
            code { background: rgba(129,140,248,.14); color: #c7d2fe; border-color: rgba(129,140,248,.20); }
            .chip, .segments a { background: rgba(129,140,248,.14); color: #e0e7ff; border-color: rgba(129,140,248,.22); }
            .chip.is-copied { background: rgba(6, 78, 59, .88); border-color: #10b981; color: #d1fae5; }
            button.secondary, .button.secondary { background: linear-gradient(135deg, #334155, #0f172a); color: #fff; }
            .note { background: rgba(69,26,3,.72); border-color: rgba(146,64,14,.8); color: #fde68a; }
            .ok { background: rgba(6,78,59,.55); border-color: rgba(16,185,129,.55); color: #d1fae5; }
            .error { background: rgba(127,29,29,.55); border-color: rgba(248,113,113,.45); color: #fee2e2; }
            .app-footer { color: rgba(203,213,225,.78); }
            .app-footer a { color: #c7d2fe; border-bottom-color: rgba(199,210,254,.30); }
        }
</style>
</head>
<body>
<div class="wrap">
    <div class="card hero-card">
        <h1>Fish Audio TTS</h1>
        <p class="muted">Озвучка текста, native multi-speaker диалоги Fish Audio и резервная склейка реплик.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= h($error) ?></div>
        <?php endforeach; ?>
        <?php foreach ($dialogueNotes as $note): ?>
            <div class="alert note"><?= h($note) ?></div>
        <?php endforeach; ?>

        <?php if (!$isLoggedIn): ?>
            <form method="post">
                <label for="login_password">Пароль</label>
                <input id="login_password" name="login_password" type="password" autocomplete="current-password" autofocus>
                <div class="actions submit-zone"><button type="submit">Войти</button></div>
            </form>
        <?php else: ?>
            <?php if (!file_exists($configPath)): ?>
                <div class="alert error">Нет config.php. Скопируйте config.example.php в config.php и вставьте Fish Audio API key.</div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert ok"><?= h($success) ?></div>
                <?php if ($audioUrl): ?>
                    <audio controls src="<?= h($audioUrl) ?>"></audio>
                    <div class="actions">
                        <a class="button secondary" href="<?= h($audioUrl) ?>" download><?= h((string)$audioLabel ?: 'Скачать аудио') ?></a>
                    </div>
                <?php endif; ?>
                <?php if ($segmentLinks): ?>
                    <div class="hint">
                        <strong>Отдельные реплики:</strong>
                        <div class="segments">
                            <?php foreach ($segmentLinks as $link): ?>
                                <a href="<?= h($link['url']) ?>" download><?= h($link['label']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="hint compact-hint">
                <div class="hint-rows">
                    <div class="hint-row"><span class="hint-label">Формат диалога</span><span><code>М: текст мужчины</code> или <code>Ж: текст женщины</code></span></div>
                    <div class="hint-row"><span class="hint-label">Fish native</span><span>Сайт сам превращает реплики в <code>&lt;|speaker:0|&gt;</code> и <code>&lt;|speaker:1|&gt;</code>, а <code>reference_id</code> отправляет массивом из двух голосов.</span></div>
                    <div class="hint-row"><span class="hint-label">Резерв</span><span>Склейка реплик остаётся на случай ошибки native-режима. ffmpeg сейчас: <strong><?= $ffmpegStatus ? 'доступен' : 'не найден/запрещён' ?></strong>.</span></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($isLoggedIn): ?>
    <div class="mode-tabs" role="tablist" aria-label="Режимы озвучки">
        <button type="button" class="mode-tab <?= $activeTab === 'dialogue' ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'dialogue' ? 'true' : 'false' ?>" aria-controls="dialogue-card" data-tab="dialogue">Диалог</button>
        <button type="button" class="mode-tab <?= $activeTab === 'single' ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'single' ? 'true' : 'false' ?>" aria-controls="single-card" data-tab="single">Один голос</button>
    </div>

    <div class="card mode-card tab-panel <?= $activeTab === 'dialogue' ? 'is-active' : '' ?>" id="dialogue-card" role="tabpanel" data-tab-panel="dialogue" <?= $activeTab === 'dialogue' ? '' : 'hidden' ?>>
        <h2>Диалог: мужчина + женщина</h2>
        <p class="muted">По умолчанию сначала используется native multi-speaker Fish Audio — один запрос и один цельный файл. Если API вернёт ошибку, режим «Авто» попробует старую склейку реплик.</p>
        <form method="post" class="voice-form dialogue-form">
            <input type="hidden" name="action" value="synthesize_dialogue">
            <input type="hidden" name="active_tab" value="dialogue">

            <label for="dialogue_text">Сценарий диалога</label>
            <textarea id="dialogue_text" class="dialogue-textarea" name="dialogue_text" required><?= h($lastDialogueText) ?></textarea>

            <div class="grid2 dialogue-voices">
                <div>
                    <label for="male_voice_preset">Голос мужчины / speaker:0</label>
                    <select id="male_voice_preset" name="male_voice_preset"><?= render_voice_options($voicePresets, $selectedMalePreset, false) ?></select>
                    <div id="customMaleVoiceWrap" class="custom-voice <?= $selectedMalePreset === '__custom__' ? 'is-visible' : '' ?>">
                        <label for="custom_male_reference_id">Свой Voice ID мужчины</label>
                        <input id="custom_male_reference_id" name="custom_male_reference_id" value="<?= h($customMaleReferenceId) ?>">
                    </div>
                </div>
                <div>
                    <label for="female_voice_preset">Голос женщины / speaker:1</label>
                    <select id="female_voice_preset" name="female_voice_preset"><?= render_voice_options($voicePresets, $selectedFemalePreset, false) ?></select>
                    <div id="customFemaleVoiceWrap" class="custom-voice <?= $selectedFemalePreset === '__custom__' ? 'is-visible' : '' ?>">
                        <label for="custom_female_reference_id">Свой Voice ID женщины</label>
                        <input id="custom_female_reference_id" name="custom_female_reference_id" value="<?= h($customFemaleReferenceId) ?>">
                    </div>
                </div>
            </div>

            <div class="grid dialogue-settings">
                <div>
                    <label for="dialogue_mode">Режим диалога</label>
                    <select id="dialogue_mode" name="dialogue_mode">
                        <option value="auto" <?= $dialogueMode === 'auto' ? 'selected' : '' ?>>Авто: Fish native → склейка при ошибке</option>
                        <option value="native" <?= $dialogueMode === 'native' ? 'selected' : '' ?>>Только Fish native multi-speaker</option>
                        <option value="segments" <?= $dialogueMode === 'segments' ? 'selected' : '' ?>>Только склейка реплик</option>
                    </select>
                </div>
                <div>
                    <label for="dialogue_output_format">Итоговый формат</label>
                    <select id="dialogue_output_format" name="dialogue_output_format">
                        <option value="mp3" <?= $dialogueOutputFormat === 'mp3' ? 'selected' : '' ?>>MP3</option>
                        <option value="wav" <?= $dialogueOutputFormat === 'wav' ? 'selected' : '' ?>>WAV</option>
                    </select>
                </div>
                <div>
                    <label for="model_dialogue_hint">Модель</label>
                    <select id="model_dialogue_hint" name="model">
                        <?php foreach (['s2.1-pro-free', 's2.1-pro', 's2-pro', 's1'] as $m): ?>
                            <option value="<?= h($m) ?>" <?= $selectedModel === $m ? 'selected' : '' ?>><?= h($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid dialogue-pause">
                <div>
                    <label for="pause_ms">Пауза для режима склейки, мс</label>
                    <input id="pause_ms" name="pause_ms" type="number" min="0" max="3000" step="50" value="<?= h(post_string('pause_ms', '350')) ?>">
                </div>
            </div>

            <div class="actions utility-actions">
                <button type="button" class="secondary js-tag-english" data-target="dialogue_text">Пометить английские слова [English]</button>
                <span class="small">Проверьте результат: кнопка может задеть бренды, ссылки и email.</span>
            </div>

            <div class="hint">
                <div class="hint-rows">
                    <div class="hint-row"><span class="hint-label">Пример сценария</span><span><code>М: [calm] Привет.</code></span><span><code>Ж: [excited] Сегодня разбираем [English]rainforest!</code></span></div>
                    <div class="hint-row"><span class="hint-label">Что отправится в Fish</span><span><code>&lt;|speaker:0|&gt;[calm] Привет.</code></span><span><code>&lt;|speaker:1|&gt;[excited] ...</code></span></div>
                </div>
            </div>

            <div class="actions submit-zone">
                <button type="submit">Озвучить диалог</button>
                <span class="small">Native-режим не требует ffmpeg и не создаёт отдельные реплики.</span>
            </div>

            <?php include __DIR__ . '/shared_controls.php'; ?>
        </form>
    </div>

    <div class="card mode-card tab-panel <?= $activeTab === 'single' ? 'is-active' : '' ?>" id="single-card" role="tabpanel" data-tab-panel="single" <?= $activeTab === 'single' ? '' : 'hidden' ?>>
        <h2>Обычная озвучка одним голосом</h2>
        <form method="post" class="voice-form single-form">
            <input type="hidden" name="action" value="synthesize">
            <input type="hidden" name="active_tab" value="single">

            <label for="text">Текст</label>
            <textarea id="text" name="text" required><?= h($lastText) ?></textarea>

            <div class="actions utility-actions">
                <button type="button" class="secondary js-tag-english" data-target="text">Пометить английские слова [English]</button>
                <span class="small">Проверьте результат: кнопка может задеть бренды, ссылки и email.</span>
            </div>

            <div class="tag-panel" aria-label="Подсказки по тегам Fish Audio">
                <p class="tag-panel-title">Теги для интонации и эффектов</p>
                <p class="tag-panel-desc">Клик по тегу копирует его в буфер. Теги — это подсказки модели, поэтому лучше тестировать на коротких фразах.</p>

                <div class="tag-group">
                    <div class="tag-group-title">Язык</div>
                    <div class="chips"><button type="button" class="chip js-copy-tag" data-copy="[English]">[English]</button></div>
                </div>

                <div class="tag-group">
                    <div class="tag-group-title">Диалог Fish native</div>
                    <div class="chips">
                        <button type="button" class="chip js-copy-tag" data-copy="<|speaker:0|>">&lt;|speaker:0|&gt;</button>
                        <button type="button" class="chip js-copy-tag" data-copy="<|speaker:1|>">&lt;|speaker:1|&gt;</button>
                    </div>
                </div>

                <div class="tag-group">
                    <div class="tag-group-title">Интонация / эмоции</div>
                    <div class="chips">
                        <?php foreach (['[calm]', '[confident]', '[excited]', '[curious]', '[sarcastic]', '[sad]', '[surprised]', '[angry]'] as $tag): ?>
                            <button type="button" class="chip js-copy-tag" data-copy="<?= h($tag) ?>"><?= h($tag) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="tag-group">
                    <div class="tag-group-title">Подача / голос</div>
                    <div class="chips">
                        <?php foreach (['[whisper]', '[emphasis]', '[softly]', '[speaking slowly]', '[teacher tone]'] as $tag): ?>
                            <button type="button" class="chip js-copy-tag" data-copy="<?= h($tag) ?>"><?= h($tag) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="tag-group">
                    <div class="tag-group-title">Звуки / паузы</div>
                    <div class="chips">
                        <?php foreach (['[laugh]', '[sigh]', '[gasp]', '[inhale]', '[exhale]', '[pause]'] as $tag): ?>
                            <button type="button" class="chip js-copy-tag" data-copy="<?= h($tag) ?>"><?= h($tag) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="tag-group">
                    <div class="tag-group-title">Готовые примеры</div>
                    <div class="sample-row">
                        <code>[calm] Сегодня разберём слово [English]rainforest.</code>
                        <button type="button" class="chip js-copy-tag" data-copy="[calm] Сегодня разберём слово [English]rainforest.">Копировать</button>
                    </div>
                    <div class="sample-row">
                        <code>[curious] А почему [English]greenhouse — это не зелёный дом?</code>
                        <button type="button" class="chip js-copy-tag" data-copy="[curious] А почему [English]greenhouse — это не зелёный дом?">Копировать</button>
                    </div>
                    <div class="sample-row">
                        <code>М: Привет. / Ж: Привет. [laugh]</code>
                        <button type="button" class="chip js-copy-tag" data-copy="М: Привет.\nЖ: Привет. [laugh]">Копировать</button>
                    </div>
                    <div class="sample-row">
                        <code>&lt;|speaker:0|&gt;Привет. &lt;|speaker:1|&gt;Привет. [laugh]</code>
                        <button type="button" class="chip js-copy-tag" data-copy="<|speaker:0|>Привет.\n<|speaker:1|>Привет. [laugh]">Копировать Fish native</button>
                    </div>
                </div>
            </div>

            <div class="grid single-settings">
                <div>
                    <label for="model">Модель</label>
                    <select id="model" name="model">
                        <?php foreach (['s2.1-pro-free', 's2.1-pro', 's2-pro', 's1'] as $m): ?>
                            <option value="<?= h($m) ?>" <?= $selectedModel === $m ? 'selected' : '' ?>><?= h($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="voice_preset">Голос</label>
                    <select id="voice_preset" name="voice_preset"><?= render_voice_options($voicePresets, $selectedVoicePreset, true) ?></select>
                </div>
                <div>
                    <label for="format">Формат</label>
                    <select id="format" name="format">
                        <?php foreach (['mp3', 'wav', 'opus'] as $f): ?>
                            <option value="<?= h($f) ?>" <?= $selectedFormat === $f ? 'selected' : '' ?>><?= h($f) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="customVoiceWrap" class="custom-voice <?= $selectedVoicePreset === '__custom__' ? 'is-visible' : '' ?>">
                <label for="custom_reference_id">Свой Voice ID / reference_id</label>
                <input id="custom_reference_id" name="custom_reference_id" value="<?= h($customReferenceId) ?>" placeholder="например: 2a1036d645634680b3cc69aeeb60375b">
            </div>

            <?php include __DIR__ . '/shared_controls.php'; ?>

            <div class="hint">
                Для S2/S2.1 лучше использовать квадратные скобки: <code>[English]rainforest</code>, <code>[whisper]</code>, <code>[laugh]</code>, <code>[pause]</code>.
                Сложные описания тоже можно пробовать: <code>[whispers sweetly]</code>, <code>[laughing nervously]</code>.
            </div>

            <div class="actions submit-zone">
                <button type="submit">Озвучить</button>
                <span class="small">API-ключ остаётся на сервере, в браузер не отдаётся.</span>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <footer class="app-footer">
        <span>Fish Audio TTS Web · лёгкая PHP-вебморда для озвучки</span>
        <span>Автор: <a href="https://it-uu.ru" target="_blank" rel="noopener noreferrer">it-uu.ru</a></span>
    </footer>
</div>
<script>
(function () {
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

    const tabs = document.querySelectorAll('.mode-tab');
    const panels = document.querySelectorAll('[data-tab-panel]');
    function activateTab(name) {
        tabs.forEach((tab) => {
            const isActive = tab.getAttribute('data-tab') === name;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        panels.forEach((panel) => {
            panel.hidden = panel.getAttribute('data-tab-panel') !== name;
        });
        try { window.localStorage.setItem('fish_tts_active_tab', name); } catch (e) {}
    }
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => activateTab(tab.getAttribute('data-tab')));
    });
    try {
        const savedTab = window.localStorage.getItem('fish_tts_active_tab');
        if (savedTab && !document.querySelector('.alert.ok')) activateTab(savedTab);
    } catch (e) {}

    function tagEnglish(text) {
        return text.replace(/\b[A-Za-z][A-Za-z0-9+.#'’\-]*\b/g, function (match, offset, full) {
            const before = full.slice(Math.max(0, offset - 9), offset);
            const after = full.slice(offset + match.length, offset + match.length + 1);
            const prev = full.slice(Math.max(0, offset - 1), offset);
            if (match === 'English' && prev === '[' && after === ']') return match;
            if (before === '[English]') return match;
            if (/^(https?|www|mailto)$/i.test(match)) return match;
            if (/^speaker$/i.test(match)) return match;
            return '[English]' + match;
        });
    }

    document.querySelectorAll('.js-tag-english').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.getAttribute('data-target'));
            if (!target) return;
            target.value = tagEnglish(target.value);
            target.focus();
        });
    });

    const copyToClipboard = async (value) => {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(value);
            return;
        }
        const tmp = document.createElement('textarea');
        tmp.value = value;
        tmp.setAttribute('readonly', '');
        tmp.style.position = 'fixed';
        tmp.style.left = '-9999px';
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
    };

    document.querySelectorAll('.js-copy-tag').forEach((button) => {
        const originalText = button.textContent;
        button.addEventListener('click', async () => {
            const value = button.getAttribute('data-copy') || originalText;
            try {
                await copyToClipboard(value);
                button.classList.add('is-copied');
                button.textContent = 'Скопировано';
                window.setTimeout(() => {
                    button.classList.remove('is-copied');
                    button.textContent = originalText;
                }, 900);
            } catch (e) {
                alert('Не получилось скопировать. Скопируйте вручную: ' + value);
            }
        });
    });
})();
</script>
</body>
</html>
