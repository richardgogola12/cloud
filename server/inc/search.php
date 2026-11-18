<?php
/* Vyhľadávanie ako v Google Disku: filtre (typ, vlastník, umiestnenie, dátum, veľkosť, štítok),
 * operátory v texte (type:pdf owner:me in:trash is:starred after:2024-01-01 size>10mb tag:…),
 * položky zdieľané so mnou a voliteľne aj obsah dokumentov (fulltextový index). */

const SEARCH_TYPES = [
    'folder'  => ['label' => 'Zložky', 'icon' => 'bi-folder-fill'],
    'doc'     => ['label' => 'Dokumenty', 'icon' => 'bi-file-earmark-word', 'ext' => ['doc', 'docx', 'odt', 'rtf', 'pages']],
    'sheet'   => ['label' => 'Tabuľky', 'icon' => 'bi-file-earmark-spreadsheet', 'ext' => ['xls', 'xlsx', 'ods', 'csv', 'numbers']],
    'slides'  => ['label' => 'Prezentácie', 'icon' => 'bi-file-earmark-slides', 'ext' => ['ppt', 'pptx', 'odp', 'key']],
    'pdf'     => ['label' => 'PDF', 'icon' => 'bi-file-earmark-pdf', 'ext' => ['pdf'], 'mime' => ['application/pdf']],
    'image'   => ['label' => 'Fotky a obrázky', 'icon' => 'bi-image', 'ext' => ['heic', 'heif', 'raw', 'dng', 'cr2', 'nef', 'psd'], 'mime' => ['image/%']],
    'video'   => ['label' => 'Videá', 'icon' => 'bi-camera-video', 'ext' => ['mkv', '3gp'], 'mime' => ['video/%']],
    'audio'   => ['label' => 'Zvuk', 'icon' => 'bi-music-note-beamed', 'ext' => ['opus', 'flac'], 'mime' => ['audio/%']],
    'archive' => ['label' => 'Archívy', 'icon' => 'bi-file-earmark-zip', 'ext' => ['zip', 'rar', '7z', 'gz', 'tgz', 'tar', 'bz2', 'xz']],
    'text'    => ['label' => 'Text a kód', 'icon' => 'bi-file-earmark-code', 'ext' => ['txt', 'md', 'log', 'json', 'xml', 'yml', 'yaml', 'html', 'htm', 'css', 'js', 'ts', 'php', 'py', 'sql', 'sh', 'kt', 'java', 'c', 'cpp', 'cs', 'ini', 'conf'], 'mime' => ['text/%']],
];
const SEARCH_TYPE_ALIASES = ['folders' => 'folder', 'zlozka' => 'folder', 'document' => 'doc', 'docs' => 'doc', 'word' => 'doc', 'dokument' => 'doc',
    'spreadsheet' => 'sheet', 'excel' => 'sheet', 'tabulka' => 'sheet', 'presentation' => 'slides', 'powerpoint' => 'slides', 'prezentacia' => 'slides',
    'photo' => 'image', 'photos' => 'image', 'images' => 'image', 'foto' => 'image', 'obrazok' => 'image', 'videos' => 'video', 'music' => 'audio', 'zvuk' => 'audio',
    'zip' => 'archive', 'code' => 'text', 'kod' => 'text', 'zlozky' => 'folder', 'dokumenty' => 'doc', 'tabulky' => 'sheet', 'prezentacie' => 'slides',
    'fotky' => 'image', 'obrazky' => 'image', 'videa' => 'video', 'archivy' => 'archive', 'archiv' => 'archive', 'pdfko' => 'pdf'];

function content_index_on(): bool { return setting('content_index', '0') === '1'; }
/** priamo z databázy – dlhá úloha na pozadí sa zastaví, keď admin index medzitým vypne */
function content_index_live(): bool {
    try { return (string)q('SELECT v FROM ' . P . "settings WHERE k = 'content_index'")->fetchColumn() === '1'; } catch (Throwable $e) { return false; }
}

