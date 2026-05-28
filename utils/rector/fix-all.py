#!/usr/bin/env python3
"""Post-Rector fixer: adds ->all() to collect() chains that need it.

Run after: vendor/bin/rector process src/ --no-progress-bar
"""

import glob, re, sys

COLLECTION_METHODS = {'values', 'unique', 'keys', 'flip', 'reverse', 'filter', 'map', 'slice'}
NON_COLLECTION = {'contains', 'containsStrict', 'join', 'count', 'first', 'last', 'isEmpty',
                  'isNotEmpty', 'search', 'sort', 'sortBy', 'sortKeys', 'every', 'reduce', 'reject'}

def fix_file(filepath: str) -> bool:
    with open(filepath) as f:
        content = f.read()

    lines = content.split('\n')
    changed = False

    for i, line in enumerate(lines):
        stripped = line.rstrip()

        # Skip if already has ->all()
        if '->all()' in stripped:
            continue

        # Skip count(collect(...)->...) — count() handles Collection
        if re.search(r'count\s*\(\s*collect\(', stripped):
            continue

        # Only touch lines that end a statement or argument
        if not (stripped.endswith(';') or stripped.endswith('),') or stripped.endswith('));')):
            continue

        # Find the LAST ->method( in the chain
        matches = list(re.finditer(r'->(\w+)\s*\(', stripped))
        if not matches:
            continue

        last_method = matches[-1].group(1)
        if last_method not in COLLECTION_METHODS:
            continue

        # Find the matching closing paren using depth tracking
        last_match = matches[-1]
        open_pos = last_match.end() - 1  # position of (
        depth = 1
        close_pos = None
        for j in range(open_pos + 1, len(stripped)):
            if stripped[j] == '(':
                depth += 1
            elif stripped[j] == ')':
                depth -= 1
                if depth == 0:
                    close_pos = j
                    break

        if close_pos is None:
            continue

        after_close = stripped[close_pos + 1:].lstrip()

        # If this method is followed by another ->method, it's not the chain end
        if after_close.startswith('->'):
            next_method = re.match(r'->(\w+)', after_close)
            if next_method and next_method.group(1) not in NON_COLLECTION:
                continue  # Chain continues — don't add all() here

        # Insert ->all() after the closing paren
        new_line = stripped[:close_pos + 1] + '->all()' + stripped[close_pos + 1:]
        lines[i] = new_line
        changed = True

    if changed:
        with open(filepath, 'w') as f:
            f.write('\n'.join(lines) + '\n')

    return changed


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else 'src/'
    total = 0

    for fp in sorted(glob.glob(f'{path}/**/*.php', recursive=True)):
        if fix_file(fp):
            total += 1
            print(f'  + {fp}')

    print(f'\nFixed {total} files.')


if __name__ == '__main__':
    main()
