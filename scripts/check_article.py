"""Checks the article against the run files and the repository.

Every number the article quotes is rebuilt from runs/*.json or runs/index-build.log and must appear in the
text, and every <pre> block that names a file must match that file line for line.
Usage: python3 -I scripts/check_article.py ../pgvector-laravel-semantic-search.html
"""
import html
import json
import re
import statistics
import sys
from pathlib import Path

root = Path(__file__).resolve().parent.parent
article = Path(sys.argv[1]).read_text()
text = html.unescape(re.sub(r'<[^>]+>', '', article))
runs = {name: json.loads((root / 'runs' / f'{name}.json').read_text()) for name in ['recall', 'teams', 'threshold', 'thresholds', 'shapes', 'remedy', 'layouts', 'analyze-flips', 'combined']}
failures = []
checked = 0


def expect(snippet, why):
    global checked
    checked += 1
    if snippet not in text:
        failures.append(f'missing "{snippet}" ({why})')


def fmt(x, digits=1):
    return f'{x:,.{digits}f}'


# teams
teams = {t['team_id']: t for t in runs['teams']['teams']}
hnsw = [t for t in teams.values() if 'hnsw' in t['off']['plans']]
btree = [t for t in teams.values() if t['off']['plans'] == ['other']]
assert [t['team_id'] for t in hnsw] == list(range(1, 12))
expect(f'{len(btree)} smaller teams, up to {max(t["size"] for t in btree):,} rows each', 'btree teams')
expect(f'hold {sum(t["size"] for t in hnsw):,} of the 100,000 rows', 'rows on hnsw teams')
assert all(t['off']['avg_rows'] == min(10, t['size']) and t['off']['recall'] == 1 for t in btree)
assert all(t['strict']['avg_rows'] == 10 for t in teams.values())
expect(f'got between {min(t["off"]["avg_rows"] for t in hnsw)} and {max(t["off"]["avg_rows"] for t in hnsw if t["team_id"] > 1)} rows', 'range, teams 2-11')
expect(f'team 1 holds {teams[1]["size"]:,} rows, team 11 holds {teams[11]["size"]:,}, team 150 holds {teams[150]["size"]}', 'sizes')
expect(f'Team 1, at 17%, got {teams[1]["off"]["avg_rows"]}', 'team 1')
assert round(teams[1]['size'] / 1000) == 17 and abs(teams[2]['size'] / 1000 - 8.5) < 0.05 and abs(teams[11]['size'] / 1000 - 1.5) < 0.01
for n in [1, 2, 4, 8, 11]:
    t = teams[n]
    row = f'{n}{t["size"]:,}{t["off"]["avg_rows"]:g}{t["off"]["recall"] * 100:g}%10{t["strict"]["recall"] * 100:g}%{t["strict"]["median_ms"]:.1f} ms'
    expect(row, f'team table row {n}')
strict = [t['strict']['recall'] for t in hnsw]
expect(f'{min(strict) * 100:g}% to {max(strict) * 100:g}% of the returned rows', 'strict recall range')
expect(f'grew from about 3 ms to {teams[11]["strict"]["median_ms"]:.0f} ms', 'strict time')
checked += 1
if f'{teams[8]["strict"]["recall"] * 100:g}% of the true nearest' not in article:
    failures.append('figure alt text: team 8 recall')

# thresholds (timing)
th = {(r['min_similarity'], r['iterative_scan']): r for r in runs['threshold']['by_threshold']}
for m in [0.6, 0.7, 0.8, 0.9]:
    off, st = th[(m, 'off')], th[(m, 'strict_order')]
    assert off['avg_rows'] == st['avg_rows']
    expect(f'{m}{off["avg_rows"]:g}{off["laravel_median_ms"]:.1f} ms{st["laravel_median_ms"]:.1f} ms{st["subquery_median_ms"]:.1f} ms', f'threshold row {m}')
slow = [th[(m, 'strict_order')]['laravel_median_ms'] for m in [0.7, 0.8, 0.9]]
assert all(s > 20 * th[(m, 'off')]['laravel_median_ms'] for s, m in zip(slow, [0.7, 0.8, 0.9]))

