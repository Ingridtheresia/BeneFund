<?php

return [
    // Application fees, charged in addition to the donation. These are not
    // Midtrans's merchant pricing and are never included in campaign funds.
    'methods' => [
        'qris' => [
            'label' => 'QRIS', 'fee' => 0,
            'channels' => [
                'other_qris' => ['label' => 'QRIS', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/v2/qris/index'],
            ],
        ],
        'bank' => [
            'label' => 'Transfer Bank', 'fee' => 4500,
            'channels' => [
                'bca_va' => ['label' => 'BCA Virtual Account', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/bca/va/index'],
                'bni_va' => ['label' => 'BNI Virtual Account', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/bni/va/index'],
                'bri_va' => ['label' => 'BRI Virtual Account', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/openapi/va/index?bank=bri'],
                'echannel' => ['label' => 'Mandiri Bill Payment', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/openapi/va/index?bank=mandiri'],
                'permata_va' => ['label' => 'Permata Virtual Account', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/openapi/va/index?bank=permata'],
                'cimb_va' => ['label' => 'CIMB Niaga Virtual Account', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/openapi/va/index?bank=cimb'],
            ],
        ],
        'ewallet' => [
            'label' => 'E-Wallet', 'fee' => 1500,
            'channels' => [
                'gopay' => ['label' => 'GoPay', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/v2/qris/index'],
                'shopeepay' => ['label' => 'ShopeePay', 'simulator_url' => 'https://simulator.sandbox.midtrans.com/v2/qris/index'],
            ],
        ],
    ],
];
