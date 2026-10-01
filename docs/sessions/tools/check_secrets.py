#!/usr/bin/env python3
"""Проверка файлов на секреты перед коммитом.

Ищет в файлах точные значения секретов из локального .env (переменные со
словами TOKEN, KEY, SECRET, PASSWORD в имени, кроме значений, совпадающих
с .env.example — они публичные) и общие шаблоны: токен бота Telegram, ключи
Google (AIza…, AQ.…), APP_KEY Laravel (base64:…), приватные ключи PEM.
Значения на экран не выводит: только файл, номер строки и первые четыре символа.

Использование:
    python docs/sessions/tools/check_secrets.py            # все отслеживаемые git файлы и docs/sessions/*
    python docs/sessions/tools/check_secrets.py файл …     # только указанные файлы

Код возврата 1, если что-то найдено.
"""

import glob
import os
import re
import subprocess
import sys

PATTERNS = {
    'токен Telegram': re.compile(r'\b[0-9]{8,10}:[A-Za-z0-9_-]{30,}'),
    'ключ Google (AIza)': re.compile(r'AIza[0-9A-Za-z_-]{30,}'),
    'ключ Google (AQ.)': re.compile(r'\bAQ\.[A-Za-z0-9_-]{30,}'),
    'APP_KEY': re.compile(r'base64:[A-Za-z0-9+/]{40,}={0,2}'),
    'приватный ключ': re.compile(r'-----BEGIN [A-Z ]*PRIVATE KEY-----'),
}
SECRET_NAMES = re.compile(r'TOKEN|KEY|SECRET|PASSWORD', re.I)
SKIP_FILES = {'.env', '.env.example'}


def read_env(path: str) -> dict:
    values = {}
    if not os.path.isfile(path):
        return values
    with open(path, encoding='utf-8', errors='ignore') as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            name, value = line.split('=', 1)
            values[name.strip()] = value.strip().strip('"').strip("'")
    return values


def secret_values(root: str) -> dict:
    env = read_env(os.path.join(root, '.env'))
    public = set(read_env(os.path.join(root, '.env.example')).values())
    return {name: value for name, value in env.items()
            if SECRET_NAMES.search(name) and len(value) >= 8 and value not in public}


def target_files(root: str, args: list) -> list:
    if args:
        return args
    tracked = subprocess.run(['git', 'ls-files'], cwd=root, capture_output=True, text=True, check=True).stdout.split('\n')
    files = {os.path.join(root, f) for f in tracked if f}
    files.update(glob.glob(os.path.join(root, 'docs', 'sessions', '*')))
    return sorted(f for f in files if os.path.isfile(f) and os.path.basename(f) not in SKIP_FILES)


def mask(found: str) -> str:
    return found[:4] + '*' * max(4, len(found) - 4)


def main(argv: list) -> int:
    root = subprocess.run(['git', 'rev-parse', '--show-toplevel'], capture_output=True, text=True, check=True).stdout.strip()
    secrets = secret_values(root)
    problems = 0
    for path in target_files(root, argv):
        try:
            with open(path, encoding='utf-8') as fh:
                lines = fh.read().split('\n')
        except (UnicodeDecodeError, OSError):
            continue
        rel = os.path.relpath(path, root)
        for number, line in enumerate(lines, 1):
            for name, value in secrets.items():
                if value in line:
                    print(f'{rel}:{number}: значение {name} из .env ({mask(value)})')
                    problems += 1
            for label, pattern in PATTERNS.items():
                for match in pattern.finditer(line):
                    print(f'{rel}:{number}: похоже на {label} ({mask(match.group(0))})')
                    problems += 1
    if problems:
        print(f'Найдено: {problems}. Коммитить нельзя.')
        return 1
    print('Секретов не найдено.')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
