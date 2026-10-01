# Собирает docs/eval-report.md из отчёта bot:eval и оценок с комментариями из verdicts.py.
# Использование (с хоста, нужен Python 3):
#   python docs/eval/tools/make_report.py docs/eval/<отчёт>.md docs/eval/tools/verdicts.py docs/eval-report.md
# После нового прогона сначала перечитать ответы и поправить оценки в verdicts.py.
import re, sys, importlib.util

src, verdicts_path, out = sys.argv[1:4]
spec = importlib.util.spec_from_file_location('v', verdicts_path)
v = importlib.util.module_from_spec(spec); spec.loader.exec_module(v)

text = open(src, encoding='utf-8').read()
head = re.search(r'Дата прогона: .*?\n', text).group(0).strip()
rows = []
for line in text.split('\n'):
    if re.match(r'^\| \d+ \|', line):
        cells = [c.strip() for c in line.strip('|').split(' | ')]
        rows.append(cells)

md = v.HEADER.replace('{{run}}', head) + '\n'
md += '| № | Ответ бота | Передано оператору (да/нет) | Ваша оценка (верно / неверно / спорно) | Комментарий |\n|---|---|---|---|---|\n'
counts = {}
for n, reply, op, auto, comment in rows:
    n = int(n)
    verdict, note = v.VERDICTS[n]
    counts[verdict] = counts.get(verdict, 0) + 1
    model = re.search(r'\((model|invalid_refs|llm_error)[^)]*?(gemini-[\w.-]+)', comment)
    model = model.group(2) if model else '—'
    refs = re.search(r'п\. ([\d., ]+)\)', comment)
    refs = ('п. ' + refs.group(1).strip()) if refs else 'без пунктов'
    md += f'| {n} | {reply} | {op} | {verdict} | {note} Пункты бота: {refs}. Модель: {model}. |\n'
md += '\n' + v.FOOTER.replace('{{counts}}', ', '.join(f'{k} — {counts.get(k, 0)}' for k in (v.V, v.S, v.N)))
open(out, 'w', encoding='utf-8', newline='\n').write(md)
print(counts)
