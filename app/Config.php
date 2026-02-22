<?php
class Config {
    // DB Settings
    public const DB_HOST = '127.0.0.1';
    public const DB_NAME = 'fastfood_pos';
    public const DB_USER = 'root';
    public const DB_PASS = '';
    public const DB_CHARSET = 'utf8mb4';

    // App Settings
    public const APP_ENV = 'local';
    public const APP_DEBUG = true;

    // Tax/Service defaults (percentage)
    public const TAX_RATE_PERCENT = 0.0;         // e.g. 5.0 for 5%
    public const SERVICE_CHARGE_PERCENT = 0.0;   // e.g. 10.0 for 10%

    // CORS (agar zarurat ho)
    public const CORS_ALLOWED_ORIGIN = '*';
}