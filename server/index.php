<?php
require __DIR__ . '/inc/core.php';
$user = require_login();
$title = 'Môj disk';
$active = 'drive';
$scripts = ['assets/js/passkey.js', 'assets/js/drive.js'];
require __DIR__ . '/inc/views/layout_start.php';
?>
<?php if (!empty($_SESSION['offer_passkey'])): ?>
<div id="passkeyOffer" class="alert alert-primary d-none d-flex align-items-center gap-3 rounded-4 border-0 shadow-sm">
  <i class="bi bi-fingerprint fs-2"></i>
  <div class="flex-grow-1"><div class="fw-semibold">Prihlasuj sa odtlačkom prsta</div><div class="small">Rýchlejšie a bezpečnejšie ako heslo – na tomto zariadení.</div></div>
  <button class="btn btn-primary btn-sm rounded-pill" data-pk-add>Zapnúť</button>
  <button class="btn-close" data-pk-dismiss aria-label="Zavrieť"></button>
</div>
<?php endif; ?>

<div class="drive-head">
  <nav aria-label="breadcrumb" class="flex-grow-1 min-w-0"><ol class="breadcrumb drive-crumbs mb-0" id="crumbs"></ol></nav>
  <button class="btn btn-sm btn-outline-secondary rounded-pill d-none relock-btn" id="relockBtn" title="Zamknúť zložku – obsah sa skryje, kým znova nezadáš heslo"><i class="bi bi-lock"></i><span class="d-none d-sm-inline ms-1">Zamknúť</span></button>
  <div class="btn-group view-switch" role="group">
    <button class="btn btn-icon" data-view="list" title="Zoznam"><i class="bi bi-list-ul"></i></button>
    <button class="btn btn-icon" data-view="grid" title="Mriežka"><i class="bi bi-grid-3x3-gap"></i></button>
  </div>
  <div class="dropdown" id="colsDrop">
    <button class="btn btn-icon" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="Stĺpce v zozname"><i class="bi bi-layout-three-columns"></i></button>
    <div class="dropdown-menu dropdown-menu-end shadow cols-menu" id="colsMenu"></div>
  </div>
  <div class="dropdown">
    <button class="btn btn-icon" data-bs-toggle="dropdown" title="Zoradiť"><i class="bi bi-sort-down"></i></button>
    <ul class="dropdown-menu dropdown-menu-end shadow" id="sortMenu">
      <li><h6 class="dropdown-header">Zoradiť podľa</h6></li>
      <li><a class="dropdown-item" href="#" data-sort="name">Názov</a></li>
      <li><a class="dropdown-item" href="#" data-sort="updated">Dátum zmeny</a></li>
      <li><a class="dropdown-item" href="#" data-sort="size">Veľkosť</a></li>
      <li><a class="dropdown-item" href="#" data-sort="created">Dátum vytvorenia</a></li>
      <li><a class="dropdown-item" href="#" data-sort="kind">Druh</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="#" data-dir="asc">Vzostupne</a></li>
      <li><a class="dropdown-item" href="#" data-dir="desc">Zostupne</a></li>
    </ul>
  </div>
</div>

<div class="search-chips d-none" id="searchChips"></div>
<div class="trash-bar d-none" id="trashBar">
  <i class="bi bi-info-circle"></i>
  <span class="flex-grow-1 small" id="trashInfo">Položky v koši sa natrvalo zmažú po 30 dňoch.</span>
  <button class="btn btn-sm btn-outline-danger rounded-pill" id="emptyTrash"><i class="bi bi-trash3 me-1"></i>Vysypať kôš</button>
</div>
<div class="shared-bar d-none small text-body-secondary mb-2" id="sharedBar"><i class="bi bi-people me-1"></i><span></span></div>

