const test=require('node:test');
const assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const fs=require('node:fs');
const path=require('node:path');
const H=require('../assets/desk/desk-state.js');

function boot(fetchImpl){
  const dom=new JSDOM('<div id="journal-desk"></div><div id="announcements"></div><script id="desk-config" type="application/json"></script>',{url:'https://example.com/journal-desk/',runScripts:'outside-only'});
  const w=dom.window;const d=w.document;
  d.querySelector('#desk-config').textContent=JSON.stringify({apiBase:'https://example.com/wp-json/lunara/v1/',nonce:'nonce',deskUrl:'https://example.com/journal-desk/',siteUrl:'https://example.com/',name:'Dalton',maxUploadBytes:10485760});
  w.scrollTo=()=>{};w.confirm=()=>true;w.fetch=(url,opts)=>url.endsWith('/wire')?Promise.resolve(json({stories:[],sources:[],availability:{ready:true}})):fetchImpl(url,opts);
  for(const file of ['desk-state.js','desk.js'])w.eval(fs.readFileSync(path.join(__dirname,'../assets/desk',file),'utf8'));
  const flush=async()=>{for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve));};
  const click=async(selector)=>{const el=d.querySelector(selector);assert.ok(el,selector);el.click();await flush();};
  return {dom,w,d,flush,click};
}
const json=(data,status=200)=>({ok:status<400,status,headers:{get:()=> 'application/json'},json:async()=>data});
const desk={drafts:[],draft_count:0,dispatch:{},pagination:{}};

test('pitch decision helpers only send open pitches and trim angles', ()=>{
  const pending=[{id:'p_1',status:'pending'},{id:'p_2',status:'pending'},{id:'p_3',status:'pending'}];
  let calls=H.togglePitchCall({},'p_1','write');
  calls=H.togglePitchCall(calls,'p_2','pass');
  calls=H.togglePitchCall(calls,'p_2','pass');
  assert.deepEqual(calls,{p_1:'write'},'second click on the same call clears it');
  calls=H.passRemaining(pending,{...calls,p_9:'write'});
  const body=H.pitchDecisionBody(pending,calls,{p_1:'  Lead with the director.  ',p_3:'ignored for a pass'});
  assert.deepEqual(body,{write:['p_1'],pass:['p_2','p_3'],angles:{p_1:'Lead with the director.'}});
  assert.equal(H.pitchCallCount({a:'write',b:'pass'}),2);
});

test('Desk Pitches tab decides pitches over Dispatch routes with the session nonce', async()=>{
  const requests=[];
  let pitches=[
    {id:'p_1',status:'pending',title:'Villeneuve sets an original',url:'https://example.com/a',source:'Deadline',summary:'An original sci-fi feature.',image_url:'https://example.com/a.jpg',published_at:'2026-09-25T10:00:00+00:00'},
    {id:'p_2',status:'pending',title:'A24 buys a Cannes breakout',url:'https://example.com/b',source:'Variety',summary:'',image_url:'javascript:alert(1)',published_at:''},
    {id:'p_3',status:'written',title:'Criterion slate',url:'https://example.com/c',source:'IndieWire',post_ids:[77],angle:'Kiarostami first.',note:''},
  ];
  let mode=true;
  const app=boot(async(url,opts)=>{
    requests.push({url,opts});
    if(url.endsWith('/dispatch/pitches/decide')){const body=JSON.parse(opts.body);pitches=pitches.map(p=>body.write.includes(p.id)?{...p,status:'approved'}:body.pass.includes(p.id)?{...p,status:'passed'}:p);return json({success:true,approved:body.write.length,passed:body.pass.length,writer:{queued:true}});}
    if(url.endsWith('/dispatch/pitches/mode')){mode=JSON.parse(opts.body).enabled;return json({pitch_mode:mode});}
    if(url.endsWith('/dispatch/pitches'))return json({pitch_mode:mode,pitches,counts:{}});
    if(url.endsWith('/settings'))return json({publication:{},voice:{banned_phrases:[]}});
    return json(desk);
  });
  const {d,click,flush}=app;await flush();await click('[data-view="queue"]');
  assert.match(d.querySelector('.status-strip').textContent,/2 pitches waiting/,'queue strip links to waiting pitches');
  await click('[data-view="pitches"]');
  assert.equal(d.querySelectorAll('.pitch-row').length,2,'only pending pitches are listed');
  assert.equal(d.querySelectorAll('.pitch-image').length,1,'unsafe image URLs are not rendered');
  assert.equal(d.querySelector('[data-action="send-pitches"]').disabled,true,'nothing to send before a call');
  await click('[data-pitch-angle="p_1"]');
  const angle=d.querySelector('[data-pitch-angle-text="p_1"]');angle.value='Villeneuve going original is the story.';angle.dispatchEvent(new app.w.Event('input',{bubbles:true}));
  await click('[data-pitch-call="p_2"][data-call="pass"]');
  assert.equal(d.querySelector('[data-pitch-call="p_1"][data-call="write"]').getAttribute('aria-pressed'),'true','adding an angle marks the pitch Write it');
  await click('[data-action="send-pitches"]');
  const decide=requests.find(r=>r.url.endsWith('/dispatch/pitches/decide'));
  assert.equal(decide.opts.method,'POST');assert.equal(decide.opts.headers['X-WP-Nonce'],'nonce');assert.equal(decide.opts.credentials,'same-origin');
  assert.deepEqual(JSON.parse(decide.opts.body),{write:['p_1'],pass:['p_2'],angles:{p_1:'Villeneuve going original is the story.'}});
  assert.match(d.querySelector('.notice').textContent,/1 going to the writer.*1 passed/);
  assert.equal(d.querySelectorAll('.pitch-row').length,0,'decided pitches leave the list');
  await click('[data-action="pitch-history"]');
  assert.match(d.querySelector('.pitch-history').textContent,/With the writer/);
  assert.ok(d.querySelector('.pitch-history [data-draft="77"]'),'a drafted pitch opens its draft in the Desk');
  await click('[data-action="pitch-mode"]');
  assert.deepEqual(JSON.parse(requests.find(r=>r.url.endsWith('/dispatch/pitches/mode')).opts.body),{enabled:false});
  assert.match(d.querySelector('.status-strip').textContent,/Pitch mode is off/);
  assert.equal(requests.some(r=>/\/publish|\/save/.test(r.url)),false,'deciding pitches never saves or publishes');
  app.dom.window.close();
});

test('an older Dispatch without the pitch gate gets an honest update message', async()=>{
  const app=boot(async(url)=>{
    if(url.endsWith('/dispatch/pitches'))return json({code:'rest_no_route',message:'No route was found matching the URL and request method.'},404);
    if(url.endsWith('/settings'))return json({publication:{},voice:{banned_phrases:[]}});
    return json(desk);
  });
  const {d,click,flush}=app;await flush();await click('[data-view="queue"]');
  assert.doesNotMatch(d.querySelector('.status-strip').textContent,/pitch/i);
  assert.equal(d.querySelector('.notice'),null,'the quiet startup check raises no alert');
  await click('[data-view="pitches"]');
  assert.match(d.querySelector('.empty').textContent,/Lunara Film Dispatch 3\.3\.0 or later/);
  app.dom.window.close();
});
