const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../cad_viewer/third_party/o3dv.min.js','utf8');
const start=source.indexOf('onModelFinished:(e,t)=>{if(this._ecwDisposed)');assert.ok(start>0);
const code=source.slice(start,source.indexOf(',onTextureLoaded:',start)).replace('onModelFinished:','');
function run(disposed,attached){
 let removed=0,rendered=0;const parent={removeChild(node){assert.equal(node.parentNode,parent);removed++;}};
 const n={parentNode:attached?parent:null};const self={_ecwDisposed:disposed,parentElement:parent,canvas:{style:{}},parameters:{},viewer:{SetMainObject(){rendered++;},GetBoundingSphere(){return {};},AdjustClippingPlanesToSphere(){},SetUpVector(){},FitSphereToWindow(){}}};
 const ctx={n,U:{Y:1},self};vm.createContext(ctx);vm.runInContext('(function(){const done='+code+';done({model:{}},{});}).call(self)',ctx);return {removed,rendered};
}
assert.deepEqual(run(false,true),{removed:1,rendered:1});
assert.deepEqual(run(false,false),{removed:0,rendered:1});
assert.deepEqual(run(true,false),{removed:0,rendered:0});
console.log('PASS normal completion, detached loader, and completion after viewer disposal');