<!-- panel pre hromadné akcie -->
<div class="selection-bar d-none" id="selBar">
  <button class="btn btn-icon" data-sel="clear" title="Zrušiť výber"><i class="bi bi-x-lg"></i></button>
  <span class="fw-semibold me-auto" id="selCount">0 vybraté</span>
  <button class="btn btn-icon" data-sel="restore" data-in="trash" title="Obnoviť"><i class="bi bi-arrow-counterclockwise"></i></button>
  <button class="btn btn-icon text-danger" data-sel="purge" data-in="trash" title="Zmazať natrvalo"><i class="bi bi-x-octagon"></i></button>
  <button class="btn btn-icon" data-sel="tags" data-not="trash" title="Štítky a farba"><i class="bi bi-tags"></i></button>
  <button class="btn btn-icon" data-sel="download" data-not="trash" title="Stiahnuť"><i class="bi bi-download"></i></button>
  <button class="btn btn-icon" data-sel="move" data-not="trash" data-perm="edit" title="Presunúť"><i class="bi bi-folder-symlink"></i></button>
  <button class="btn btn-icon" data-sel="copy" data-not="trash" title="Kopírovať"><i class="bi bi-copy"></i></button>
  <button class="btn btn-icon" data-sel="star" data-not="trash" data-perm="owner" title="Hviezdička"><i class="bi bi-star"></i></button>
  <button class="btn btn-icon" data-sel="leave" data-in="shared-root" title="Odstrániť zo zdieľaných"><i class="bi bi-person-dash"></i></button>
  <button class="btn btn-icon text-danger" data-sel="delete" data-not="trash" data-perm="edit" title="Do koša"><i class="bi bi-trash3"></i></button>
</div>

<div class="photo-bar d-none" id="photoBar"></div>
<div id="listHeader" class="list-header d-none">
  <div class="c-check"><input type="checkbox" class="form-check-input" id="checkAll"></div>
  <div class="c-name sortable" data-sort-col="name">Názov</div><div class="c-cols" id="headCols"></div><div class="c-act"></div>
</div>
<div id="items" class="items"></div>
<div id="empty" class="empty-state d-none">
  <div class="empty-icon"><i class="bi bi-cloud-arrow-up"></i></div>
  <h5 class="fw-semibold">Zatiaľ tu nič nie je</h5>
  <p class="text-body-secondary">Pretiahni sem súbory alebo použi tlačidlo <b>Nový</b>.</p>
  <button class="btn btn-primary rounded-pill px-4" data-action="upload-files"><i class="bi bi-upload me-2"></i>Nahrať súbory</button>
</div>
<div id="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>

<!-- mobilné tlačidlo -->
<div class="dropup fab-wrap d-lg-none">
  <button class="fab shadow" data-bs-toggle="dropdown" aria-label="Pridať"><i class="bi bi-plus-lg"></i></button>
  <ul class="dropdown-menu dropdown-menu-end shadow mb-2">
    <li><a class="dropdown-item py-2" href="#" data-action="new-folder"><i class="bi bi-folder-plus me-2"></i>Nová zložka</a></li>
    <li><a class="dropdown-item py-2" href="#" data-action="new-text"><i class="bi bi-file-earmark-text me-2"></i>Nový textový súbor</a></li>
    <li><a class="dropdown-item py-2" href="#" data-action="upload-files"><i class="bi bi-file-earmark-arrow-up me-2"></i>Nahrať súbory</a></li>
    <li><a class="dropdown-item py-2" href="#" data-action="upload-camera"><i class="bi bi-camera me-2"></i>Odfotiť a nahrať</a></li>
    <li><a class="dropdown-item py-2" href="#" data-action="upload-folder"><i class="bi bi-folder-symlink me-2"></i>Nahrať zložku</a></li>
  </ul>
</div>

<input type="file" id="fileInput" multiple hidden>
<input type="file" id="folderInput" webkitdirectory multiple hidden>
<input type="file" id="cameraInput" accept="image/*,video/*" capture="environment" hidden>

<div class="drop-overlay" id="dropOverlay"><div><i class="bi bi-cloud-arrow-up"></i><div>Pusti súbory pre nahratie</div></div></div>

<!-- panel nahrávania -->
<div class="upload-panel shadow-lg d-none" id="uploadPanel">
  <div class="upload-head">
    <span id="uploadTitle">Nahrávanie…</span>
    <button class="btn btn-icon btn-sm" id="uploadToggle"><i class="bi bi-chevron-down"></i></button>
    <button class="btn btn-icon btn-sm" id="uploadClose"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="upload-list" id="uploadList"></div>
</div>

