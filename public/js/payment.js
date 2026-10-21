(() => {
    const panel = document.getElementById('payment-panel');
    if (!panel) return;
    const form = document.getElementById('checkout-form');
    const button = document.getElementById('pay-button');
    const feedback = document.getElementById('payment-feedback');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let checks = 0;
    let checking = false;
    let timer;

    function stopPolling() {
        clearInterval(timer);
        timer = undefined;
    }

    async function post(url) {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (response.status === 401 || response.status === 419) {
                throw new Error('Sesi berakhir. Muat ulang halaman dan masuk kembali.');
            }
            throw new Error(data.message || 'Pembayaran belum dapat diproses. Silakan coba lagi.');
        }
        return data;
    }

    async function checkStatus() {
        if (checking) return;
        if (checks >= 30) {
            stopPolling();
            feedback.textContent = 'Pembayaran masih menunggu. Klik Periksa Status Pembayaran setelah menyelesaikan simulasi.';
            return;
        }
        checking = true;
        checks++;
        try {
            const result = await post(panel.dataset.statusUrl);
            document.getElementById('payment-status').textContent = result.label;
            if (result.status !== 'pending') {
                stopPolling();
                panel.dataset.poll = 'false';
                window.location.assign(result.redirect_url);
            } else if (feedback.textContent.startsWith('Memverifikasi')) {
                feedback.textContent = 'Pembayaran masih menunggu konfirmasi Midtrans.';
            }
        } catch (error) {
            feedback.textContent = error.message;
        } finally {
            checking = false;
        }
    }

    function startPolling() {
        if (!timer) timer = setInterval(checkStatus, 10000);
    }

    if (form) form.addEventListener('submit', async (event) => {
        // Native form submission opens hosted Snap checkout when its script is unavailable.
        if (typeof window.snap?.pay !== 'function') return;
        event.preventDefault();
        button.disabled = true;
        feedback.textContent = 'Menyiapkan pembayaran…';
        try {
            const result = await post(form.action);
            feedback.textContent = '';
            checks = 0;
            panel.dataset.poll = 'true';
            button.textContent = 'Lanjutkan Pembayaran';
            startPolling();
            const verify = () => {
                checks = 0;
                feedback.textContent = 'Memverifikasi status ke Midtrans…';
                checkStatus();
            };
            window.snap.pay(result.token, {
                onSuccess: verify, onPending: verify,
                onError: () => { feedback.textContent = 'Pembayaran belum berhasil. Periksa status atau coba lagi.'; checkStatus(); },
                onClose: () => { feedback.textContent = 'Anda dapat melanjutkan pembayaran selama sesi masih berlaku.'; checkStatus(); },
            });
        } catch (error) {
            feedback.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });

    if (panel.dataset.poll === 'true') {
        checkStatus();
        startPolling();
    }

    // Sandbox simulators open in another tab; refresh immediately when the donor returns.
    function refreshOnReturn() {
        if (document.visibilityState === 'hidden' || panel.dataset.poll !== 'true') return;
        checks = 0;
        checkStatus();
        startPolling();
    }
    window.addEventListener('focus', refreshOnReturn);
    document.addEventListener('visibilitychange', refreshOnReturn);
})();
