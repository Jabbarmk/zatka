// Invoice form: dynamic line items and live totals
(function () {
  const lines = document.getElementById('lines');
  if (!lines) return;
  const tpl = document.getElementById('line-template').innerHTML;
  const exemptions = JSON.parse(document.getElementById('exemption-data').textContent);
  const defaultRate = parseFloat(document.getElementById('default-rate').value || '15');
  let n = 0;

  function addLine() {
    const row = document.createElement('tr');
    row.innerHTML = tpl.replace(/__N__/g, n++);
    lines.appendChild(row);
    const cat = row.querySelector('.vat-cat');
    cat.addEventListener('change', () => onCategory(row));
    onCategory(row);
    row.querySelectorAll('input, select').forEach(el => el.addEventListener('input', recalc));
    row.querySelector('.remove-line').addEventListener('click', () => { row.remove(); recalc(); });
    recalc();
  }

  function onCategory(row) {
    const cat = row.querySelector('.vat-cat').value;
    const rate = row.querySelector('.vat-rate');
    const ex = row.querySelector('.exemption');
    rate.value = cat === 'S' ? defaultRate.toFixed(2) : '0.00';
    rate.readOnly = cat !== 'S';
    ex.innerHTML = '';
    ex.disabled = cat === 'S';
    if (cat !== 'S') {
      Object.entries(exemptions).forEach(([code, [c, text]]) => {
        if (c === cat) ex.insertAdjacentHTML('beforeend', `<option value="${code}">${code} - ${text}</option>`);
      });
    }
    recalc();
  }

  function r2(v) { return Math.round((v + Number.EPSILON) * 100) / 100; }

  function recalc() {
    let net = 0, vat = 0;
    lines.querySelectorAll('tr').forEach(row => {
      const q = parseFloat(row.querySelector('.qty').value) || 0;
      const p = parseFloat(row.querySelector('.price').value) || 0;
      const d = parseFloat(row.querySelector('.disc').value) || 0;
      const rate = parseFloat(row.querySelector('.vat-rate').value) || 0;
      const ln = r2(r2(q * p) - d);
      const lv = r2(ln * rate / 100);
      row.querySelector('.line-net').textContent = ln.toFixed(2);
      row.querySelector('.line-vat').textContent = lv.toFixed(2);
      row.querySelector('.line-total').textContent = r2(ln + lv).toFixed(2);
      net += ln; vat += lv;
    });
    const docDisc = parseFloat(document.getElementById('doc_discount').value) || 0;
    const taxable = r2(net - docDisc);
    const vatAdj = docDisc > 0 ? r2(vat - r2(docDisc * defaultRate / 100)) : r2(vat);
    document.getElementById('t-net').textContent = r2(net).toFixed(2);
    document.getElementById('t-taxable').textContent = taxable.toFixed(2);
    document.getElementById('t-vat').textContent = vatAdj.toFixed(2);
    document.getElementById('t-total').textContent = r2(taxable + vatAdj).toFixed(2);
  }

  document.getElementById('add-line').addEventListener('click', addLine);
  document.getElementById('doc_discount').addEventListener('input', recalc);
  addLine();

  // Show/hide fields depending on document type
  const typeSel = document.getElementById('type_code');
  const subSel = document.getElementById('subtype');
  function onType() {
    const isNote = typeSel.value === '381' || typeSel.value === '383';
    document.querySelectorAll('.note-only').forEach(el => el.classList.toggle('d-none', !isNote));
    document.querySelectorAll('.note-only input, .note-only select').forEach(el => el.required = isNote);
    const isStd = subSel.value === '01';
    document.getElementById('customer_id').required = isStd;
    document.getElementById('supply_date').required = isStd;
  }
  typeSel.addEventListener('change', onType);
  subSel.addEventListener('change', onType);
  onType();
})();