<!-- kontextové menu (zdieľané) -->
<ul class="dropdown-menu shadow ctx-menu" id="ctxMenu">
  <li><a class="dropdown-item" href="#" data-ctx="open"><i class="bi bi-box-arrow-up-right me-2"></i>Otvoriť</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="openapp"><i class="bi bi-box-arrow-up-right me-2"></i>Otvoriť v aplikácii…</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="send"><i class="bi bi-send me-2"></i>Poslať…</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="edit"><i class="bi bi-pencil-square me-2"></i>Upraviť text</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="download"><i class="bi bi-download me-2"></i>Stiahnuť</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="share"><i class="bi bi-person-plus me-2"></i>Zdieľať</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="tags"><i class="bi bi-tags me-2"></i>Štítky a farba…</a></li>
  <li data-sep><hr class="dropdown-divider"></li>
  <li><a class="dropdown-item" href="#" data-ctx="rename"><i class="bi bi-input-cursor-text me-2"></i>Premenovať</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="move"><i class="bi bi-folder-symlink me-2"></i>Presunúť</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="copy"><i class="bi bi-copy me-2"></i>Vytvoriť kópiu</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="star"><i class="bi bi-star me-2"></i><span>Pridať hviezdičku</span></a></li>
  <li><a class="dropdown-item" href="#" data-ctx="versions"><i class="bi bi-clock-history me-2"></i>Verzie</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="info"><i class="bi bi-info-circle me-2"></i>Informácie</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="albumadd"><i class="bi bi-journal-plus me-2"></i>Pridať do albumu…</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="albumremove"><i class="bi bi-journal-minus me-2"></i>Odstrániť z albumu</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="albumcover"><i class="bi bi-image me-2"></i>Nastaviť ako obal albumu</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="activity"><i class="bi bi-activity me-2"></i>Aktivita</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="rewind"><i class="bi bi-rewind me-2"></i>Vrátiť do stavu k…</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="lock"><i class="bi bi-lock me-2"></i>Zamknúť heslom…</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="lockedit"><i class="bi bi-key me-2"></i>Heslo zložky…</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="relock"><i class="bi bi-lock-fill me-2"></i>Zamknúť teraz</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="restore"><i class="bi bi-arrow-counterclockwise me-2"></i>Obnoviť</a></li>
  <li><a class="dropdown-item" href="#" data-ctx="leave"><i class="bi bi-person-dash me-2"></i>Odstrániť zo zdieľaných</a></li>
  <li data-sep2><hr class="dropdown-divider"></li>
  <li><a class="dropdown-item text-danger" href="#" data-ctx="delete"><i class="bi bi-trash3 me-2"></i>Presunúť do koša</a></li>
  <li><a class="dropdown-item text-danger" href="#" data-ctx="purge"><i class="bi bi-x-octagon me-2"></i>Zmazať natrvalo</a></li>
</ul>

<!-- presun / kópia – Select2 -->
<div class="modal fade" id="moveModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="moveForm">
      <div class="modal-header border-0"><h5 class="modal-title" id="moveTitle">Presunúť do</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <label class="form-label small text-body-secondary">Cieľová zložka</label>
        <select id="moveTarget" class="form-select"></select>
      </div>
      <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zrušiť</button><button class="btn btn-primary rounded-pill px-4" id="moveOk">Presunúť</button></div>
    </form>
  </div>
</div>

<!-- náhľad -->
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content preview-content">
      <div class="preview-bar">
        <button class="btn btn-icon text-white" data-bs-dismiss="modal"><i class="bi bi-arrow-left"></i></button>
        <span class="text-truncate flex-grow-1 fw-semibold" id="previewName"></span>
        <a class="btn btn-icon text-white d-none" id="previewOpenApp" title="Otvoriť v aplikácii"><i class="bi bi-box-arrow-up-right"></i></a>
        <a class="btn btn-icon text-white d-none" id="previewSend" title="Poslať"><i class="bi bi-send"></i></a>
        <button class="btn btn-icon text-white" id="previewPlay" title="Prezentácia"><i class="bi bi-play-circle"></i></button>
        <a class="btn btn-icon text-white" id="previewDownload" title="Stiahnuť"><i class="bi bi-download"></i></a>
      </div>
      <button class="preview-nav prev" id="previewPrev"><i class="bi bi-chevron-left"></i></button>
      <div class="preview-body" id="previewBody"></div>
      <button class="preview-nav next" id="previewNext"><i class="bi bi-chevron-right"></i></button>
    </div>
  </div>
