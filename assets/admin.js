(function () {
    function el(tag, attrs = {}, html = '') {
        const e = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (html) e.innerHTML = html;
        return e;
    }
    function badge(grade) {
        let cls = 'neutral';
        if (grade === 'A') cls = 'good';
        else if (grade === 'B' || grade === 'C') cls = 'ok';
        else if (grade === 'D' || grade === 'F') cls = 'bad';
        const b = el('span', { class: 'nfinite-badge ' + cls }, grade);
        return b;
    }
    function row(label, val, score, grade) {
        const wrap = el('div', { class: 'nfinite-card' });
        const det = el('details'); det.setAttribute('open', '');
        const sum = el('summary');
        sum.append(el('span', { class: 'nfinite-h' }, label));
        sum.append(badge(grade));
        det.append(sum);
        const body = el('div', { class: 'nfinite-detail' });
        body.append(el('p', {}, '<strong>Value:</strong> ' + val));
        body.append(el('p', {}, '<strong>Score:</strong> ' + (score === null ? '—' : parseInt(score))));
        det.append(body);
        wrap.append(det);
        return wrap;
    }
    function renderBlock(title, data) {
        const box = el('div', { class: 'nfinite-psi-block' });
        box.append(el('h4', {}, title));
        if (!data.ok) {
            box.append(el('div', { class: 'notice notice-error' }, '<p>' + data.error + '</p>'));
            return box;
        }
        const header = el('div', { class: 'nfinite-score' }, data.overall === null ? 'N/A' : parseInt(data.overall));
        box.append(header);
        const grid = el('div', { class: 'nfinite-cards full' });
        ['FCP', 'LCP', 'TBT', 'CLS', 'SI'].forEach(id => {
            const m = data.metrics[id]; if (!m) return;
            grid.append(row(m.label, m.value_fmt, m.score, m.grade));
        });
        box.append(grid);
        if (data.warnings && data.warnings.length) {
            box.append(el('p', { class: 'muted' }, 'Warnings: ' + data.warnings.join(' | ')));
        }
        return box;
    }

    document.addEventListener('click', function (e) {
        const runBtn = document.getElementById('nfinite-psi-run');
        if (!runBtn || e.target !== runBtn) return;

        const url = document.getElementById('nfinite-psi-url').value.trim();
        const results = document.getElementById('nfinite-psi-results');
        const spinner = runBtn.nextElementSibling;
        results.innerHTML = '';
        spinner.classList.add('is-active');

        const fd = new FormData();
        fd.append('action', 'nfinite_test_psi');
        fd.append('_ajax_nonce', window.NFINITE_PSI.nonce);
        fd.append('url', url);

        fetch(window.NFINITE_PSI.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(json => {
                if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || 'Unknown error'); }
                const wrap = el('div', { class: 'nfinite-psi-grid' });
                wrap.append(renderBlock('Mobile', json.data.mobile));
                wrap.append(renderBlock('Desktop', json.data.desktop));
                results.append(wrap);
            })
            .catch(err => {
                results.innerHTML = '<div class="notice notice-error"><p>' + String(err.message || err) + '</p></div>';
            })
            .finally(() => spinner.classList.remove('is-active'));
    });
})();