/** Rozloží text hľadania na slová a operátory. */
function search_parse(string $q): array {
    $out = ['words' => [], 'type' => null, 'owner' => null, 'in' => null, 'starred' => false, 'after' => null, 'before' => null,
            'size_min' => null, 'size_max' => null, 'tag' => null];
    preg_match_all('/(-?)(\w+)(:|>=|<=|>|<)("[^"]*"|\S+)|"([^"]+)"|(\S+)/u', $q, $m, PREG_SET_ORDER);
    foreach ($m as $t) {
        if (!empty($t[2]) && isset($t[3]) && $t[3] !== '') {
            $op = mb_strtolower($t[2]); $sep = $t[3]; $val = trim($t[4], '"'); $lv = mb_strtolower($val);
            if ($op === 'type' && $sep === ':') { $out['type'] = SEARCH_TYPE_ALIASES[$lv] ?? (isset(SEARCH_TYPES[$lv]) ? $lv : null); continue; }
            if ($op === 'owner' && $sep === ':') { $out['owner'] = in_array($lv, ['me', 'ja'], true) ? 'me' : 'others'; continue; }
            if ($op === 'in' && $sep === ':' && in_array($lv, ['trash', 'kos', 'drive', 'shared', 'starred'], true)) { $out['in'] = ['kos' => 'trash'][$lv] ?? $lv; continue; }
            if ($op === 'is' && $sep === ':' && in_array($lv, ['starred', 'hviezdicka'], true)) { $out['starred'] = true; continue; }
            if (in_array($op, ['after', 'before', 'po', 'pred'], true) && $sep === ':' && ($d = search_date($val))) { $out[in_array($op, ['after', 'po'], true) ? 'after' : 'before'] = $d; continue; }
            if ($op === 'tag' && $sep === ':') { $out['tag'] = $val; continue; }
            if ($op === 'size' && $sep !== ':' && ($b = parse_size_str($val)) !== null) { $out[$sep[0] === '>' ? 'size_min' : 'size_max'] = $b; continue; }
        }
        $w = $t[5] ?? '';
        if ($w === '') $w = $t[0];
        $w = trim($w, '"');
        if ($w !== '') $out['words'][] = $w;
    }
    $out['words'] = array_slice($out['words'], 0, 8);
    return $out;
}
function search_date(string $s): ?string {
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m) || preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $n)) {
        [$y, $mo, $d] = isset($n) && $n ? [$n[3], $n[2], $n[1]] : [$m[1], $m[2], $m[3]];
        if (checkdate((int)$mo, (int)$d, (int)$y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    return null;
}
function parse_size_str(string $s): ?int {
    if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*(b|k|kb|m|mb|g|gb)?$/i', trim($s), $m)) return null;
    $mul = ['' => 1, 'b' => 1, 'k' => 1024, 'kb' => 1024, 'm' => 1048576, 'mb' => 1048576, 'g' => 1073741824, 'gb' => 1073741824][strtolower($m[2] ?? '')];
    return (int)round((float)str_replace(',', '.', $m[1]) * $mul);
}
function like_esc(string $s): string { return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%'; }

/** SQL podmienka pre typ súboru (podľa MIME aj prípony). */
function search_type_sql(string $type, string $a, array &$p): string {
    $def = SEARCH_TYPES[$type] ?? null;
    if (!$def || $type === 'folder') return '0';
    $or = [];
    foreach ($def['mime'] ?? [] as $mm) { $or[] = "$a.mime LIKE ?"; $p[] = $mm; }
    foreach ($def['ext'] ?? [] as $x) { $or[] = "$a.name LIKE ?"; $p[] = '%.' . $x; }
    return '(' . implode(' OR ', $or) . ')';
}

/** Zložky a súbory zdieľané so mnou (vrátane podzložiek) → [folderId => perm], [fileId => perm]. */
function shared_scope(int $uid): array {
    $dirs = []; $files = [];
    foreach (q('SELECT s.item_type, s.item_id, s.permission, s.owner_id FROM ' . P . 'user_shares s WHERE s.target_id = ?', [$uid]) as $s) {
        if ($s['item_type'] === 'd') {
            foreach (descendants((int)$s['owner_id'], (int)$s['item_id']) as $d) {
                if (($dirs[$d] ?? '') !== 'edit') $dirs[$d] = $s['permission'];
            }
        } else $files[(int)$s['item_id']] = $s['permission'];
    }
    return [$dirs, $files];
}

/**
 * Hlavné vyhľadávanie.
 * $f: q, type, owner (any|me|others), where (any|drive|starred|trash|shared|folder id), modified (today|7d|30d|90d|year|lastyear),
 *     after, before (Y-m-d), size_min, size_max (B), tag (id), content (0/1)
 * @return array{0:array,1:array,2:array} [folders, files, extra]
 */
function search_items(int $uid, array $f, string $orderD, string $orderF, string $vcnt, int $limit = 300): array {
    $P = search_parse((string)($f['q'] ?? ''));
    $type = $P['type'] ?? (isset(SEARCH_TYPES[$f['type'] ?? '']) ? $f['type'] : null);
    $owner = $P['owner'] ?? (in_array($f['owner'] ?? '', ['me', 'others'], true) ? $f['owner'] : 'any');
    $where = $P['in'] ?? (string)($f['where'] ?? 'any');
    if ($where === 'shared') { $owner = 'others'; $where = 'any'; }
    if ($where === 'drive' && $owner === 'any') $owner = 'me';
    $starred = $P['starred'] || $where === 'starred';
    $trash = $where === 'trash';
    if ($trash) $owner = 'me';
    $after = $P['after'] ?? search_date((string)($f['after'] ?? ''));
    $before = $P['before'] ?? search_date((string)($f['before'] ?? ''));
    $mod = (string)($f['modified'] ?? '');
    $since = ['today' => date('Y-m-d'), '7d' => date('Y-m-d', strtotime('-7 days')), '30d' => date('Y-m-d', strtotime('-30 days')),
              '90d' => date('Y-m-d', strtotime('-90 days')), 'year' => date('Y') . '-01-01', 'lastyear' => (date('Y') - 1) . '-01-01'][$mod] ?? null;
    if ($since && !$after) $after = $since;
    if ($mod === 'lastyear' && !$before) $before = (date('Y') - 1) . '-12-31';
    $smin = $P['size_min'] ?? (isset($f['size_min']) && $f['size_min'] !== '' ? (int)$f['size_min'] : null);
    $smax = $P['size_max'] ?? (isset($f['size_max']) && $f['size_max'] !== '' ? (int)$f['size_max'] : null);
    $words = $P['words'];
    $useContent = content_index_on() && ($f['content'] ?? '1') !== '0';

    // štítky (vlastné) – podľa ID z filtra alebo názvu z operátora
    $tagIds = null;
    if (!empty($f['tag'])) $tagIds = [(int)$f['tag']];
    if ($P['tag'] !== null) $tagIds = array_map('intval', q('SELECT id FROM ' . P . 'tags WHERE user_id = ? AND name LIKE ?', [$uid, like_esc($P['tag'])])->fetchAll(PDO::FETCH_COLUMN)) ?: [0];

    $hasFilter = $words || $type || $owner !== 'any' || $where !== 'any' || $starred || $after || $before || $smin !== null || $smax !== null || $tagIds;
    if (!$hasFilter) return [[], [], []];

    $extra = [];
    // rozsah vlastníka / umiestnenia
    [$sDirs, $sFiles] = ($owner !== 'me' && !$trash) ? shared_scope($uid) : [[], []];
    $inFolder = ctype_digit($where) && (int)$where > 0 ? (int)$where : null;
    $subtree = null;
    if ($inFolder) {
        $acc = folder_access($uid, $inFolder);
        $subtree = descendants((int)$acc['owner'], $inFolder);
        if ($acc['perm'] !== 'owner') foreach ($subtree as $d) $sDirs[$d] = $sDirs[$d] ?? $acc['perm'];
    }

    $scopeSql = function (string $a, bool $isFile, array &$p) use ($uid, $owner, $sDirs, $sFiles, $subtree) {
        $own = "$a.user_id = ?";
        $parts = [];
        if ($owner !== 'others') { $parts[] = $own; $p[] = $uid; }
        if ($owner !== 'me') {
            $sh = [];
            $dIds = implode(',', array_map('intval', array_keys($sDirs)));
            if ($isFile) {
                if ($dIds !== '') $sh[] = "$a.folder_id IN ($dIds)";
                if ($sFiles) $sh[] = "$a.id IN (" . implode(',', array_map('intval', array_keys($sFiles))) . ')';
            } elseif ($dIds !== '') $sh[] = "$a.id IN ($dIds)";
            $parts[] = $sh ? "($a.user_id <> ? AND (" . implode(' OR ', $sh) . '))' : '0';
            if ($sh) $p[] = $uid;
        }
        $sql = '(' . implode(' OR ', $parts) . ')';
        if ($subtree !== null) {
            $in = implode(',', array_map('intval', $subtree));
            $sql .= $isFile ? " AND $a.folder_id IN ($in)" : " AND $a.parent_id IN ($in)";
        }
        return $sql;
    };

    $common = function (string $a, bool $isFile, array &$p) use ($trash, $starred, $after, $before, $tagIds, $uid) {
        $w = [$trash ? "$a.deleted_at IS NOT NULL" : "$a.deleted_at IS NULL"];
        if ($starred) $w[] = "$a.starred = 1";
        if ($after) { $w[] = "$a.updated_at >= ?"; $p[] = $after . ' 00:00:00'; }
        if ($before) { $w[] = "$a.updated_at <= ?"; $p[] = $before . ' 23:59:59'; }
        if ($tagIds) {
            $w[] = "$a.id IN (SELECT it.item_id FROM " . P . 'item_tags it JOIN ' . P . "tags tg ON tg.id = it.tag_id WHERE tg.user_id = ? AND it.item_type = '" . ($isFile ? 'f' : 'd') . "' AND it.tag_id IN (" . implode(',', $tagIds) . '))';
            $p[] = $uid;
        }
        return $w;
    };
    // každé slovo musí byť v názve (alebo v štítku, alebo v obsahu)
    $wordSql = function (string $a, bool $isFile, array &$p, bool $content) use ($words, $uid) {
        if (!$words) return null;
        $nameAll = [];
        foreach ($words as $w) { $nameAll[] = "$a.name LIKE ?"; $p[] = like_esc($w); }
        $or = ['(' . implode(' AND ', $nameAll) . ')'];
        $or[] = "$a.id IN (SELECT it.item_id FROM " . P . 'item_tags it JOIN ' . P . "tags tg ON tg.id = it.tag_id WHERE tg.user_id = ? AND it.item_type = '" . ($isFile ? 'f' : 'd') . "' AND tg.name LIKE ?)";
        $p[] = $uid; $p[] = like_esc(implode(' ', $words));
        if ($content && $isFile) {
            $or[] = "$a.id IN (SELECT ft.file_id FROM " . P . 'file_text ft WHERE MATCH(ft.body) AGAINST (? IN BOOLEAN MODE))';
            $p[] = fulltext_query($words);
        }
        return '(' . implode(' OR ', $or) . ')';
    };

    $folders = []; $files = [];
    $wantFolders = (!$type || $type === 'folder') && $smin === null && $smax === null;
    $wantFiles = $type !== 'folder';

    if ($wantFolders) {
        $p = [];
        $w = $common('d', false, $p);
        $w[] = $scopeSql('d', false, $p);
        if ($trash) $w[] = 'd.trash_root = 1';
        if (($ws = $wordSql('d', false, $p, false)) !== null) $w[] = $ws;
        $folders = q('SELECT d.* FROM ' . P . 'folders d WHERE ' . implode(' AND ', $w) . " ORDER BY d.$orderD LIMIT 100", $p)->fetchAll();
    }
    if ($wantFiles) {
        $run = function (bool $content) use ($common, $scopeSql, $wordSql, $trash, $type, $smin, $smax, $orderF, $vcnt, $limit) {
            $p = [];
            $w = $common('f', true, $p);
            $w[] = $scopeSql('f', true, $p);
            if ($trash) $w[] = 'f.trash_root = 1';
            if ($type) $w[] = search_type_sql($type, 'f', $p);
            if ($smin !== null) { $w[] = 'f.size >= ?'; $p[] = $smin; }
            if ($smax !== null) { $w[] = 'f.size <= ?'; $p[] = $smax; }
            if (($ws = $wordSql('f', true, $p, $content)) !== null) $w[] = $ws;
            return q("SELECT f.*, $vcnt FROM " . P . 'files f WHERE ' . implode(' AND ', $w) . " ORDER BY f.$orderF LIMIT " . (int)$limit, $p)->fetchAll();
        };
        try { $files = $run($useContent && $words); }
        catch (Throwable $e) { $files = $run(false); $useContent = false; } // index chýba / nepodporuje FULLTEXT
    }

    // práva k zdieľaným položkám
    foreach ($folders as $d) if ((int)$d['user_id'] !== $uid) $extra['perm'][$d['id']] = $sDirs[(int)$d['id']] ?? 'view';
    foreach ($files as $x) if ((int)$x['user_id'] !== $uid) $extra['fperm'][$x['id']] = $sFiles[(int)$x['id']] ?? ($sDirs[(int)$x['folder_id']] ?? 'view');

    // úryvok textu, kde sa našlo slovo v obsahu
    if ($useContent && $words && $files) {
        $ids = implode(',', array_map(fn($x) => (int)$x['id'], $files));
        $first = $words[0];
        try {
            foreach (q('SELECT file_id, SUBSTRING(body, GREATEST(1, LOCATE(?, body) - 50), 170) s, LOCATE(?, body) pos FROM ' . P . "file_text WHERE file_id IN ($ids)", [$first, $first]) as $r) {
                if ((int)$r['pos'] > 0) $extra['snip'][(int)$r['file_id']] = trim(preg_replace('/\s+/u', ' ', (string)$r['s']));
            }
        } catch (Throwable $e) {}
    }
    return [$folders, $files, $extra];
}
function fulltext_query(array $words): string {
    $t = [];
    foreach ($words as $w) {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $w, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (mb_strlen($part) >= 3) $t[] = '+' . $part . '*';
        }
    }
    return $t ? implode(' ', $t) : '+"' . str_replace('"', '', implode(' ', $words)) . '"';
}

/* ---------- Index obsahu (voliteľný – text dokumentov sa ukladá nezašifrovaný) ---------- */
const TEXT_INDEX_MAX = 25 * 1048576;   // väčšie súbory sa neindexujú
const TEXT_BODY_MAX = 200000;          // znakov textu na súbor

function text_indexable(array $row): bool {
    if ((int)$row['size'] > TEXT_INDEX_MAX || (int)$row['size'] === 0) return false;
    $ext = strtolower(pathinfo((string)$row['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'pdf'], true)) return true;
    return str_starts_with((string)$row['mime'], 'text/') || in_array($ext, SEARCH_TYPES['text']['ext'], true) || $ext === 'csv';
}
/** Vytiahne čistý text zo súboru (txt, docx/xlsx/pptx, odt/ods/odp, pdf cez pdftotext). */
function extract_text(string $path, string $name): ?string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $txt = null;
    if (in_array($ext, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'], true)) {
        if (!class_exists('ZipArchive')) return null;
        $z = new ZipArchive();
        if ($z->open($path) !== true) return null;
        $parts = [];
        for ($i = 0; $i < $z->numFiles && $i < 400; $i++) {
            $n = $z->getNameIndex($i);
            if (preg_match('#^(word/(document|header\d*|footer\d*)\.xml|xl/sharedStrings\.xml|ppt/slides/slide\d+\.xml|content\.xml)$#', $n)) $parts[] = $n;
        }
        natsort($parts);
        $buf = '';
        foreach ($parts as $n) {
            $xml = $z->getFromName($n);
            if ($xml === false) continue;
            $xml = preg_replace('#</(w:p|a:p|text:p|text:h|si|row)>#', "\n", $xml);
            $xml = preg_replace('#<(w:tab|text:tab|w:br)[^>]*/>#', ' ', $xml);
            $buf .= html_entity_decode(strip_tags(str_replace('><', '> <', $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8') . "\n";
            if (strlen($buf) > TEXT_BODY_MAX * 2) break;
        }
        $z->close();
        $txt = $buf;
    } elseif ($ext === 'pdf') {
        $bin = ffmpeg_bin('pdftotext');
        if (!$bin) return null;
        $out = tmp_file();
        try {
            @exec(escapeshellarg($bin) . ' -q -l 60 -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($out) . ' 2>/dev/null', $o, $rc);
            $txt = $rc === 0 && is_file($out) ? (string)file_get_contents($out, false, null, 0, TEXT_BODY_MAX * 3) : null;
        } finally { @unlink($out); }
    } else {
        $txt = (string)file_get_contents($path, false, null, 0, TEXT_BODY_MAX * 3);
        if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1250');
    }
    if ($txt === null) return null;
    $txt = preg_replace('/[^\P{C}\n\t]+/u', ' ', $txt) ?? '';
    $txt = trim(preg_replace("/[ \t]+/u", ' ', $txt) ?? '');
    return mb_substr($txt, 0, TEXT_BODY_MAX);
}
function index_file_text(array $row): void {
    if (!text_indexable($row)) return;
    $tmp = null;
    try {
        $tmp = stored_to_temp($row);
        $body = extract_text($tmp, (string)$row['name']) ?? '';
    } catch (Throwable $e) { $body = ''; }
    finally { if ($tmp) @unlink($tmp); }
    if (!content_index_live()) return;
    q('REPLACE INTO ' . P . 'file_text (file_id, user_id, body, indexed_at) VALUES (?,?,?, NOW())', [(int)$row['id'], (int)$row['user_id'], $body]);
}
/** Doindexuje súbory, ktoré ešte nemajú text (alebo sa zmenili). */
function backfill_text(int $seconds): int {
    if (!content_index_on()) return 0;
    $t0 = microtime(true); $n = 0;
    try {
        $rows = q('SELECT f.* FROM ' . P . 'files f LEFT JOIN ' . P . 'file_text ft ON ft.file_id = f.id
                   WHERE f.deleted_at IS NULL AND f.size > 0 AND f.size <= ' . TEXT_INDEX_MAX . ' AND (ft.file_id IS NULL OR ft.indexed_at < f.updated_at)
                   AND (f.mime LIKE \'text/%\' OR f.mime = \'application/pdf\' OR f.name REGEXP \'\\\\.(docx|xlsx|pptx|odt|ods|odp|pdf|csv|md|json|xml|ya?ml|log|txt|html?|php|js|py|sql|sh)$\')
                   ORDER BY f.id DESC LIMIT 400')->fetchAll();
    } catch (Throwable $e) { return 0; }
    foreach ($rows as $r) {
        if (microtime(true) - $t0 > $seconds) break;
        index_file_text($r); $n++;
    }
    return $n;
}
function content_index_stats(): array {
    try {
        return ['files' => (int)q('SELECT COUNT(*) FROM ' . P . "file_text WHERE body <> ''")->fetchColumn(),
                'bytes' => (int)q('SELECT COALESCE(SUM(LENGTH(body)),0) FROM ' . P . 'file_text')->fetchColumn()];
    } catch (Throwable $e) { return ['files' => 0, 'bytes' => 0]; }
}
