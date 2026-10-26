<?php

return [
    // Application fees, charged in addition to the donation. These are not
    // Midtrans's merchant pricing and are never included in campaign funds.
    'methods' => [
        'qris' => [
            'label' => 'QRIS', 'fee' => 0,
            'channels' => [
                'other_qris' => ['label' => 'QRIS', 'logo' => 'images/payments/qris.png', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/v2/qris/index'],
            ],
        ],
        'bank' => [
            'label' => 'Transfer Bank', 'fee' => 4500,
            'channels' => [
                'bca_va' => ['label' => 'BCA Virtual Account', 'logo' => 'images/payments/bca.png', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/bca/va/index'],
                'bni_va' => ['label' => 'BNI Virtual Account', 'logo' => 'images/payments/bni.png', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/bni/va/index'],
                'bri_va' => ['label' => 'BRI Virtual Account', 'logo' => 'images/payments/bri.png', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/openapi/va/index?bank=bri'],
                'echannel' => ['label' => 'Mandiri Bill Payment', 'logo' => 'images/payments/mandiri.png', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/openapi/va/index?bank=mandiri'],
                'permata_va' => ['label' => 'Permata Virtual Account', 'logo' => 'images/payments/permata.png', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/openapi/va/index?bank=permata'],
                'cimb_va' => ['label' => 'CIMB Niaga Virtual Account', 'logo' => 'images/payments/cimb.svg', 'logo_theme' => 'light', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/openapi/va/index?bank=cimb'],
            ],
        ],
        'ewallet' => [
            'label' => 'E-Wallet', 'fee' => 1500,
            'channels' => [
                'gopay' => ['label' => 'GoPay', 'logo' => 'images/payments/gopay.png', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/v2/qris/index'],
                'shopeepay' => ['label' => 'ShopeePay', 'logo' => 'images/payments/shopeepay.png', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/v2/qris/index'],
            ],
        ],
    ],
];
