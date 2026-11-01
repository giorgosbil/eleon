const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class Element {
 constructor(){this.children=[];this.events={};this.style={};this.dataset={};this.attrs={};this.hidden=false;this.classList={toggle(){},add(){},remove(){}};}
 append(...els){els.forEach(el=>{if(el.parentElement)el.parentElement.children=el.parentElement.children.filter(x=>x!==el);this.children.push(el);el.parentElement=this;});}
 appendChild(el){this.append(el);return el;}
 insertBefore(el,before){const i=this.children.indexOf(before);this.children.splice(i<0?this.children.length:i,0,el);el.parentElement=this;}
 addEventListener(name,fn){this.events[name]=fn;}
 setAttribute(k,v){this.attrs[k]=v;}
 getBoundingClientRect(){return {left:40,top:70,width:760,height:620};}
 remove(){this.removed=true;this.parentElement.children=this.parentElement.children.filter(x=>x!==this);}
 focus(){}
}
const body=new Element(),document={body,activeElement:null,createElement:()=>new Element(),addEventListener(){},contains:()=>false,querySelectorAll:()=>body.children.filter(x=>!x.hidden)};
const window={innerWidth:1400,innerHeight:900,addEventListener(){},ecwFloatingWindow(){}};
const ctx={window,document,AbortController,URL,setTimeout,clearTimeout,console};vm.createContext(ctx);
let source=fs.readFileSync(__dirname+'/../web/assets/filePreview.js','utf8').replace('return { open: ecwOpenPreview };','return { open: ecwOpenPreview, build };').replace('  window.ecwOpenPreview = ecwOpenPreview;','  window.testPreview = createPreview(); window.testPreview.build();');
vm.runInContext(source,ctx);
const overlay=body.children[0];overlay.hidden=false;
// Pressing the native resize grip targets the outer window itself.
overlay.events.mousedown?.({target:overlay});assert.equal(overlay.removed,undefined,'resize grip must not close viewer');
const header=overlay.children[0].children[0];
const more=header.children.find(x=>x.className==='file-preview-more');
assert.ok(more, 'viewer actions are grouped under More');
const actions=more.children[1];
assert.equal(actions.children.length,5);
const expand=actions.children.find(x=>x.textContent==='Expand');expand.events.click();
assert.equal(overlay.style.width,'1376px');assert.equal(overlay.style.height,'876px');assert.equal(overlay.dataset.expanded,'true');assert.equal(overlay.removed,undefined);
expand.events.click();assert.equal(overlay.style.width,'760px');assert.equal(overlay.style.top,'70px');assert.equal(overlay.dataset.expanded,undefined);
header.children.find(x=>x.className==='file-preview-close').events.click();assert.equal(overlay.removed,true);
console.log('PASS resize grip keeps viewer open, Expand/Restore preserves bounds, and X closes explicitly');
