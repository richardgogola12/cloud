<?php /** @var string $qName @var float $qVal @var string $qUnit @var bool $qUnl */ ?>
<div class="quota-input" data-quota="<?= e($qName) ?>">
  <div class="input-group">
    <input type="number" class="form-control" name="quota_val" min="0" step="any" value="<?= e($qVal) ?>" <?= $qUnl ? 'disabled' : '' ?>>
    <select class="form-select select2 quota-unit" name="quota_unit" data-search="false" style="max-width:110px">
      <?php foreach (['MB', 'GB', 'TB'] as $un): ?><option <?= $qUnit === $un ? 'selected' : '' ?>><?= $un ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="d-flex flex-wrap gap-1 mt-2">
    <?php foreach (['1 GB' => [1, 'GB'], '5 GB' => [5, 'GB'], '10 GB' => [10, 'GB'], '50 GB' => [50, 'GB'], '100 GB' => [100, 'GB'], '1 TB' => [1, 'TB']] as $lbl => [$v, $u]): ?>
      <button type="button" class="btn btn-sm btn-light rounded-pill quota-preset" data-v="<?= $v ?>" data-u="<?= $u ?>"><?= $lbl ?></button>
    <?php endforeach; ?>
  </div>
  <div class="form-check form-switch mt-2">
    <input class="form-check-input quota-unl" type="checkbox" name="unlimited" value="1" id="unl_<?= e($qName) ?>" <?= $qUnl ? 'checked' : '' ?>>
    <label class="form-check-label" for="unl_<?= e($qName) ?>">Bez obmedzenia</label>
  </div>
</div>
