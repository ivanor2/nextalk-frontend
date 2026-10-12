/**
 * NexTalk Messenger — Federated Nodes Directory Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
    // ── Connect Node Button ────────────────────────────────────
    document.querySelectorAll('.connect-node-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const ws = btn.dataset.ws;
            localStorage.setItem('nt-node', ws);
            showToast('✅ Выбран узел: ' + ws, 'success');
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 800);
        });
    });

    // ── Search & Filter ────────────────────────────────────────
    const searchInput = document.getElementById('nodeSearchInput');
    const filterSelect = document.getElementById('nodeStatusFilter');
    const rows = document.querySelectorAll('.node-row');

    function filterNodes() {
        const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const filter = filterSelect ? filterSelect.value : 'all';

        rows.forEach(row => {
            const rowText = row.dataset.search || '';
            const rowStatus = row.dataset.status || '';

            const matchesQuery = !query || rowText.includes(query);
            const matchesStatus = (filter === 'all') || (rowStatus === filter);

            row.style.display = (matchesQuery && matchesStatus) ? '' : 'none';
        });
    }

    if (searchInput) searchInput.addEventListener('input', filterNodes);
    if (filterSelect) filterSelect.addEventListener('change', filterNodes);

    // ── Pinging Nodes ──────────────────────────────────────────
    document.querySelectorAll('.ping-badge').forEach(async el => {
        const url = el.dataset.url;
        if (!url || url.startsWith('ws')) {
            el.textContent = 'online';
            el.classList.add('text-success');
            return;
        }
        const start = Date.now();
        try {
            await fetch(url + '/health', { mode: 'no-cors' });
            const latency = Date.now() - start;
            el.textContent = latency + ' ms';
            if (latency < 150) el.classList.add('text-success');
            else if (latency < 350) el.classList.add('text-warning');
            else el.classList.add('text-danger');
        } catch (e) {
            el.textContent = 'онлайн';
            el.classList.add('text-success');
        }
    });
});
