<?php
$scope = isset($controlScope) ? preg_replace('/[^a-z0-9_-]/i', '', (string)$controlScope) : 'audio';
?>
<details class="advanced-settings">
    <summary>Расширенные настройки</summary>
    <div class="advanced-content">
        <div class="field-grid two">
            <div>
                <label for="<?= h($scope) ?>_temperature">Выразительность</label>
                <input id="<?= h($scope) ?>_temperature" name="temperature" type="number" min="0" max="1" step="0.05" value="<?= h(post_string('temperature', '0.7')) ?>">
            </div>
            <div>
                <label for="<?= h($scope) ?>_top_p">Вариативность</label>
                <input id="<?= h($scope) ?>_top_p" name="top_p" type="number" min="0" max="1" step="0.05" value="<?= h(post_string('top_p', '0.7')) ?>">
            </div>
            <div>
                <label for="<?= h($scope) ?>_speed">Скорость</label>
                <input id="<?= h($scope) ?>_speed" name="speed" type="number" min="0.5" max="2" step="0.05" value="<?= h(post_string('speed', '1.0')) ?>">
            </div>
            <div>
                <label for="<?= h($scope) ?>_volume">Громкость</label>
                <input id="<?= h($scope) ?>_volume" name="volume" type="number" min="-10" max="10" step="0.5" value="<?= h(post_string('volume', '0')) ?>">
            </div>
            <div>
                <label for="<?= h($scope) ?>_latency">Режим</label>
                <select id="<?= h($scope) ?>_latency" name="latency">
                    <?php foreach (['normal', 'balanced', 'low'] as $l): ?>
                        <option value="<?= h($l) ?>" <?= $selectedLatency === $l ? 'selected' : '' ?>><?= h($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="<?= h($scope) ?>_bitrate">MP3 bitrate</label>
                <select id="<?= h($scope) ?>_bitrate" name="mp3_bitrate">
                    <?php foreach ([64, 128, 192] as $b): ?>
                        <option value="<?= $b ?>" <?= post_int('mp3_bitrate', 128, 64, 192) === $b ? 'selected' : '' ?>><?= $b ?> kbps</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <label for="<?= h($scope) ?>_chunk">Размер чанка</label>
        <input id="<?= h($scope) ?>_chunk" name="chunk_length" type="number" min="100" max="300" step="10" value="<?= h(post_string('chunk_length', '300')) ?>">
    </div>
</details>
