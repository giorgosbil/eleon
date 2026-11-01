#!/usr/bin/env python3
"""Export standalone source without runtime data, credentials, or dependency caches."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
root = Path(__file__).resolve().parents[1]
out = root / 'exports' / 'other-things-local.zip'
out.parent.mkdir(exist_ok=True)
# Never in a package: data (backups hold copies of the mail), this PC's tools and earlier releases.
skip_dirs = {'.git', 'vendor', 'storage', 'cache', 'logs', 'exports', 'local-export', 'node_modules', '__pycache__',
             'backups', 'backups-offsite', '.claude', 'dist', 'updates'}
with ZipFile(out, 'w', compression=ZIP_DEFLATED) as bundle:
    for path in sorted(root.rglob('*')):
        relative = path.relative_to(root)
        if not path.is_file() or path.is_symlink() or (relative.parts[0] in skip_dirs or {'node_modules', '__pycache__'}.intersection(relative.parts)): continue
        if path.name.startswith('.env') and path.name != '.env.example': continue
        if path.name in {'config.local.php', 'caddy-root.crt'} or path.suffix in {'.zip', '.gz', '.log', '.bak', '.pem', '.key', '.pyc', '.crt', '.enc'}: continue
        # .sql: the schema (database/migrations) must ship; any other .sql file is a dump and must not.
        if path.suffix == '.sql' and relative.parts[:2] != ('database', 'migrations'): continue
        bundle.write(path, Path('other_things') / relative)
print(f'{out} ({out.stat().st_size:,} bytes)')
# A package without the schema installs a broken app (no tables): refuse to produce one.
with ZipFile(out) as check:
    sql = [n for n in check.namelist() if n.startswith('other_things/database/migrations/') and n.endswith('.sql')]
expected = len(list((root / 'database' / 'migrations').glob('*.sql')))
if len(sql) != expected or expected == 0:
    out.unlink()
    raise SystemExit(f'Package is missing database migrations ({len(sql)} of {expected}); nothing was written.')
print(f'{len(sql)} database migrations included')
