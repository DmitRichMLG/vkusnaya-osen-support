#!/usr/bin/env python3
"""Экспорт сессии Claude Code в текстовый лог.

Команда /export в Claude Code выгружает только текущую сессию, поэтому прошлые
сессии выгружаются этим скриптом из локальных транскриптов Claude Code
(~/.claude/projects/<папка проекта>/<id сессии>.jsonl). Формат повторяет
вывод /export: реплики разработчика начинаются с «>», ответы агента с «●»,
вызовы инструментов записаны как «● Инструмент(аргумент)», их результаты
свёрнуты в строки «⎿» (первые несколько строк и счётчик остальных).

Использование:
    python docs/sessions/tools/export_session.py <id сессии или путь к .jsonl> <выходной файл> [--lines N]

В лог не попадают скрытые рассуждения модели, служебные записи (напоминания
системы, снимки файлов, учёт стоимости) и ветки субагентов. Время — московское.
Секреты скрипт не ищет: перед коммитом запустите docs/sessions/tools/check_secrets.py.
"""

import glob
import json
import os
import re
import sys
from datetime import datetime, timedelta, timezone

MSK = timezone(timedelta(hours=3))
RESULT_LINES = 6
LINE_WIDTH = 200

TAG_CMD = re.compile(r'<command-name>(.*?)</command-name>', re.S)
TAG_ARGS = re.compile(r'<command-args>(.*?)</command-args>', re.S)
TAG_STDOUT = re.compile(r'<local-command-stdout>(.*?)</local-command-stdout>', re.S)
DROP_BLOCKS = re.compile(
    r'<system-reminder>.*?</system-reminder>|<local-command-caveat>.*?</local-command-caveat>'
    r'|<command-message>.*?</command-message>', re.S)
UNWRAP_TAGS = re.compile(r'</?(pasted_content|agent-message|task-notification|fast-mode-fallback)[^>]*>')
ANSI = re.compile(r'\x1b\[[0-9;?]*[ -/]*[@-~]')
CONTROL = re.compile(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]')


def clean(text: str) -> str:
    """Убирает цветовые коды, CRLF и управляющие символы из вывода инструментов,
    иначе git считает лог бинарным файлом или файлом со смешанными концами строк."""
    text = ANSI.sub('', text).replace('\r\n', '\n').replace('\r', '\n')
    return CONTROL.sub('', text)


def find_transcript(arg: str) -> str:
    if os.path.isfile(arg):
        return arg
    root = os.path.join(os.path.expanduser('~'), '.claude', 'projects')
    hits = [p for p in glob.glob(os.path.join(root, '*', '*.jsonl')) if os.path.basename(p).startswith(arg)]
    if len(hits) != 1:
        sys.exit(f'Транскрипт по «{arg}» не найден или найдено несколько: {hits}')
    return hits[0]


def load(path: str) -> list:
    entries = []
    with open(path, encoding='utf-8') as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                entries.append(json.loads(line))
            except json.JSONDecodeError:
                continue
    return entries


def stamp(iso: str, with_date: bool = True) -> str:
    dt = datetime.fromisoformat(iso.replace('Z', '+00:00')).astimezone(MSK)
    return dt.strftime('%d.%m %H:%M' if with_date else '%H:%M')


def indent(text: str, first: str, rest: str) -> str:
    lines = text.rstrip('\n').split('\n')
    return '\n'.join((first if i == 0 else rest) + line for i, line in enumerate(lines))


def shorten(text: str, max_lines: int) -> str:
    lines = text.rstrip().split('\n')
    shown = [l[:LINE_WIDTH] + ('…' if len(l) > LINE_WIDTH else '') for l in lines[:max_lines]]
    if len(lines) > max_lines:
        shown.append(f'… ещё {len(lines) - max_lines} строк')
    return '\n'.join(shown)


def blocks_text(content) -> str:
    if isinstance(content, str):
        return content
    parts = []
    for block in content or []:
        if not isinstance(block, dict):
            continue
        if block.get('type') == 'text':
            parts.append(block.get('text', ''))
        elif block.get('type') == 'image':
            parts.append('[изображение]')
    return '\n'.join(parts)


def tool_summary(name: str, inp, cwd: str) -> str:
    if not isinstance(inp, dict):
        return ''

    def rel(path):
        if isinstance(path, str) and cwd and path.replace('/', '\\').lower().startswith(cwd.replace('/', '\\').lower()):
            return path[len(cwd):].lstrip('\\/')
        return path or ''

    if name in ('Bash', 'PowerShell'):
        text = inp.get('description') or (inp.get('command') or '').strip().split('\n')[0]
    elif name in ('Read', 'Edit', 'Write', 'MultiEdit', 'NotebookEdit'):
        text = rel(inp.get('file_path') or inp.get('notebook_path'))
    elif name in ('Grep', 'Glob'):
        text = inp.get('pattern', '')
    elif name in ('Agent', 'Task'):
        text = inp.get('description') or ''
    elif name == 'Skill':
        text = inp.get('skill', '')
    elif name == 'Workflow':
        match = re.search(r"name:\s*'([^']+)'", inp.get('script') or '')
        text = match.group(1) if match else (inp.get('name') or inp.get('scriptPath') or '')
    elif name == 'AskUserQuestion':
        text = '; '.join(q.get('question', '') for q in inp.get('questions') or [] if isinstance(q, dict))
    elif name == 'WebSearch':
        text = inp.get('query', '')
    elif name == 'WebFetch':
        text = inp.get('url', '')
    elif name == 'TodoWrite':
        text = f"{len(inp.get('todos') or [])} задач"
    else:
        text = next((str(v) for v in inp.values() if isinstance(v, (str, int)) and str(v)), '')
    text = str(text).replace('\n', ' ')
    return text if len(text) <= 120 else text[:117] + '…'


