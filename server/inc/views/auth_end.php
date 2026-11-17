    </div>
  </div>
  <p class="text-center small text-body-secondary mt-4">© <?= date('Y') ?> <?= e(cfg('app_name')) ?></p>
</div>
<?php require __DIR__ . '/scripts.php'; ?>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e(url($s)) ?>?v=<?= e(APP_VERSION) ?>"></script>
<?php endforeach; ?>
</body>
</html>
