const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../cad_viewer/ot-cad-viewer.js', 'utf8');
const method = source.slice(source.indexOf('    _calcManualBBox(model) {'), source.indexOf('    async loadOV()'));
const viewer = vm.runInNewContext('({' + method + '})');
// Instances include their placement in the assembly; raw mesh vertices do not.
const box = viewer._calcManualBBox({
  meshes: [{vertices:[{x:0,y:0,z:0},{x:1,y:1,z:1}]}],
  EnumerateVertices(callback) {
    [{x:-10,y:20,z:5},{x:40,y:100,z:30}].forEach(callback);
  }
});
assert.equal(box.size.x, 50);
assert.equal(box.size.y, 80);
assert.equal(box.size.z, 25);
assert.equal(viewer._calcManualBBox({meshes:[]}), null);
const flat = viewer._calcManualBBox({meshes:[{vertices:[0,0,0,5,8,0]}]});
assert.equal(flat.size.z, 0);
console.log('PASS assembled model bounds, empty model, and flat model dimensions');