def render_user(text: str, when: str, max_lines: int):
    text = DROP_BLOCKS.sub('', clean(text)).strip()
    if not text:
        return None
    stdout = TAG_STDOUT.search(text)
    command = TAG_CMD.search(text)
    if stdout and not command:
        # Вывод команды Claude Code приходит отдельной записью после самой команды.
        body = stdout.group(1).strip()
        return indent(shorten(body, max_lines), '  ⎿  ', '     ') if body else None
    if command:
        args = TAG_ARGS.search(text)
        line = '/' + command.group(1).strip().lstrip('/')
        if args and args.group(1).strip():
            line += ' ' + args.group(1).strip()
        out = f'> [{when}] {line}'
        if stdout and stdout.group(1).strip():
            out += '\n' + indent(shorten(stdout.group(1).strip(), max_lines), '  ⎿  ', '     ')
        return out
    if text.startswith('Base directory for this skill:'):
        first = text.split('\n', 1)[0]
        name = os.path.basename(first.split(':', 1)[1].strip().rstrip('/\\'))
        return f'  [загружен скилл: {name}]'
    service = text.startswith(('<agent-message', 'Another Claude session sent a message', '<task-notification'))
    text = UNWRAP_TAGS.sub('', text).strip()
    if service:
        return indent(shorten(text, 12), f'> [{when}] [сообщение фонового агента] ', '  ')
    return indent(text, f'> [{when}] ', '  ')


def render_result(block: dict, max_lines: int) -> str:
    text = clean(blocks_text(block.get('content'))).rstrip()
    if block.get('is_error'):
        text = 'Ошибка: ' + text
    if not text:
        return '  ⎿  (пусто)'
    return indent(shorten(text, max_lines), '  ⎿  ', '     ')


def export(path: str, out_path: str, max_lines: int) -> None:
    entries = load(path)
    cwd = next((e.get('cwd') for e in entries if e.get('cwd')), '')
    version = next((e.get('version') for e in entries if e.get('version')), '')
    title = next((e.get('aiTitle') for e in reversed(entries) if e.get('type') == 'ai-title'), '')
    times = [e['timestamp'] for e in entries if e.get('type') in ('user', 'assistant') and e.get('timestamp')]
    models = sorted({e.get('message', {}).get('model') for e in entries
                     if e.get('type') == 'assistant' and e.get('message', {}).get('model')})
    session_id = os.path.splitext(os.path.basename(path))[0]

    lines = []
    prompts = 0
    for e in entries:
        if e.get('isSidechain') or e.get('isMeta'):
            continue
        kind = e.get('type')
        message = e.get('message') or {}
        content = message.get('content')
        when = stamp(e.get('timestamp', '1970-01-01T00:00:00Z'))
        if kind == 'user':
            blocks = content if isinstance(content, list) else [{'type': 'text', 'text': content or ''}]
            results = [b for b in blocks if isinstance(b, dict) and b.get('type') == 'tool_result']
            for block in results:
                lines.append(render_result(block, max_lines))
            if results:
                continue
            rendered = render_user(blocks_text(blocks), when, max_lines)
            if rendered:
                if not rendered.startswith('  ⎿'):
                    lines.append('')
                lines.append(rendered)
                if rendered.startswith('>') and not rendered.startswith(f'> [{when}] /'):
                    prompts += 1
        elif kind == 'assistant':
            blocks = content if isinstance(content, list) else [{'type': 'text', 'text': content or ''}]
            for block in blocks:
                if not isinstance(block, dict):
                    continue
                if block.get('type') == 'text' and block.get('text', '').strip():
                    lines.append('')
                    lines.append(indent(clean(block['text']).strip(), '● ', '  '))
                elif block.get('type') == 'tool_use':
                    lines.append('')
                    lines.append(f"● {block.get('name')}({tool_summary(block.get('name'), block.get('input'), cwd)})")

    header = [
        f'Сессия Claude Code: {title or session_id}',
        f'Идентификатор: {session_id}',
        f'Время: {stamp(times[0])} – {stamp(times[-1])} МСК' if times else 'Время: неизвестно',
        f'Реплик разработчика: {prompts}. Модели: {", ".join(models) or "?"}. Claude Code {version}.',
        'Выгружено из локального транскрипта скриптом docs/sessions/tools/export_session.py:',
        f'результаты инструментов свёрнуты до {max_lines} строк, рассуждения модели и служебные записи опущены.',
        '',
    ]
    with open(out_path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('\n'.join(header + lines).rstrip() + '\n')
    print(f'{out_path}: {prompts} реплик, {len(lines)} строк, {os.path.getsize(out_path) // 1024} КБ')


def main(argv: list) -> None:
    args = [a for a in argv if not a.startswith('--')]
    max_lines = RESULT_LINES
    for i, a in enumerate(argv):
        if a == '--lines' and i + 1 < len(argv):
            max_lines = int(argv[i + 1])
            args = [x for x in args if x != argv[i + 1]]
    if len(args) != 2:
        sys.exit(__doc__)
    export(find_transcript(args[0]), args[1], max_lines)


if __name__ == '__main__':
    main(sys.argv[1:])
