  </main>
</div>
<nav class="bnav" id="bnav" aria-label="Rýchla navigácia">
  <?php foreach ([['drive', 'index.php', 'bi-hdd-stack', 'Môj disk'], ['recent', 'index.php#recent', 'bi-clock-history', 'Nedávne'], ['photos', 'index.php#photos', 'bi-images', 'Fotky'], ['shared', 'index.php#shared', 'bi-people', 'Zdieľané']] as [$k, $h, $ic, $lb]): ?>
  <a href="<?= e(url($h)) ?>" data-nav="<?= $k ?>" class="<?= ($active ?? '') === $k ? 'active' : '' ?>"><i class="bi <?= $ic ?>"></i><span><?= e($lb) ?></span></a>
  <?php endforeach; ?>
</nav>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>
<?php require __DIR__ . '/scripts.php'; ?>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e(url($s)) ?>?v=<?= e(APP_VERSION) ?>"></script>
<?php endforeach; ?>
</body>
</html>
