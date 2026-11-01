#!/usr/bin/env python3
"""Export a reviewed current snapshot, never private Git history or local data."""
from pathlib import Path
import argparse,hashlib,json,re,subprocess

ROOT=Path(__file__).resolve().parents[1]
EXTRA=['LICENSE','scripts/export-public.py','tests/community_edition_test.php','web/settings/community.php',
       'Branding.cmd','lib/product_brand.php','web/manifest.php','scripts/branding.ps1','tests/product_brand_test.php','tests/welcome_setup_test.cjs','docs/BRANDING.md']
EXTRA += [str(p.relative_to(ROOT)).replace('\\','/') for p in (ROOT/'web/assets/brands').rglob('*') if p.is_file()]
PUBLIC_DOCS={'LOCAL.md','AI.md','CALENDAR.md','CAPABILITIES.md','MICROSOFT_ADMIN_APPROVAL.md','SETUP.md','BRANDING.md'}
SKIP_ROOT={'.git','.vscode','.claude','.codex','.agents','.aws','storage','cache','logs','backups','backups-offsite','exports','dist','updates','vendor','welcome-images','tools'}
SECRET_PATTERNS=[r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',r'\bgh[pousr]_[A-Za-z0-9]{30,}',r'\bgithub_pat_[A-Za-z0-9_]{30,}',r'\bsk-proj-[A-Za-z0-9_-]{25,}',r'\bGOCSPX-[A-Za-z0-9_-]{20,}',r'\bAKIA[0-9A-Z]{16}\b']

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output',type=Path,default=ROOT/'exports/eleon-public')
    args=parser.parse_args();out=args.output.resolve()
    assert out.is_relative_to((ROOT/'exports').resolve()) and out!=(ROOT/'exports').resolve(),'Export must be a child of the ignored exports directory'
    tracked=subprocess.check_output(['git','ls-files','-z'],cwd=ROOT).decode().split('\0')
    report={'included':{},'omitted':[],'review_required':[]}
    files={name for name in tracked+EXTRA if name}
    for name in sorted(files):
        parts=Path(name).parts;path=ROOT/name
        if parts[0] in SKIP_ROOT or (parts[0]=='docs' and (len(parts)!=2 or parts[1] not in PUBLIC_DOCS)) or name in ['README.md','SUMMARY.md','CHANGELOG.md','icon.ai','DataPull.cmd','DataPush.cmd','Push.cmd','Pull.cmd']:
            report['omitted'].append(name);continue
        if path.is_symlink() or not path.is_file():raise RuntimeError('Unexpected source path: '+name)
        if (path.name.startswith('.env') and path.name!='.env.example') or path.name=='config.local.php' or path.suffix.lower() in ['.key','.pem','.license','.sqlite','.db','.zip','.log','.bak','.crt']:
            raise RuntimeError('Sensitive path in export: '+name)
        if path.suffix=='.sql' and not (name.startswith('database/migrations/') or name.startswith('modules/')):raise RuntimeError('Non-migration SQL: '+name)
        body=path.read_bytes()
        if path.suffix.lower() in ['.php','.js','.json','.md','.html','.sh','.ps1','.yaml','.yml'] or path.name=='.env.example':
            text=body.decode('utf-8',errors='replace')
            for pattern in SECRET_PATTERNS:
                if re.search(pattern,text):report['review_required'].append({'path':name,'pattern':pattern})
            if name=='welcome.html':
                text=re.sub(r'<button\b[^>]*\bdata-guide="[^"]*"[^>]*>.*?</button>','',text,flags=re.S)
            if name.startswith('docs/'):
                text=re.sub(r'/home/(?:devzetisis|cloudpanel|giorgosbil)/[^\s`"<>]+','/path/to/eleon',text)
                text=re.sub(r'\b(?:dev\.|app\.)?zetisis\.com\b','example.com',text)
            if name in ['scripts/setup_microsoft_app.md','lib/account_security.php','tests/collaboration_test.php','tests/sync_test.php']:
                text=text.replace('georgeb@inquality.gr','person@example.test').replace('hello@zetisis.com','sender@example.test').replace('dev.zetisis.com','example.com').replace('g•••@zetisis.com','p•••@example.com')
            body=text.encode('utf-8')
        dest=out/name
        assert dest.resolve().is_relative_to(out)
        dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(body)
        report['included'][name]=hashlib.sha256(body).hexdigest()
    assert report['included'].get('lib/license.php') and report['included'].get('modules/telegram/module.php')
    assert len([name for name in report['included'] if name.startswith('database/migrations/') and name.endswith('.sql')])==len(list((ROOT/'database/migrations').glob('*.sql')))
    output=ROOT/'cache/public-export-review.json';output.parent.mkdir(exist_ok=True)
    output.write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    print('Exported',len(report['included']),'source files. Omitted',len(report['omitted']),'private/local/history/screenshot paths.')
    print('Credential-pattern review candidates:',len(report['review_required']))
    if report['review_required']:
        print('Publication blocked pending private review:',output)
        raise SystemExit(2)
    print('Snapshot scan passed. Full history was not exported. Report:',output)

if __name__=='__main__':main()
