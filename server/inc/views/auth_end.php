    </div>
  </div>
  <p class="text-center small text-body-secondary mt-4">© <?= date('Y') ?> <?= e(cfg('app_name')) ?></p>
</div>
<?php require __DIR__ . '/scripts.php'; ?>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e(url($s)) ?>?v=2"></script>
<?php endforeach; ?>
</body>
</html>
