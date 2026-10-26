(() => {
    const link = document.querySelector('[data-notification-link]');
    if (!link) return;

    const badge = link.querySelector('[data-notification-count]');
    const inbox = document.querySelector('[data-notification-inbox]');
    const refresh = document.querySelector('[data-notification-refresh]');
    const inboxCount = document.querySelector('[data-inbox-unread-count]');
    const initialCount = Number(link.dataset.unreadCount);
    let inFlight = false;

    async function updateCount() {
        if (document.hidden || inFlight) return;
        inFlight = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(link.dataset.statusUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!Number.isInteger(data.unread_count) || data.unread_count < 0) return;
            badge.textContent = data.unread_count > 99 ? '99+' : String(data.unread_count);
            badge.hidden = data.unread_count === 0;
            link.setAttribute('aria-label', `Notifikasi, ${data.unread_count} belum dibaca`);
            if (inboxCount) inboxCount.textContent = String(data.unread_count);
            if (inbox && refresh) {
                refresh.hidden = (data.latest_id || '') === inbox.dataset.initialLatest && data.unread_count === initialCount;
            }
        } catch {
            // Keep the server-rendered count available during temporary connection failures.
        } finally {
            window.clearTimeout(timeout);
            inFlight = false;
        }
    }

    window.setInterval(updateCount, 30000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) updateCount();
    });
})();
