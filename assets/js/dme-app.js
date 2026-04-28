(() => {
  const state = {
    workType: '',
    shipMethod: '',
    shipCount: 100,
    envelope: { use: 'no', mode: 'supplied', size: '', paper: '', thickness: '', tape: '', count: 100, spec: '' },
    replyMode: 'stamp',
    reply: { delegate: false },
    envelopeDesignRequest: false,
    contents: [],
    contact: {},
    estimate: { items: [], total: 0, is_estimatable: false, errors: [] },
  };

  const root = document.getElementById('dme-root');
  if (!root || typeof DME_APP === 'undefined') return;
  // テスト環境でキャッシュ反映状況を確認しやすくするため、読み込みバージョンを1回だけ出力する。
  console.log(`DM Estimator version: ${DME_APP.version || 'unknown'}`);

  const printCatalog = DME_APP.catalog.printCatalog || [];
  const $contents = root.querySelector('#dme-contents');
  const $tbody = root.querySelector('#dme-estimate-body');
  const $total = root.querySelector('#dme-total');
  const $status = root.querySelector('#dme-status');
  const $sendResult = root.querySelector('#dme-send-result');

  const yen = (n) => `${DME_APP.labels.currencyPrefix}${Number(n || 0).toLocaleString()}`;

  function setByPath(obj, path, value) {
    const keys = path.split('.');
    let cur = obj;
    keys.slice(0, -1).forEach((k) => { if (!cur[k]) cur[k] = {}; cur = cur[k]; });
    cur[keys[keys.length - 1]] = value;
  }

  function fetchEstimate() {
    return fetch(`${DME_APP.ajaxUrl}?action=dme_calculate&nonce=${encodeURIComponent(DME_APP.nonce)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(state),
    })
      .then((r) => r.json())
      .then((json) => {
        if (json.success) {
          state.estimate = json.data;
          renderEstimate();
        }
      });
  }

  function renderEstimate() {
    $tbody.innerHTML = '';
    state.estimate.items.forEach((item, idx) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${item.label}</td><td>${yen(item.unit_price)}</td><td>${item.quantity}</td><td>${yen(item.subtotal)}</td><td>${item.note || ''}</td><td><button data-remove="${idx}" type="button">削除</button></td>`;
      $tbody.appendChild(tr);
    });

    $total.textContent = yen(state.estimate.total || 0);
    if (state.estimate.is_estimatable) {
      $status.textContent = '見積可能';
      $status.className = 'ok';
    } else {
      const msg = (state.estimate.errors || []).join(' / ') || '見積不可';
      $status.textContent = msg;
      $status.className = 'ng';
    }
  }

  function getOptionsFor(bookKey, current) {
    const filtered = printCatalog.filter((s) => !bookKey || s.bookKey === bookKey);
    const uniq = (key) => [...new Set(filtered.map((s) => (s.conditions[key] || '')).filter(Boolean))];
    return {
      size: uniq('size'),
      paper: uniq('paper'),
      thickness: uniq('thickness'),
      spec: [...new Set(filtered.flatMap((s) => s.headers || []).filter(Boolean))],
    };
  }

  function optionHtml(values, selected) {
    return ['<option value="">選択してください</option>'].concat(values.map((v) => `<option value="${v}" ${selected === v ? 'selected' : ''}>${v}</option>`)).join('');
  }

  function refreshEnvelopeOptions() {
    const opts = getOptionsFor('envelope_print');
    ['size', 'paper', 'thickness'].forEach((k) => {
      const el = root.querySelector(`[data-field="envelope.${k}"]`);
      if (el) el.innerHTML = optionHtml(opts[k], state.envelope[k]);
    });
    state.envelope.spec = opts.spec[0] || '';
  }

  function addContentRow() {
    const idx = state.contents.length;
    state.contents.push({ name: `内容物${idx + 1}`, mode: 'print', bookKey: '', size: '', paper: '', thickness: '', spec: '' });

    const row = document.createElement('div');
    row.className = 'dme-content-row';
    row.dataset.idx = String(idx);

    row.innerHTML = `
      <div class="dme-grid dme-grid-4">
        <label>名称<input data-content="name" type="text" value="内容物${idx + 1}"></label>
        <label>種別<select data-content="mode"><option value="print">印刷</option><option value="supplied">支給</option></select></label>
        <label>種類<select data-content="bookKey"><option value="">選択</option>${[...new Set(printCatalog.map((s) => s.bookKey))].map((k) => `<option value="${k}">${k}</option>`).join('')}</select></label>
        <label>カラー/仕様<select data-content="spec"></select></label>
        <label>サイズ<select data-content="size"></select></label>
        <label>紙質<select data-content="paper"></select></label>
        <label>厚み<select data-content="thickness"></select></label>
        <button type="button" data-content="remove">削除</button>
      </div>
    `;
    $contents.appendChild(row);
    refreshContentOptions(row, idx);
  }

  function refreshContentOptions(row, idx) {
    const c = state.contents[idx];
    const opts = getOptionsFor(c.bookKey);
    ['size', 'paper', 'thickness', 'spec'].forEach((k) => {
      const sel = row.querySelector(`[data-content="${k}"]`);
      if (sel) sel.innerHTML = optionHtml(opts[k] || [], c[k]);
    });
  }

  function handleRootFieldEvent(e) {
    const field = e.target.getAttribute('data-field');
    const contact = e.target.getAttribute('data-contact');
    if (field) {
      let val = e.target.value;
      if (e.target.type === 'number') val = Number(val || 0);
      if (e.target.type === 'checkbox') val = !!e.target.checked;
      setByPath(state, field, val);
      fetchEstimate();
    }
    if (contact) {
      state.contact[contact] = e.target.value;
    }
  }

  root.addEventListener('input', handleRootFieldEvent);
  root.addEventListener('change', handleRootFieldEvent);

  $contents.addEventListener('input', (e) => {
    const row = e.target.closest('.dme-content-row');
    if (!row) return;
    const idx = Number(row.dataset.idx);
    const key = e.target.getAttribute('data-content');
    if (!key) return;
    state.contents[idx][key] = e.target.value;
    if (key === 'bookKey') refreshContentOptions(row, idx);
    fetchEstimate();
  });

  $contents.addEventListener('click', (e) => {
    if (e.target.getAttribute('data-content') === 'remove') {
      const row = e.target.closest('.dme-content-row');
      const idx = Number(row.dataset.idx);
      state.contents.splice(idx, 1);
      row.remove();
      [...$contents.querySelectorAll('.dme-content-row')].forEach((r, i) => (r.dataset.idx = String(i)));
      fetchEstimate();
    }
  });

  $tbody.addEventListener('click', (e) => {
    const remove = e.target.getAttribute('data-remove');
    if (remove == null) return;
    state.estimate.items.splice(Number(remove), 1);
    state.estimate.total = state.estimate.items.reduce((s, x) => s + Number(x.subtotal || 0), 0);
    renderEstimate();
  });

  root.querySelector('#dme-add-content').addEventListener('click', () => {
    addContentRow();
    fetchEstimate();
  });

  root.querySelector('#dme-send').addEventListener('click', () => {
    if (!state.estimate.is_estimatable) {
      $sendResult.textContent = '見積不可のため送信できません。';
      return;
    }
    fetch(`${DME_APP.ajaxUrl}?action=dme_send_mail&nonce=${encodeURIComponent(DME_APP.nonce)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(state),
    })
      .then((r) => r.json())
      .then((json) => {
        $sendResult.textContent = json.success ? json.data.message : (json.data?.message || '送信失敗');
      })
      .catch(() => {
        $sendResult.textContent = '通信エラーが発生しました。';
      });
  });

  refreshEnvelopeOptions();
  addContentRow();
  fetchEstimate();
})();
