<script src="<?= e(lib('jquery/jquery.min.js', 'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js')) ?>"></script>
<script src="<?= e(lib('bootstrap/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(lib('select2/select2.min.js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/common.js')) ?>?v=<?= e(APP_VERSION) ?>"></script>
<?php if (!empty($user['id'])): ?>
<script>window.CLOUD_HELP_IMG = <?= json_encode(url('assets/help/'), JSON_UNESCAPED_SLASHES) ?>; window.CLOUD_VER = <?= json_encode(APP_VERSION) ?>;<?php
  // sprievodca sa sám otvorí na disku, kým ho používateľ nedokončí alebo nedá „Viac nezobrazovať“
  if (!empty($user) && ($active ?? '') === 'drive' && (user_prefs($user)['tour'] ?? '') !== 'done' && empty($_SESSION['tour_later'])): ?> window.CLOUD_TOUR = true;<?php endif; ?></script>
<script src="<?= e(url('assets/js/tour.js')) ?>?v=<?= e(APP_VERSION) ?>"></script>
<?php endif; ?>
