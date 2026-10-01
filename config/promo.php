<?php

// Все настройки акции и интеграций в одном месте. Секреты только из .env.
return [

    // Правила написаны по московскому времени (п. 1.5). Храним всё в UTC, сюда переводим при показе и расчётах.
    'timezone' => 'Europe/Moscow',

    // Первый оператор панели создаётся при старте из .env.
    'operator' => [
        'email' => env('OPERATOR_EMAIL'),
        'password' => env('OPERATOR_PASSWORD'),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // По порядку: при любом сбое пробуем следующую модель.
        'models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_MODELS', 'gemini-3.8-flash,gemini-3.5-flash'))))),
    ],

];
