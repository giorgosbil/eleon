const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor() { this.value=''; this.hidden=false; this.dataset={}; this.children=[]; this.classList={toggle(){}}; }
  setAttribute(k,v){ this[k]=v; } removeAttribute(k){ delete this[k]; }
  appendChild(e){this.children.push(e);} append(...e){this.children.push(...e);} addEventListener(){} focus(){}
  querySelector(){return null;} querySelectorAll(){return [];} contains(){return false;}
}
const ids = new Map(); const el = id => { if(!ids.has(id)) ids.set(id,new Element()); return ids.get(id); };
const document={body:{dataset:{}}, getElementById:el, querySelector:el, createElement:()=>new Element()};
let calls=[]; let failSave=false; let release;
const window={location:{href:'https://example.test/other_things/web/app.php',origin:'https://example.test'},addEventListener(){}}; window.parent=window;
const ctx={document,window,URL,URLSearchParams,console,setTimeout:()=>1,clearTimeout(){},fetch:async(url,options)=>{
  const parsed=new URL(url); const action=parsed.searchParams.get('action');
  calls.push({action,parsed,body:JSON.parse(options.body||'{}')});
  if(action==='draft_save') return {ok:!failSave,status:500,text:async()=>JSON.stringify(failSave?{error:'save failed'}:{draft_id:9})};
  if(action==='send') {await new Promise(resolve=>release=resolve);return {ok:true,text:async()=>'{"sent":true}'};}
  throw Error(action);
}};
require('./support/vm_page.cjs').page(ctx);
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(__dirname+'/../web/assets/app.js','utf8').replace('  wire();\n  boot();',
 'globalThis.composer={state,send,saveDraft,setEditor(e){quill=e;},setMailbox(id){composerMailboxId=id;},setQuote(q){composeQuote=q;}};'),ctx);
const c=ctx.composer;c.setEditor({root:{innerHTML:'<p>New answer</p>'}});c.setMailbox(1);c.state.mailboxId=2;
el('fieldTo').value='recipient@example.test';el('fieldSubject').value='Subject';el('composerOverlay').hidden=false;
(async()=>{
 const first=c.send(); await c.send();
 while(!release) await new Promise(resolve=>setImmediate(resolve));
 assert.equal(calls.filter(c=>c.action==='send').length,1);
 assert.equal(el('sendBtn').disabled,true);assert.equal(el('sendProgress').hidden,false);
 assert.equal(calls[0].parsed.searchParams.get('mailbox_id'),'1');
 assert.equal(calls[1].body.draft_id,9);
 release();await first;
 assert.equal(el('composerOverlay').hidden,true);assert.equal(el('sendProgress').hidden,true);
 console.log('PASS Send saves latest draft, pins mailbox, blocks duplicate clicks and shows progress');
 calls=[];failSave=true;el('composerOverlay').hidden=false;
 await c.send();assert.equal(calls.some(c=>c.action==='send'),false);
 assert.equal(el('composerOverlay').hidden,false);assert.equal(el('sendBtn').disabled,false);
 console.log('PASS failed save never sends an older draft and keeps composer open');
 calls=[];failSave=false;c.setQuote('<blockquote>Previous email</blockquote>');
 await c.saveDraft();assert.match(calls[0].body.body_html,/New answer.*Previous email/);
 assert.equal(calls[0].body.to[0].email,'recipient@example.test');
 console.log('PASS outgoing mail preserves quoted history outside editable answer');
})().catch(e=>{console.error(e);process.exitCode=1;});