# similarity threshold over the 40 questions
labels = {'bge': 'bge-small-en-v1.5', 'nomic': 'nomic-embed-text v1.5, with prefixes', 'nomic_unprefixed': 'nomic-embed-text v1.5, question without prefix'}
for model, label in labels.items():
    rows = runs['thresholds'][model]
    first = sum(1 for r in rows if int(r['rank']) == 1)
    top10 = sum(1 for r in rows if int(r['rank']) <= 10)
    passing = sum(1 for r in rows if r['target'] >= 0.6)
    others = statistics.median(int(r['above_default']) for r in rows)
    expect(f'{label}{first} of 40{top10} of 40{passing} of 40{others:g}', f'threshold table {model}')
bge_targets = statistics.median(r['target'] for r in runs['thresholds']['bge'])
expect(f'at a median similarity of {bge_targets:.2f} with bge', 'median target')

# recall
for r in runs['recall']['by_ef_search']:
    expect(f'{r["recall_at_10"] * 100:.2f}%{r["median_ms"]:.1f} ms', f'recall row {r["ef_search"]}')
expect(f'took a median of {runs["recall"]["exact_median_ms"]:.0f} ms', 'exact search')

# query shapes
sh = runs['shapes']
expect(f'took a median of {sh["limit_10"]["median_ms"]:.1f} ms on the server', 'limit 10')
expect(f'{sh["no_limit"]["median_ms"]:.1f} ms', 'no limit')
expect(f'median {sh["no_limit"]["median_rows"]:g}, max {sh["no_limit"]["max_rows"]:,}', 'no limit rows')
expect(f'{sh["paginate_count"]["median_ms"]:.1f} ms', 'count')
expect(f'a median of {sh["no_limit"]["median_rows"]:g} of the 100,000 rows', 'rows at 0.6')
expect(f'about {sh["avg_embedding_text_bytes"] / 1000:.1f} KB of text each', 'embedding text size')
assert sh['limit_10']['used_hnsw'] == 50 and sh['no_limit']['used_hnsw'] == 0

# index builds
log = (root / 'runs' / 'index-build.log').read_text()
times = [float(t) / 1000 for t in re.findall(r'Time: ([\d.]+) ms', log)]
# order in the script: version, show, create, create index, insert, create, insert, set, build 512MB, drop, set, build 64MB, drops
insert_indexed, insert_plain, build_512, build_64 = times[4], times[6], times[8], times[11]
expect(f'{round(insert_indexed)} s', 'insert with index, run 2')
expect(f'{insert_plain:.1f} s + {build_512:.1f} s', 'insert then build, run 2')
expect(f'{build_64:.1f} s', 'build at 64MB, run 2')
notice = int(re.search(r'after (\d+) tuples', log).group(1))
assert abs(notice - 28000) < 500
# run 1 was printed by psql in this session and is kept in runs/index-build-run1.log
run1 = (root / 'runs' / 'index-build-run1.log').read_text()
t1 = [float(t) / 1000 for t in re.findall(r'Time: ([\d.]+) ms', run1)]
expect(f'{round(t1[2])} s', 'insert with index, run 1')
expect(f'{t1[4]:.1f} s + {t1[5]:.1f} s', 'insert then build, run 1')
expect(f'{t1[8]:.1f} s', 'build at 64MB, run 1')

# import
imp = re.search(r'100000 rows in ([\d.]+) s \(([\d.]+) s of it embedding\)', (root / 'runs' / 'import-100k.log').read_text())
expect(f'took {round(float(imp.group(1)))} seconds, {round(float(imp.group(2)))} of them waiting', 'import time')

# remedy: subquery form on teams 1-11
for r in runs['remedy']['teams_1_to_11']:
    expect(f'{r["iterative_scan"]}order by {r["outer_order"]}{r["rows"]:,} of 2,200{r["recall"] * 100:.1f}%{r["searches_out_of_order"]} of 220{r["median_ms"]:.1f} ms', f'remedy row {r["iterative_scan"]} {r["outer_order"]}')
relaxed = runs['remedy']['teams_1_to_11'][2]['recall']
strict_r = runs['remedy']['teams_1_to_11'][0]['recall']
expect(f'found {relaxed * 100:.1f}% of the true nearest rows instead of {strict_r * 100:.1f}%', 'remedy prose')
expect(f'{runs["remedy"]["teams_1_to_11"][1]["searches_out_of_order"]} of 220 result lists', 'out of order prose')
expect(f'2 to 3 ms per row', 'insert rate')
assert 2 <= t1[2] / 100 <= 3 and 2 <= insert_indexed / 100 <= 3.1
expect(f'up to {round((insert_indexed / t1[2] - 1) * 100)}% slower', 'run 2 vs run 1')

