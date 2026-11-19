<?php require __DIR__ . '/head.php'; ?>
<body class="auth-page">
<script>try{Object.keys(localStorage).forEach(function(k){if(k.indexOf('cloud-lc-')===0)localStorage.removeItem(k)})}catch(e){}</script>
<div class="auth-wrap">
  <div class="auth-card card border-0 shadow-lg">
    <div class="card-body p-4 p-sm-5">
      <div class="text-center mb-4">
        <span class="brand-logo brand-logo-lg mb-3"><i class="bi bi-cloud-fill"></i></span>
        <h1 class="h4 fw-bold mb-1"><?= e($heading ?? cfg('app_name')) ?></h1>
        <?php if (!empty($subheading)): ?><p class="text-body-secondary mb-0"><?= e($subheading) ?></p><?php endif; ?>
      </div>
      <?= flashes() ?>
