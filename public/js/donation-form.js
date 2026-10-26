(() => {
    const form = document.getElementById('donation-form');
    const catalog = document.getElementById('donation-payment-methods');
    if (!form || !catalog) return;

    let methods;
    try {
        methods = JSON.parse(catalog.textContent);
    } catch {
        return;
    }

    const amount = form.querySelector('[name="amount"]');
    const method = form.querySelector('[name="method"]');
    const channels = Array.from(form.querySelectorAll('[name="payment_channel"]'));
    const channelGroups = form.querySelectorAll('.payment-channel-group');
    const label = document.getElementById('donation-channel-label');
    const note = document.getElementById('donation-channel-note');
    const currency = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
    const rupiah = (value) => `Rp ${currency.format(value)}`;

    function updateEstimate() {
        const donation = Math.max(0, Number(amount.value) || 0);
        const fee = Number(methods[method.value]?.fee) || 0;
        document.getElementById('donation-estimate').textContent = rupiah(donation);
        document.getElementById('donation-fee').textContent = fee > 0 ? rupiah(fee) : 'Gratis';
        document.getElementById('donation-total').textContent = rupiah(donation + fee);
    }

    function updateMethod() {
        const selected = method.value;
        for (const group of channelGroups) {
            group.hidden = group.dataset.method !== selected;
        }
        for (const option of channels) {
            option.disabled = option.dataset.method !== selected;
            if (option.disabled) option.checked = false;
        }
        if (!channels.some((option) => !option.disabled && option.checked)) {
            const first = channels.find((option) => !option.disabled);
            if (first) first.checked = true;
        }
        label.textContent = selected === 'bank' ? 'Pilih Bank' : selected === 'ewallet' ? 'Pilih E-Wallet' : 'Pembayaran QRIS';
        note.textContent = selected === 'qris'
            ? 'QRIS tanpa biaya admin. Kode QR tampil pada halaman pembayaran Midtrans.'
            : selected === 'bank'
                ? 'Nomor virtual account atau kode tagihan dibuat oleh Midtrans setelah Anda melanjutkan pembayaran.'
                : 'Lanjutkan untuk membuka instruksi pembayaran e-wallet melalui Midtrans.';
        updateEstimate();
    }

    amount.addEventListener('input', updateEstimate);
    method.addEventListener('change', updateMethod);
    updateMethod();
})();
