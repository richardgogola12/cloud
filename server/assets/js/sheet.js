/* Otvorenie editora tabuliek (sheet.php) v okne nad diskom.
   Editor beží vo vlastnom rámci, aby jeho knižnice nekolidovali s diskom; správy si vymieňajú cez postMessage. */
(function () {
  const base = () => document.querySelector('meta[name="base-url"]').content;
  let M = null, cur = null, canClose = false;

  function ensure() {
    if (M) return M;
    document.body.insertAdjacentHTML('beforeend', '<div class="modal" id="sheetModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-fullscreen"><div class="modal-content sheet-frame-wrap"><iframe class="sheet-frame" title="Editor" allow="clipboard-read; clipboard-write"></iframe></div></div></div>');
    M = document.getElementById('sheetModal');
    // zatvorenie (aj tlačidlom Späť) – editor najprv dokončí ukladanie
    M.addEventListener('hide.bs.modal', (e) => {
      if (canClose || !cur) return;
      e.preventDefault();
      try { M.querySelector('iframe').contentWindow.postMessage({ cloudSheetCmd: 'close' }, location.origin); } catch (x) { finish(); }
    });
    M.addEventListener('hidden.bs.modal', () => { M.querySelector('iframe').src = 'about:blank'; const c = cur; cur = null; canClose = false; c && c.o.onClose && c.o.onClose(); });
    window.addEventListener('message', (e) => {
      if (e.origin !== location.origin || !e.data || !e.data.cloudSheet || !cur) return;
      const d = e.data;
      if (d.cloudSheet === 'saved') cur.o.onSaved && cur.o.onSaved(d);
      if (d.cloudSheet === 'close') finish();
    });
    return M;
  }
  function finish() { canClose = true; bootstrap.Modal.getOrCreateInstance(M).hide(); }

  /** Otvorí tabuľku (sheet.php) alebo dokument (doc.php) v editore. o: { readOnly, onSaved, onClose } */
  function open(it, o, pageName) {
    ensure();
    cur = { it, o: o || {} }; canClose = false;
    M.querySelector('iframe').src = base() + (pageName || 'sheet.php') + '?embed=1&id=' + encodeURIComponent(it.id) + (cur.o.readOnly ? '&ro=1' : '');
    bootstrap.Modal.getOrCreateInstance(M).show();
    setTimeout(() => { try { M.querySelector('iframe').focus(); } catch (e) {} }, 300);
  }

  /** Prázdna tabuľka .xlsx (na vytvorenie nového súboru). */
  async function blankXlsx() {
    const L = window.CLOUD_LIB || {};
    const load = (src) => new Promise((res, rej) => { if (document.querySelector('script[src="' + src + '"]')) return res(); const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error('Nepodarilo sa načítať knižnicu tabuliek.')); document.head.appendChild(s); });
    if (!window.ExcelJS) await load(L.exceljs);
    const wb = new ExcelJS.Workbook(); wb.creator = 'Cloud';
    wb.addWorksheet('Hárok1');
    return new Blob([await wb.xlsx.writeBuffer()], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  }

  window.CloudSheet = { open, blankXlsx };
  window.CloudDoc = { open: (it, o) => open(it, o, 'doc.php') };
})();
