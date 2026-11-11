"""Writes the chart spec for the article from runs/teams.json."""
import json
import sys

out = sys.argv[1]
slug = 'pgvector-laravel-semantic-search'

teams = sorted(json.load(open('runs/teams.json'))['teams'], key=lambda t: t['team_id'])[:30]
chart1 = {
    'kind': 'line',
    'title': 'Rows a team gets back when it asks for 10',
    'subtitle': 'Average over 20 searches. x: teams 1–30 of 200, largest first; team 1 holds 17% of the 100,000 rows.',
    'source': 'Source: runs/teams.json in the companion repository',
    'format': 'number',
    'x': {'max': 30, 'step': 5},
    'y': {'max': 10, 'step': 2, 'unit': 'rows', 'decimals': 2},
    'snap': 1,
    'series': [
        {'id': 'strict', 'label': 'hnsw.iterative_scan = strict_order', 'slot': 0,
         'points': [[t['team_id'], t['strict']['avg_rows']] for t in teams]},
        {'id': 'off', 'label': 'default (iterative scan off)', 'slot': 1,
         'points': [[t['team_id'], t['off']['avg_rows']] for t in teams]},
    ],
}

for n, spec in [(1, chart1)]:
    with open(f'{out}/{slug}-chart-{n}.json', 'w') as f:
        json.dump(spec, f, indent=1, ensure_ascii=False)
        f.write('\n')
print('ok')
