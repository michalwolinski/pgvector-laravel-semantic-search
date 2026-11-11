"""Builds data/corpus.jsonl: the first real paragraph of each Simple English Wikipedia article (CC BY-SA 4.0)."""
import json
import sys

import pyarrow.parquet as pq

source, target, limit = sys.argv[1], sys.argv[2], int(sys.argv[3])
table = pq.read_table(source, columns=['id', 'title', 'text'])
written = 0
with open(target, 'w') as out:
    for row in table.to_pylist():
        paragraph = next((p.strip() for p in row['text'].split('\n') if 200 <= len(p.strip()) <= 1200), None)
        if paragraph is None:
            continue
        out.write(json.dumps({'wiki_id': int(row['id']), 'title': row['title'], 'body': paragraph}) + '\n')
        written += 1
        if written == limit:
            break
print(f'{written} paragraphs from {table.num_rows} articles')
