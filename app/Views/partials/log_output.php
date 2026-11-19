<pre id="logs" class="is-size-7" data-log-output tabindex="0"
     aria-label="<?= esc($app['name'], 'attr') ?> logs"
     style="max-height: 65vh; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere"><?php foreach ($lines as $line): ?><span id="<?= esc($line['id'], 'attr') ?>"><?= esc($line['text']) ?></span><?php endforeach ?></pre>