</div>

<!-- informácie -->
<div class="modal fade" id="infoModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0"><h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Informácie</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><dl class="row mb-0" id="infoBody"></dl><div id="infoAct" class="info-act"></div></div>
    </div>
  </div>
</div>
<!-- zdieľanie -->
<div class="modal fade" id="shareModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title text-truncate" id="shareTitle">Zdieľať</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <ul class="nav nav-pills admin-tabs mb-3" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#shUsers" type="button"><i class="bi bi-people me-1"></i>Používatelia</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#shLink" type="button"><i class="bi bi-link-45deg me-1"></i>Verejný odkaz</button></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="shUsers">
            <div class="row g-2 align-items-end mb-2">
              <div class="col-md"><label class="form-label small text-body-secondary">Pridať ľudí (meno alebo e-mail)</label><select id="shUserSel" multiple></select></div>
              <div class="col-7 col-md-3"><select id="shPerm" class="form-select" data-search="false"><option value="view">Iba čítanie</option><option value="edit">Môže upravovať</option></select></div>
              <div class="col-5 col-md-auto"><button class="btn btn-primary rounded-pill w-100" id="shAdd">Zdieľať</button></div>
            </div>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="shNotify" checked><label class="form-check-label small" for="shNotify">Upozorniť e-mailom</label></div>
            <div class="small fw-semibold mb-1">Ľudia s prístupom</div>
            <ul class="list-group list-group-flush" id="shList"></ul>
          </div>
          <div class="tab-pane fade" id="shLink">
            <div id="shLinkNone">
              <p class="text-body-secondary small">Ktokoľvek s odkazom bude môcť zobraziť a stiahnuť <span data-what>položku</span> bez prihlásenia.</p>
            </div>
            <div id="shLinkBox" class="d-none mb-3">
              <div class="input-group"><input class="form-control" id="shUrl" readonly><button class="btn btn-outline-primary" id="shCopy"><i class="bi bi-clipboard me-1"></i>Kopírovať</button></div>
              <div class="small text-body-secondary mt-1" id="shStats"></div>
            </div>
            <div class="mb-3" id="shModeWrap">
              <label class="form-label small">Čo môže návštevník</label>
              <select class="form-select" id="shMode" data-search="false">
                <option value="view">Zobraziť a stiahnuť</option>
                <option value="upload">Iba nahrať súbory (obsah zložky neuvidí)</option>
                <option value="both">Zobraziť, stiahnuť aj nahrať</option>
              </select>
            </div>
            <div class="row g-3">
              <div class="col-sm-6"><label class="form-label small">Platnosť do (voliteľné)</label><input type="date" class="form-control" id="shExpires"></div>
              <div class="col-sm-6"><label class="form-label small">Heslo (voliteľné)</label><input type="text" class="form-control" id="shPassword" autocomplete="off" placeholder="bez hesla">
                <div class="form-check mt-1 d-none" id="shPwClearWrap"><input class="form-check-input" type="checkbox" id="shPwClear"><label class="form-check-label small" for="shPwClear">Odstrániť heslo</label></div></div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <button class="btn btn-primary rounded-pill px-4" id="shSave"><i class="bi bi-link-45deg me-1"></i><span>Vytvoriť odkaz</span></button>
              <button class="btn btn-outline-danger rounded-pill d-none" id="shDelete">Zrušiť odkaz</button>
              <button class="btn btn-light rounded-pill d-none" id="shNative"><i class="bi bi-share me-1"></i>Poslať…</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- štítky a farba vybraných položiek -->
