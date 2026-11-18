  </main>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>
<?php require __DIR__ . '/scripts.php'; ?>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e(url($s)) ?>?v=<?= e(APP_VERSION) ?>"></script>
<?php endforeach; ?>
</body>
</html>
