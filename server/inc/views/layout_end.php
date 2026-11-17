  </main>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>
<?php require __DIR__ . '/scripts.php'; ?>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e(url($s)) ?>?v=2"></script>
<?php endforeach; ?>
</body>
</html>
