(() => {
    const money = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
    const rupiah = (value) => `Rp ${money.format(Number(value) || 0)}`;
    const dateLabel = (value) => {
        if (!value) return '';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('id-ID');
    };
    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined && text !== null) node.textContent = text;
        if (className) node.className = className;
        return node;
    };

    function updateSummary(container, summary) {
        if (!summary) return;
        for (const node of container.querySelectorAll('[data-funding]')) {
            const key = node.dataset.funding;
            if (Object.prototype.hasOwnProperty.call(summary, key)) node.textContent = rupiah(summary[key]);
        }
    }

    function renderPublicList(container, entries) {
        const list = container.querySelector('[data-disbursement-list]');
        if (!list || !Array.isArray(entries)) return;
        const fragment = document.createDocumentFragment();
        if (!entries.length) fragment.append(element('p', 'Belum ada pengajuan penyaluran untuk campaign ini.'));
        for (const entry of entries) {
            const article = element('article');
            article.style.cssText = 'padding:20px 0;border-top:1px solid var(--border)';
            article.append(element('span', entry.status_label, 'tag'));
            const heading = element('h3', entry.title);
            heading.style.marginTop = '12px';
            article.append(heading);
            const amount = element('p');
            amount.append(element('strong', rupiah(entry.amount)), document.createTextNode(` · Penerima: ${entry.recipient_label || ''}`));
            article.append(amount);
            if (entry.description) {
                const description = element('p', entry.description);
                description.style.whiteSpace = 'pre-line';
                article.append(description);
            }
            const reference = element('p', `Referensi: ${entry.id}`, 'muted');
            reference.style.cssText = 'overflow-wrap:anywhere;font-size:12px';
            article.append(reference);
            const timeline = element('ol');
            timeline.setAttribute('aria-label', `Riwayat status ${entry.title || ''}`);
            for (const event of Array.isArray(entry.events) ? entry.events : []) {
                const item = element('li', event.status_label);
                const timestamp = dateLabel(event.created_at);
                if (timestamp) item.append(element('small', ` · ${timestamp}`, 'muted'));
                timeline.append(item);
            }
            article.append(timeline);
            fragment.append(article);
        }
        list.replaceChildren(fragment);
    }

    function updateCampaignRows(container, campaigns) {
        if (!Array.isArray(campaigns)) return;
        const byId = new Map(campaigns.map((campaign) => [String(campaign.id), campaign]));
        for (const row of container.querySelectorAll('[data-campaign-row]')) {
            const campaign = byId.get(row.dataset.campaignRow);
            if (!campaign) continue;
            for (const cell of row.querySelectorAll('[data-campaign-funding]')) {
                const key = cell.dataset.campaignFunding;
                if (Object.prototype.hasOwnProperty.call(campaign, key)) cell.textContent = rupiah(campaign[key]);
            }
        }
        if (window.disbursementChart) {
            const chart = window.disbursementChart;
            chart.data.labels = campaigns.map((campaign) => campaign.title);
            chart.data.datasets[0].data = campaigns.map((campaign) => campaign.raised);
            chart.data.datasets[1].data = campaigns.map((campaign) => campaign.spent);
            chart.data.datasets[2].data = campaigns.map((campaign) => campaign.confirmed);
            chart.update('none');
        }
    }

    for (const container of document.querySelectorAll('[data-disbursement-live]')) {
        const feedback = container.querySelector('[data-live-feedback]');
        const endpoint = container.dataset.statusUrl;
        if (!endpoint) continue;
        let attempts = 0;
        let inFlight = false;
        let stopped = false;
        let timer;
        const message = (text) => { if (feedback) feedback.textContent = text; };

        async function poll() {
            if (inFlight || stopped) return;
            clearTimeout(timer);
            if (attempts >= 120) {
                stopped = true;
                message('Pembaruan otomatis selesai. Muat ulang halaman untuk memeriksa status terbaru.');
                return;
            }
            if (document.visibilityState === 'hidden') {
                timer = setTimeout(poll, 10000);
                return;
            }
            inFlight = true;
            attempts++;
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const response = await fetch(endpoint, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if ([401, 403, 419].includes(response.status)) {
                    stopped = true;
                    message('Akses atau sesi telah berakhir. Muat ulang halaman dan masuk kembali untuk memeriksa status.');
                    return;
                }
                if (!response.ok) throw new Error('Status tidak tersedia.');
                const data = await response.json();
                updateSummary(container, data.summary);
                if (container.dataset.disbursementLive === 'campaign') renderPublicList(container, data.disbursements);
                if (container.dataset.disbursementLive === 'transparency') updateCampaignRows(container, data.campaigns);
                if (container.dataset.disbursementLive === 'detail') {
                    const status = container.querySelector('[data-current-status]');
                    if (status && data.status_label) status.textContent = data.status_label;
                    if (data.status && data.status !== container.dataset.initialStatus) {
                        const banner = container.querySelector('[data-status-changed]');
                        if (banner) banner.hidden = false;
                    }
                }
                message(`Status terakhir diperiksa: ${dateLabel(data.checked_at) || new Date().toLocaleString('id-ID')}. Diperbarui setiap 10 detik.`);
            } catch {
                message('Status belum dapat diperbarui. Data yang tampil adalah pemeriksaan terakhir; mencoba lagi otomatis. Anda juga dapat memuat ulang halaman.');
            } finally {
                clearTimeout(timeout);
                inFlight = false;
                if (!stopped) timer = setTimeout(poll, 10000);
            }
        }

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'hidden') poll();
        });
        window.addEventListener('focus', () => poll());
        window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;
            stopped = false;
            attempts = 0;
            poll();
        });
        poll();
    }

    for (const button of document.querySelectorAll('[data-copy-disbursement-url]')) {
        button.addEventListener('click', async () => {
            const input = document.getElementById('disbursement-share-url');
            const feedback = document.querySelector('[data-copy-feedback]');
            if (!input) return;
            try {
                await navigator.clipboard.writeText(input.value);
                if (feedback) feedback.textContent = 'Tautan berhasil disalin.';
            } catch {
                input.focus();
                input.select();
                if (feedback) feedback.textContent = 'Pilih salin pada kolom tautan untuk membagikannya kepada penerima.';
            }
        });
    }
})();
