// Browser layout regression using synthetic email data; no mailbox requests.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('./support/playwright.cjs');
const web=path.resolve(__dirname,'../web');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try {
 const page=await browser.newPage({viewport:{width:1400,height:900}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 const bubble={from:{name:'Alex Brown',email:'alex@example.test'},to:[],cc:[],body_state:'fetched',body_html:'<p style="color:#000000;background-color:#ffffff">Message content</p>',direction:'incoming',attachments:[]};
 const data={summary:{name:'Layout test',number:'CASE-TEST',status:'open',created_at:'2026-09-19 12:00:00',last_activity:'2026-09-19 12:04:00',thread_count:2,timezone:'UTC'},columns:[1,2].map(id=>({thread_id:id,mailbox_id:1,label:'Thread '+id,message_count:1,participants:[],is_hidden:false})),events:[1,2].map(id=>({type:'email',message_id:id,thread_id:id,occurred_at:'2026-09-19 12:0'+id+':00',bubble:{...bubble,direction:id===2?'outgoing':'incoming'}}))};
 await page.route('https://ecw.test/**',async route=>{
  const url=new URL(route.request().url()),rel=url.pathname.replace('/other_things/web/','');
  if(rel==='api/index.php') return route.fulfill({json:url.searchParams.get('action')==='case_timeline'?data:{users:[]}});
  if(rel==='case.php'){
   let html=fs.readFileSync(path.join(web,rel),'utf8').replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g,'').replace('data-case=""','data-case="1"');
   html=html.replace('</head>','<link rel="stylesheet" href="assets/appearance.css"></head>').replace('<body class="case-body"','<body class="case-body"');
   html=html.replace('<script src="assets/floating-window', '<script id="appearanceConfig" type="application/json">{"theme":"dark","background":"dots","sent":"#243b55","received":"#25303e"}</script><script src="assets/appearance.js"></script><script src="assets/floating-window');
   return route.fulfill({contentType:'text/html',body:html});
  }
  const file=path.join(web,rel);if(!file.startsWith(path.join(web,'assets')+path.sep)||!fs.existsSync(file))return route.abort();
  return route.fulfill({contentType:file.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(file)});
 });
 await page.goto('https://ecw.test/other_things/web/case.php');await page.locator('.bubble').last().waitFor();
 assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
 assert.equal(await page.locator('.bubble.outgoing').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(36, 59, 85)');
 assert.equal(await page.locator('.bubble.incoming .message-body p').evaluate(el=>getComputedStyle(el).color),'rgb(245, 247, 250)');
 const first=page.locator('.tl-head[data-thread-id="1"]'),before=await first.boundingBox();
 const divider=await page.locator('.tl-divider').boundingBox();await page.mouse.move(divider.x+4,divider.y+30);await page.mouse.down();await page.mouse.move(divider.x+104,divider.y+30);await page.mouse.up();
 const after=await first.boundingBox();assert.ok(after.width>before.width+90,'drag changes adjacent column width');
 const a=await page.locator('#msg-1').boundingBox(),b=await page.locator('#msg-2').boundingBox();assert.ok(b.y>a.y,'chronological shared rows remain intact');
 assert.deepEqual(errors,[]);console.log('PASS browser dark palette, background, draggable case divider and chronological rows');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