<div class="modal fade" id="tagsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title text-truncate" id="tagsTitle">Štítky a farba</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3" id="colorBlock">
          <label class="form-label small text-body-secondary mb-1" id="colorLabel">Farba</label>
          <div class="color-palette" id="itemColors"></div>
        </div>
        <div class="mb-3" id="tagBlockD">
          <label class="form-label small text-body-secondary mb-1"><i class="bi bi-folder me-1"></i>Štítky zložiek</label>
          <select id="tagSelD" multiple></select>
        </div>
        <div class="mb-1" id="tagBlockF">
          <label class="form-label small text-body-secondary mb-1"><i class="bi bi-file-earmark me-1"></i>Štítky súborov</label>
          <select id="tagSelF" multiple></select>
        </div>
        <div class="form-text">Nový štítok vytvoríš tak, že napíšeš jeho názov a stlačíš Enter. Štítky sú osobné – vidíš ich iba ty.</div>
      </div>
      <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zrušiť</button><button class="btn btn-primary rounded-pill px-4" id="tagsSave">Uložiť</button></div>
    </div>
  </div>
</div>

<!-- správa štítkov -->
<div class="modal fade" id="tagManageModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title"><i class="bi bi-tags me-2"></i>Štítky</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form class="tag-new mb-3" id="tagNewForm">
          <div class="d-flex gap-2 mb-2">
            <input class="form-control" id="tagNewName" maxlength="50" placeholder="Nový štítok, napr. Faktúry" required>
            <select class="form-select" id="tagNewScope" style="max-width:130px"><option value="f">pre súbory</option><option value="d">pre zložky</option></select>
          </div>
          <div class="d-flex align-items-center gap-2">
            <div class="color-palette flex-grow-1" id="tagNewColors"></div>
            <button class="btn btn-primary rounded-pill px-3">Pridať</button>
          </div>
        </form>
        <div class="small fw-semibold text-body-secondary mb-1"><i class="bi bi-folder me-1"></i>Štítky zložiek</div>
        <ul class="list-group list-group-flush mb-3" id="tagListD"></ul>
        <div class="small fw-semibold text-body-secondary mb-1"><i class="bi bi-file-earmark me-1"></i>Štítky súborov</div>
        <ul class="list-group list-group-flush" id="tagListF"></ul>
      </div>
    </div>
  </div>
</div>

<!-- verzie -->
<div class="modal fade" id="versionsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title text-truncate" id="verTitle">Verzie</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="small text-body-secondary mb-2" id="verHint"></p>
        <ul class="list-group list-group-flush" id="verList"></ul>
        <label class="btn btn-outline-primary rounded-pill w-100 mt-3 d-none" id="verUploadBtn"><i class="bi bi-upload me-1"></i>Nahrať novú verziu<input type="file" hidden id="verUpload"></label>
      </div>
    </div>
  </div>
</div>

<!-- textový editor -->
<div class="modal fade" id="editorModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-fullscreen-md-down modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <i class="bi bi-file-earmark-text me-2 text-primary"></i><h5 class="modal-title text-truncate flex-grow-1 fs-6" id="edName"></h5>
        <span class="small text-body-secondary me-2" id="edStatus"></span>
        <button class="btn btn-primary btn-sm rounded-pill px-3 me-2" id="edSave"><i class="bi bi-floppy me-1"></i>Uložiť</button>
        <button type="button" class="btn-close" id="edClose"></button>
      </div>
      <div class="modal-body p-0"><textarea id="edText" class="editor-area" spellcheck="false"></textarea></div>
    </div>
  </div>
</div>
<script>
  // stĺpce v zozname: dostupné stĺpce, predvolené a vlastný výber používateľa
  window.CLOUD_LIB = <?= json_encode([
    'leafletJs' => lib('leaflet/leaflet.js', 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js'),
    'leafletCss' => lib('leaflet/leaflet.css', 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css'),
    'clusterJs' => lib('leaflet/leaflet.markercluster.js', 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js'),
    'clusterCss' => lib('leaflet/MarkerCluster.css', 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.css'),
  ], JSON_UNESCAPED_SLASHES) ?>;
  window.CLOUD_UID = <?= (int)$user['id'] ?>;
  window.CLOUD_COLS = <?= json_encode(['labels' => LIST_COLUMNS, 'views' => LIST_VIEWS, 'defaults' => DEFAULT_COLUMNS, 'mine' => (object)(user_prefs($user)['columns'] ?? [])], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php require __DIR__ . '/inc/views/layout_end.php'; ?>
