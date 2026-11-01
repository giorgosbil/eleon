"""Run against an EMPTY disposable migrated DB configured with ECW_CONFIG_FILE.
Requires PHP, Python 3, and ECW_LOCAL_TEST=1. Never use the live database.
"""
import os, sys, json, re, time, tempfile, subprocess, urllib.request, urllib.parse, urllib.error, http.cookiejar
from pathlib import Path
assert os.environ.get('ECW_LOCAL_TEST') == '1', 'Explicit disposable-database opt-in required'
assert os.environ.get('ECW_CONFIG_FILE'), 'A disposable database configuration is required'
root = Path(__file__).resolve().parents[1]
env = dict(os.environ, ECW_LOCAL_FEATURES='1', ECW_PRIVATE_MAILBOXES='1', ECW_SETUP_TOKEN='local-integration-test-code', ECW_BASE_URL='http://127.0.0.1:18764')
base = 'http://127.0.0.1:18764/other_things/web/'
count = subprocess.check_output(['php', '-r', 'require $argv[1]."/lib/db.php"; echo ecw_db()->query("SELECT COUNT(*) FROM ecw_users")->fetchColumn();', str(root)], env=env).decode().strip()
assert count == '0', 'This test requires an EMPTY disposable database'
class Client:
    def __init__(self): self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(self, path, data=None, headers=None):
        body = urllib.parse.urlencode(data).encode() if isinstance(data, dict) else data
        try: response = self.opener.open(urllib.request.Request(base+path, data=body, headers=headers or {}))
        except urllib.error.HTTPError as e: response = e
        return response.status, response.read().decode()
    def form(self, path, data):
        _, text = self.request(path)
        token = re.search(r'name="csrf" value="([^"]+)"', text).group(1)
        return self.request(path, dict(data, csrf=token))
    def login(self, email):
        status, text = self.form('login.php', dict(email=email,password='Local-test-password-123'))
        assert status == 200 and 'Sign in to continue' not in text, (status,text[:500])
        self.csrf = json.loads(self.request('api/?action=session')[1])['csrf']
    def api(self, action, params=None, data=None):
        path = 'api/?'+urllib.parse.urlencode(dict(action=action, **(params or {})))
        status, text = self.request(path, json.dumps(data).encode() if data is not None else None,
            {'Content-Type':'application/json','X-ECW-CSRF':self.csrf})
        try: return status,json.loads(text)
        except ValueError: return status,text

def check(condition, name):
    assert condition, name
    print('PASS '+name, flush=True)