# partial indexes and partitions
lay = runs['layouts']['layouts']
def lrow(label, team, key):
    r = lay[key]
    return f'{label}{team}{r["rows"]}{r["recall"] * 100:g}%{r["median_ms"]:.1f} ms'
expect(lrow('Shared table, one HNSW index', 8, 'shared_team_8'), 'layout shared 8')
expect(lrow('Shared table plus create index … where team_id = 8', 8, 'partial_index_team_8'), 'layout partial 8')
expect(lrow('Partitioned, team 8 has its own partition', 8, 'partitioned_team_8'), 'layout partition 8')
expect(lrow('Partitioned, team 2 has its own partition', 2, 'partitioned_team_2'), 'layout partition 2')
expect(lrow('Partitioned, team 13 in the default partition', 13, 'partitioned_team_13'), 'layout default 13')
expect(lrow('Same, with hnsw.iterative_scan = relaxed_order', 13, 'partitioned_relaxed_team_13'), 'layout relaxed 13')
own = [lay[f'partitioned_team_{t}'] for t in [1, 2, 4, 8, 11]]
assert all(r['rows'] == 200 for r in own)
expect(f'at {min(r["recall"] for r in own) * 100:g}% to {max(r["recall"] for r in own) * 100:g}% recall', 'own partitions recall')
expect(f'default partition of {lay["default_partition_rows"]:,} rows', 'default partition size')
expect(f'Team 13 has {lay["team_sizes"]["13"]:,} rows', 'team 13 size')
expect(f'{lay["team_sizes"]["13"] / lay["default_partition_rows"] * 100:.1f}% of the partition', 'team 13 share')
assert 'Bitmap' in lay['shared_team_13']['plans'][0]
assert lay['partitioned_team_12']['rows'] < 50 and lay['partitioned_team_14']['rows'] < 50
expect(f'{lay["all_teams_partitioned"]["median_ms"]:.1f} ms instead of {lay["all_teams_shared"]["median_ms"]:.1f} ms', 'cross-team time')
expect(f'recall of {lay["all_teams_partitioned"]["recall"] * 100:g}% instead of {lay["all_teams_shared"]["recall"] * 100:g}%', 'cross-team recall')
flips = runs['analyze-flips']
r = flips['runs']
expect(f'I ran ANALYZE {len(r)} times in a row', 'analyze runs')
expect(f'between {min(len(x) for x in r)} and {max(len(x) for x in r)} teams, in {len({tuple(x) for x in r})} different sets', 'analyze sets')
sz = flips['sizes_9_to_16']
expect(f'Team 11, with {int(sz["11"]):,} rows, went to HNSW after {sum(11 in x for x in r)} of the {len(r)} runs, team 12, with {int(sz["12"]):,}, after {sum(12 in x for x in r)}', 'teams 11 and 12')
st = flips['statistics_1000']
assert len({tuple(x) for x in st}) == 1
expect(f'the same {len(st[0])} teams went to HNSW in all {len(st)} runs', 'statistics 1000')
comb = runs['combined']['teams']
assert len(comb) == 200
expect(f'{sum(x["rows"] for x in comb):,} of 20,000', 'combined rows')
expect(f'at least {min(x["recall"] for x in comb) * 100:g}% for every team and {sum(x["recall"] for x in comb) / 200 * 100:.1f}% on average', 'combined recall')
slow = max(comb, key=lambda x: x['median_ms'])
expect(f'team {slow["team_id"]} took a median of {slow["median_ms"]:.1f} ms', 'combined slowest')

# code blocks: each named file must contain the block's lines, in order
for block in re.findall(r'<pre><code class="language-(?:php|sql)">(.*?)</code></pre>', article, re.S):
    body = html.unescape(block)
    head = re.match(r'<\?php // (\S+)', body)
    if head is None:
        continue
    checked += 1
    source = (root / head.group(1)).read_text().splitlines()
    lines = [l for l in body.split('\n')[1:] if l.strip()]
    stripped = [l.strip() for l in source]
    position = 0
    for line in lines:
        try:
            position = stripped.index(line.strip(), position) + 1
        except ValueError:
            failures.append(f'{head.group(1)}: line not found in order: {line.strip()}')
            break

if failures:
    print('\n'.join(failures))
    print(f'{len(failures)} of {checked} checks failed')
    sys.exit(1)
print(f'{checked}/{checked} checks passed')
