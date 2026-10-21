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
    const channel = form.querySelector('[name="payment_channel"]');
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
        for (const group of channel.querySelectorAll('optgroup')) {
            group.disabled = group.dataset.method !== selected;
            group.hidden = group.disabled;
        }
        for (const option of channel.options) {
            option.disabled = option.dataset.method !== selected;
            option.hidden = option.disabled;
        }
        if (!channel.selectedOptions.length || channel.selectedOptions[0].disabled) {
            channel.value = Object.keys(methods[selected]?.channels || {})[0] || '';
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
