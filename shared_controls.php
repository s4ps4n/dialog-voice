<details class="advanced-settings">
    <summary>Расширенные настройки аудио</summary>
    <div class="advanced-content">
<div class="grid">
    <div>
        <label for="mp3_bitrate_<?= h((string)rand(1000,9999)) ?>">MP3 bitrate</label>
        <select name="mp3_bitrate">
            <?php foreach ([64, 128, 192] as $b): ?>
                <option value="<?= $b ?>" <?= post_int('mp3_bitrate', 128, 64, 192) === $b ? 'selected' : '' ?>><?= $b ?> kbps</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Выразительность: 0–1</label>
        <input name="temperature" type="number" min="0" max="1" step="0.05" value="<?= h(post_string('temperature', '0.7')) ?>">
    </div>
    <div>
        <label>Вариативность: 0–1</label>
        <input name="top_p" type="number" min="0" max="1" step="0.05" value="<?= h(post_string('top_p', '0.7')) ?>">
    </div>
</div>

<div class="grid">
    <div>
        <label>Скорость</label>
        <input name="speed" type="number" min="0.5" max="2" step="0.05" value="<?= h(post_string('speed', '1.0')) ?>">
    </div>
    <div>
        <label>Громкость</label>
        <input name="volume" type="number" min="-10" max="10" step="0.5" value="<?= h(post_string('volume', '0')) ?>">
    </div>
    <div>
        <label>Режим</label>
        <select name="latency">
            <?php foreach (['normal', 'balanced', 'low'] as $l): ?>
                <option value="<?= h($l) ?>" <?= $selectedLatency === $l ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<label>Размер чанка: 100–300</label>
<input name="chunk_length" type="number" min="100" max="300" step="10" value="<?= h(post_string('chunk_length', '300')) ?>">

    </div>
</details>
  