with tempfile.TemporaryDirectory(prefix='ecw-local-test-') as temp:
    # Only expose the tested workspace under its normal URL prefix.
    os.symlink(root, Path(temp)/'other_things')
    log = open(Path(temp)/'http.log','w+')
    server = subprocess.Popen(['php','-S','127.0.0.1:18764','-t',temp],env=env,stdout=log,stderr=log)
    try:
        admin = Client()
        for attempt in range(50):
            try: admin.request('setup.php'); break
            except urllib.error.URLError: time.sleep(.1)
        status,text = admin.form('setup.php',dict(setup_token=env['ECW_SETUP_TOKEN'],organization='Local test company',name='Admin Test',email='admin@local.example',password='Local-test-password-123'))
        check(status==200 and 'Your workspace is ready' in text,'first administrator setup')
        check('name="setup_token"' not in admin.request('setup.php')[1],'setup closes after first account')
        admin.login('admin@local.example')
        status,text = admin.form('settings/users.php',dict(action='invite',email='member@local.example'))
        token = re.search(r'register.php\?token=([a-f0-9]{64})',text).group(1)
        member = Client()
        status,text = member.form('register.php?token='+token,dict(token=token,name='Member Test',password='Local-test-password-123'))
        check(status==200 and 'Sign in' in text,'invited user chooses password')
        _,text = member.form('register.php?token='+token,dict(token=token,name='Replay',password='Local-test-password-123'))
        check('already used' in text,'invitation cannot be reused')
        member.login('member@local.example')
        check(member.request('settings/users.php')[0]==403,'ordinary user cannot administer users')
        fixture = r'''<?php
require_once $argv[1].'/lib/invitations.php'; require_once $argv[1].'/lib/compose.php'; require_once $argv[1].'/lib/cases.php';
$p=ecw_db(); $org=(int)$p->query("SELECT id FROM ecw_organizations WHERE name='Local test company'")->fetchColumn();
$admin=(int)$p->query("SELECT id FROM ecw_users WHERE email='admin@local.example'")->fetchColumn();
$member=(int)$p->query("SELECT id FROM ecw_users WHERE email='member@local.example'")->fetchColumn();
$ins=function($t,$r)use($p){$p->prepare('INSERT INTO `'.ecw_table($t).'` (`'.implode('`,`',array_keys($r)).'`) VALUES ('.implode(',',array_fill(0,count($r),'?')).')')->execute(array_values($r));return(int)$p->lastInsertId();};
$mb=$ins('mailboxes',['organization_id'=>$org,'email_address'=>'admin@local.example','connected_by_user_id'=>$admin]);
$own=$ins('mailboxes',['organization_id'=>$org,'email_address'=>'member@local.example','connected_by_user_id'=>$member]);
$t=$ins('email_threads',['mailbox_id'=>$mb,'normalized_subject'=>'Shared conversation']);
$private=$ins('email_threads',['mailbox_id'=>$mb,'normalized_subject'=>'Private conversation']);
$m=$ins('email_messages',['mailbox_id'=>$mb,'email_thread_id'=>$t,'provider_message_id'=>'local-message','direction'=>'incoming','body_html'=>'<p>Shared content</p>','body_fetch_state'=>'fetched','received_at'=>ecw_now()]);
$pm=$ins('email_messages',['mailbox_id'=>$mb,'email_thread_id'=>$private,'provider_message_id'=>'private-message','direction'=>'incoming','body_html'=>'<p>Private content</p>','body_fetch_state'=>'fetched','received_at'=>ecw_now()]);
$file=tempnam(sys_get_temp_dir(),'ecw-attachment-');file_put_contents($file,'shared attachment');
$a=$ins('email_attachments',['email_message_id'=>$m,'filename'=>'shared.txt','mime_type'=>'text/plain','local_path'=>$file,'provider_download_state'=>'downloaded']);
$pa=$ins('email_attachments',['email_message_id'=>$pm,'filename'=>'private.txt','mime_type'=>'text/plain','local_path'=>$file,'provider_download_state'=>'downloaded']);
$case=ecw_case_create($org,'Local test case',$admin); ecw_case_link_thread($case,$t,null,$admin);
$draft=ecw_draft_save($org,$mb,$admin,['subject'=>'Private draft','body_html'=>'<p>Never send</p>']);
$outside=$ins('users',['name'=>'Outside','email'=>'outside@local.example','password_hash'=>'unused']);
$expired=ecw_create_invitation($admin,$org,'expired@local.example');$p->exec("UPDATE ecw_user_invitations SET expires_at='2000-01-01' WHERE email='expired@local.example'");
$reject=false;try{ecw_accept_invitation($expired,'Expired','Local-test-password-123');}catch(InvalidArgumentException $e){$reject=true;} if(!$reject)throw new RuntimeException('expired invitation accepted');
echo json_encode(compact('org','admin','member','mb','own','t','private','m','pm','a','pa','case','draft','outside','file'));
'''
        fixture_path=Path(temp)/'fixture.php';fixture_path.write_text(fixture)
        ids=json.loads(subprocess.check_output(['php',str(fixture_path),str(root)],env=env))
        check([int(m['id']) for m in member.api('mailboxes')[1]['mailboxes']]==[ids['own']],'mailbox list contains only own mailbox')
        for action in ['threads','folders','updates','compose']:
            check(member.api(action,dict(mailbox_id=ids['mb']))[0]==404,action+' cannot access another mailbox')
        check(member.api('thread',dict(mailbox_id=ids['mb'],id=ids['t']))[0]==200,'shared case thread is readable')
        check(member.api('thread',dict(mailbox_id=ids['mb'],id=ids['private']))[0]==404,'unshared thread remains private')
        check(member.api('body',dict(mailbox_id=ids['mb'],message_id=ids['m']))[0]==200,'shared message body is readable')
        check(member.api('body',dict(mailbox_id=ids['mb'],message_id=ids['pm']))[0]==404,'unshared body remains private')
        check(member.request('api/?action=attachment&id='+str(ids['a']))[0]==200,'shared attachment is readable')
        check(member.request('api/?action=attachment&id='+str(ids['pa']))[0]==404,'unshared attachment remains private')
        for action in ['draft_get','draft_save','draft_discard','draft_detach','send']:
            status,_=member.api(action,dict(draft_id=ids['draft']),None if action=='draft_get' else {})
            check(status==404,action+' refuses another user draft')
        status,data=member.api('case_timeline',dict(case_id=ids['case']))
        check(status==200 and data['columns'][0]['can_write'] is False,'case marks other mailbox as read only')
        status,data=admin.api('case_update',dict(case_id=ids['case']),dict(owner_user_id=ids['admin'],handler_user_id=ids['member']))
        check(status==200 and data['summary']['handler_user_id']==ids['member'],'responsible and handler saved separately')
        check(admin.api('case_update',dict(case_id=ids['case']),dict(handler_user_id=ids['outside']))[0]==404,'case cannot be assigned outside company')
        check(admin.api('case_update',dict(case_id=ids['case']))[0]==405,'case changes require POST')
        check(member.api('case_linkable_threads')[1]['threads']==[], 'thread picker does not reveal private threads')
        check(admin.api('case_unlink',dict(mailbox_id=ids['mb']),dict(thread_id=ids['t']))[0]==200,'owner can withdraw shared thread')
        check(member.api('thread',dict(mailbox_id=ids['mb'],id=ids['t']))[0]==404,'unlink revokes shared access')
        os.unlink(ids['file'])
        print('Local integration checks completed. Disposable fixture data remains in the test database.')
    except Exception:
        log.flush(); log.seek(0); print(log.read()[-4000:],file=sys.stderr); raise
    finally:
        server.terminate(); server.wait(timeout=10); log.close()
