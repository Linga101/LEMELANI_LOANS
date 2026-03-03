USE lemelani_loans;

ALTER TABLE customer_accounts
    MODIFY account_type ENUM(
        'airtel_money',
        'tnm_mpamba',
        'sticpay',
        'mastercard',
        'visa',
        'binance',
        'bank_transfer',
        'bank_account',
        'mobile_money',
        'wallet'
    ) NOT NULL DEFAULT 'airtel_money';

ALTER TABLE platform_accounts
    MODIFY account_type ENUM(
        'airtel_money',
        'tnm_mpamba',
        'sticpay',
        'mastercard',
        'visa',
        'binance',
        'bank_transfer',
        'bank_account',
        'mobile_money',
        'wallet',
        'escrow'
    ) NOT NULL DEFAULT 'airtel_money';
