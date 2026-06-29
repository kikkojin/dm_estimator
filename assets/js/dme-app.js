(() => {
  const state = {
    workType: '',
    shipMethod: '',
    shipCount: 1000,
    envelope: { use: '', mode: '', size: '', paper: '', thickness: '', tape: '', count: 0, spec: '' },
    replyMode: '',
    reply: { delegate: '', responseRate: '' },
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

  /**
   * 作業内容の選択有無を判定する。
   * 初期表示時は workType が空文字のため、見積計算は実行しない。
   */
  function hasSelectedWorkType() {
    return !!state.workType;
  }

  function setByPath(obj, path, value) {
    const keys = path.split('.');
    let cur = obj;
    keys.slice(0, -1).forEach((k) => { if (!cur[k]) cur[k] = {}; cur = cur[k]; });
    cur[keys[keys.length - 1]] = value;
  }

  function fetchEstimate() {
    // 初期表示では「作業内容」が未選択のため、ここで即 return する。
    // ページを開いただけで明細（発送基本料金/内容物/封入作業など）が並ぶのを防ぎ、
    // ユーザーが作業内容を選択してから初めて見積計算を走らせるためのガード。
    if (!hasSelectedWorkType()) {
      renderEmptyEstimate();
      return Promise.resolve();
    }

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

  /**
   * 見積明細を初期状態（未計算状態）で表示する。
   * - 明細テーブルは空
   * - 合計は 0 円
   * - ステータスは入力ガイド
   *
   * 「計算して 0 円」ではなく「まだ計算していない」状態を明確にするため、
   * fetchEstimate を呼ばないケースでもこの関数で UI を統一する。
   */
  function renderEmptyEstimate() {
    $tbody.innerHTML = '';
    $total.textContent = yen(0);
    $status.textContent = '作業内容を選択してください';
    $status.className = 'ng';
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
      tape: uniq('tape'),
      spec: [...new Set(filtered.flatMap((s) => s.headers || []).filter(Boolean))],
    };
  }

  function optionHtml(values, selected) {
    return ['<option value="">選択してください</option>'].concat(values.map((v) => `<option value="${v}" ${selected === v ? 'selected' : ''}>${v}</option>`)).join('');
  }

  const BOOK_KEY_LABELS = {
    a4_offset: 'A4オフセット',
    envelope_print: '封筒',
    booklet: '冊子',
    leaflet: 'リーフレット',
    pressure_dm: '圧着DM',
    postcard: 'はがき',
  };

  function getBookKeyLabel(bookKey) {
    return BOOK_KEY_LABELS[bookKey] || bookKey;
  }


  function setEnvelopeVisibility() {
    const use = state.envelope.use;
    const mode = state.envelope.mode;
    const modeBlock = root.querySelector('[data-envelope-block="mode"]');
    const countBlock = root.querySelector('[data-envelope-block="count"]');
    const detailBlock = root.querySelector('[data-envelope-block="detail"]');
    const sizeField = root.querySelector('[data-envelope-field="size"]');
    const paperField = root.querySelector('[data-envelope-field="paper"]');
    const thicknessField = root.querySelector('[data-envelope-field="thickness"]');
    const tapeField = root.querySelector('[data-envelope-field="tape"]');

    const showMode = use === 'yes';
    const showDetailed = showMode && (mode === 'supplied' || mode === 'print');
    const showCount = showMode;

    if (modeBlock) modeBlock.classList.toggle('dme-hidden', !showMode);
    if (countBlock) countBlock.classList.toggle('dme-hidden', !showCount);
    if (detailBlock) detailBlock.classList.toggle('dme-hidden', !showDetailed);

    if (sizeField) sizeField.classList.toggle('dme-hidden', !showDetailed);
    if (paperField) paperField.classList.toggle('dme-hidden', !showDetailed);
    if (thicknessField) thicknessField.classList.toggle('dme-hidden', !showDetailed);
    if (tapeField) tapeField.classList.toggle('dme-hidden', !showDetailed);
  }

  function setReplyVisibility() {
    const replyBlock = root.querySelector('[data-reply-block="container"]');
    const delegateBlock = root.querySelector('[data-reply-block="delegate"]');
    const responseRateBlock = root.querySelector('[data-reply-block="response-rate"]');
    const responseRateInput = root.querySelector('[data-field="reply.responseRate"]');
    const showReply = state.workType === 'survey';
    const showReceiverOnly = showReply && state.replyMode === 'receiver';

    if (replyBlock) replyBlock.classList.toggle('dme-hidden', !showReply);
    if (delegateBlock) delegateBlock.classList.toggle('dme-hidden', !showReceiverOnly);
    if (responseRateBlock) responseRateBlock.classList.toggle('dme-hidden', !showReceiverOnly);
    if (responseRateInput) responseRateInput.required = showReceiverOnly;
  }

  function refreshEnvelopeOptions() {
    const opts = getOptionsFor('envelope_print');
    // 往信用封筒の候補はシート名の分解結果から直接生成する。
    ['size', 'paper', 'thickness', 'tape'].forEach((k) => {
      const el = root.querySelector(`[data-field="envelope.${k}"]`);
      if (el) {
        el.innerHTML = optionHtml(opts[k] || [], state.envelope[k]);
      }
    });
    state.envelope.spec = opts.spec[0] || '';
    refreshEnvelopeCountOptions();
  }

  function getEnvelopePrintQuantities() {
    const filtered = printCatalog.filter((sheet) => {
      if (sheet.bookKey !== 'envelope_print') return false;
      const conditions = sheet.conditions || {};
      if (state.envelope.size && conditions.size !== state.envelope.size) return false;
      if (state.envelope.paper && conditions.paper !== state.envelope.paper) return false;
      if (state.envelope.thickness && conditions.thickness !== state.envelope.thickness) return false;
      if (state.envelope.tape && conditions.tape !== state.envelope.tape) return false;
      return true;
    });
    const values = [...new Set(filtered.flatMap((sheet) => sheet.quantities || []))]
      .map((n) => Number(n))
      .filter((n) => Number.isFinite(n) && n > 0)
      .sort((a, b) => a - b);
    return values;
  }

  function pickInitialEnvelopeCount(quantities) {
    if (!quantities.length) return 0;
    const required = Number(state.shipCount || 0);
    const selected = quantities.find((qty) => qty >= required);
    return selected || quantities[quantities.length - 1];
  }

  function refreshEnvelopeCountOptions() {
    const el = root.querySelector('[data-field="envelope.count"]');
    if (!el) return;
    const quantities = getEnvelopePrintQuantities();
    if (!quantities.length) {
      el.innerHTML = '<option value="">選択してください</option>';
      state.envelope.count = 0;
      return;
    }

    const selected = quantities.includes(Number(state.envelope.count))
      ? Number(state.envelope.count)
      : pickInitialEnvelopeCount(quantities);
    state.envelope.count = selected;
    el.innerHTML = quantities.map((qty) => `<option value="${qty}" ${qty === selected ? 'selected' : ''}>${qty}</option>`).join('');
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
        <label>種類<select data-content="bookKey"><option value="">選択</option>${[...new Set(printCatalog.map((s) => s.bookKey))].map((k) => `<option value="${k}">${getBookKeyLabel(k)}</option>`).join('')}</select></label>
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
      if (e.target.type === 'number') val = e.target.value === '' ? '' : Number(e.target.value);
      if (e.target.type === 'checkbox') val = !!e.target.checked;
      setByPath(state, field, val);
      if (field.startsWith('envelope.')) setEnvelopeVisibility();
      if (field === 'shipCount' || field.startsWith('envelope.')) refreshEnvelopeCountOptions();
      if (field === 'workType' || field === 'replyMode') setReplyVisibility();
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
    // 内容物行の追加だけでは見積計算を開始しない。
    // 作業内容が選択済みの場合のみ fetchEstimate 内で計算される。
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
  setEnvelopeVisibility();
  setReplyVisibility();
  addContentRow();
  // 初期表示時は「内容物1」の入力行のみ表示し、見積計算は実行しない。
  // これによりページ表示直後の明細自動表示を避け、入力導線を明確にする。
  renderEmptyEstimate();
})();
