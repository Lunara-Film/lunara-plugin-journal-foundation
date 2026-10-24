const test=require('node:test');
const assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const fs=require('node:fs');
const path=require('node:path');
const H=require('../assets/desk/desk-state.js');

const json=(data,status=200)=>({ok:status<400,status,headers:{get:()=> 'application/json'},json:async()=>data});
const pending={id:'p_1',title:'A filmmaker returns',url:'https://trade.example/story',source:'Deadline',summary:'A new feature begins filming.',image_url:'https://trade.example/still.jpg',published_at:'2026-10-24T14:00:00Z',status:'pending',covered:false};
const wire=(stories=[pending])=>({stories,sources:[{id:'deadline',label:'Deadline',count:stories.length}],counts:{total:stories.length,returned:stories.length,covered:stories.filter(s=>s.covered).length},refreshed_at:'2026-10-24T15:00:00Z',availability:{ready:true,can_refresh:true},refresh:{}});
const desk={drafts:[{id:42,title:'Saved article'}],draft_count:1,dispatch:{},pagination:{}};
const settings={publication:{can_publish:true},voice:{banned_phrases:[]}};
const workspace=()=>({revision:'rev1',validation:{valid:true},workspace:{id:42,title:'Saved article',content:'<p>Supported news.</p>',excerpt:'Summary',acf:{},publication:{gpt_publish_enabled:true}}});
const publishing=()=>({timezone:'America/Chicago',today:'2026-10-24',published_today:1,last_published:{id:5,title:'Previous article',date:'2026-10-24T14:00:00Z',url:'https://example.com/previous'},scheduled:[],reserve:[{id:42,title:'Saved article',revision:'rev1',status:'draft'}],target_per_day:1,reserve_target:7,ready_count:1,coverage_days:1,publication:{enabled:true,can_publish:true},cron:{disabled:true}});
function boot(handler){
  const dom=new JSDOM('<div id="journal-desk"></div><div id="announcements"></div><script id="desk-config" type="application/json"></script>',{url:'https://example.com/journal-desk/',runScripts:'outside-only'});
  const w=dom.window,d=w.document,requests=[];
  d.querySelector('#desk-config').textContent=JSON.stringify({apiBase:'https://example.com/wp-json/lunara/v1/',nonce:'nonce',deskUrl:'https://example.com/journal-desk/',siteUrl:'https://example.com/',settingsUrl:'https://example.com/wp-admin/settings',name:'Dalton',maxUploadBytes:10485760});
  w.scrollTo=()=>{};w.confirm=()=>true;
  w.fetch=async(url,opts)=>{
    requests.push({url,opts});const custom=handler&&await handler(url,opts);if(custom!==undefined)return custom;
    if(url.includes('/app/wire?'))return json(wire());
    if(url.endsWith('/app/publishing'))return json(publishing());
    if(url.endsWith('/drafts/42'))return json(workspace());
    if(url.endsWith('/settings'))return json(settings);
    if(url.endsWith('/dispatch/pitches'))return json({pitch_mode:true,pitches:[],counts:{}});
    return json(JSON.parse(JSON.stringify(desk)));
  };
  for(const file of ['desk-state.js','desk.js'])w.eval(fs.readFileSync(path.join(__dirname,'../assets/desk',file),'utf8'));
  const flush=async()=>{for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve));};
  const click=async(selector)=>{const el=d.querySelector(selector);assert.ok(el,selector);el.click();await flush();};
  const input=(selector,value)=>{const el=d.querySelector(selector);assert.ok(el,selector);el.value=value;el.dispatchEvent(new w.Event('input',{bubbles:true}));};
  return {dom,w,d,requests,flush,click,input};
}

test('Stories is the default desk with safe source images, attribution and existing coverage',async()=>{
  const covered={...pending,id:'p_2',title:'<img src=x onerror=alert(1)> Already filed',summary:'<script>bad()</script>',image_url:'javascript:alert(1)',url:'javascript:alert(1)',status:'written',covered:true,coverage_status:'publish',coverage_url:'https://example.com/journal/filed'};
  const app=boot(url=>url.includes('/app/wire?')?json(wire([pending,covered])):undefined);
  await app.flush();const {d}=app;
  assert.equal(d.querySelector('[data-view="wire"]').getAttribute('aria-selected'),'true');
  assert.equal(d.querySelector('h1').textContent,'Stories');
  assert.equal(d.querySelectorAll('.wire-story').length,2);
  assert.equal(d.querySelectorAll('.wire-image').length,1);
  assert.equal(d.querySelectorAll('.wire-story script,.wire-title img').length,0);
  assert.match(d.querySelectorAll('.wire-title')[1].textContent,/<img/);
  assert.match(d.querySelectorAll('.wire-story')[1].textContent,/Covered/);
  assert.equal(d.querySelectorAll('.wire-story')[1].querySelector('[data-wire-story]'),null);
  assert.equal(d.querySelectorAll('.wire-story')[1].querySelector('a').href,'https://example.com/journal/filed');
  assert.match(d.querySelector('.wire-meta').textContent,/Deadline/);
  assert.ok(d.querySelector('time[datetime="2026-10-24T14:00:00Z"]'));
  assert.equal(app.requests.some(r=>r.opts.method==='POST'),false,'loading the desk has no write side effects');
  app.dom.window.close();
});

test('source refresh imports stories without drafting; Write it sends the real pitch id and angle',async()=>{
  let story={...pending};
  const app=boot((url,opts)=>{
    if(url.includes('/app/wire?'))return json(wire([story]));
    if(url.endsWith('/wire/refresh'))return json({...wire([story]),refresh:{added:2,errors:[]}});
    if(url.endsWith('/dispatch/pitches/decide')){story={...story,status:'approved'};return json({success:true,approved:1,passed:0,writer:{queued:true}});}
  });
  await app.flush();await app.click('[data-action="refresh-wire"]');
  const refresh=app.requests.find(r=>r.url.endsWith('/wire/refresh'));
  assert.equal(refresh.opts.method,'POST');assert.equal(refresh.opts.credentials,'same-origin');assert.equal(refresh.opts.headers['X-WP-Nonce'],'nonce');
  assert.match(app.d.querySelector('.notice').textContent,/2 new stories.*No articles were written/);
  assert.equal(app.requests.filter(r=>r.opts.method==='POST').length,1);
  await app.click('[data-wire-angle="p_1"]');app.input('[data-wire-angle-text="p_1"]','  Lead with the return to features.  ');
  await app.click('[data-wire-story="p_1"][data-call="write"]');
  const decide=app.requests.find(r=>r.url.endsWith('/dispatch/pitches/decide'));
  assert.deepEqual(JSON.parse(decide.opts.body),{write:['p_1'],pass:[],angles:{p_1:'Lead with the return to features.'}});
  assert.match(app.d.querySelector('.wire-story').textContent,/With the writer/);
  assert.equal(app.d.querySelector('[data-wire-story="p_1"]'),null);
  assert.equal(app.requests.some(r=>/\/(publish|save|revise)$/.test(r.url)),false);
  app.dom.window.close();
});

test('failed decisions preserve the angle and never claim success or silently leave unsaved work',async()=>{
  const app=boot(url=>url.endsWith('/dispatch/pitches/decide')?json({message:'Writer unavailable'},503):undefined);
  await app.flush();await app.click('[data-wire-angle="p_1"]');app.input('[data-wire-angle-text="p_1"]','Keep this angle.');
  await app.click('[data-wire-story="p_1"][data-call="write"]');
  assert.match(app.d.querySelector('.notice.error').textContent,/Writer unavailable/);
  assert.equal(app.d.querySelector('[data-wire-angle-text="p_1"]').value,'Keep this angle.');
  app.w.confirm=()=>false;await app.click('[data-view="publishing"]');
  assert.equal(app.d.querySelector('h1').textContent,'Stories');
  app.w.confirm=()=>true;await app.click('[data-view="publishing"]');
  assert.equal(app.d.querySelector('h1').textContent,'Publishing');
  app.dom.window.close();
});

test('search and source filters ignore stale responses and use encoded query values',async()=>{
  const resolvers={};
  const app=boot(url=>{
    if(url.includes('/app/wire?search=first'))return new Promise(resolve=>{resolvers.first=resolve;});
    if(url.includes('/app/wire?search=second'))return new Promise(resolve=>{resolvers.second=resolve;});
  });
  await app.flush();
  const submit=()=>app.d.querySelector('#wire-filter-form').dispatchEvent(new app.w.Event('submit',{bubbles:true,cancelable:true}));
  app.input('#wire-search','first & film');submit();await app.flush();
  app.input('#wire-search','second');submit();await app.flush();
  resolvers.second(json(wire([{...pending,title:'Second result'}])));await app.flush();
  resolvers.first(json(wire([{...pending,title:'Old result'}])));await app.flush();
  assert.equal(app.d.querySelector('.wire-title').textContent,'Second result');
  assert.equal(app.d.querySelector('#wire-search').value,'second');
  assert.ok(app.requests.some(r=>r.url.includes('search=first%20%26%20film')));
  const source=app.d.querySelector('#wire-source');source.value='deadline';source.dispatchEvent(new app.w.Event('change',{bubbles:true}));await app.flush();
  assert.ok(app.requests.some(r=>r.url.includes('source=deadline')));
  // A response for the previous screen must not replace the next screen or its notice.
  await app.click('[data-view="publishing"]');
  assert.equal(app.d.querySelector('h1').textContent,'Publishing');
  assert.match(app.d.querySelector('.publishing-summary').textContent,/1 of 1/);
  assert.match(app.d.querySelector('.publishing-warning').textContent,/server’s scheduled trigger/);
  app.dom.window.close();
});

test('scheduling requires a clean validated draft and confirms delivery before showing success',async()=>{
  const app=boot((url,opts)=>{
    if(url.endsWith('/schedule'))return json({id:42,scheduled:true,publish_at:JSON.parse(opts.body).publish_at,revision:'rev2'});
  });
  await app.flush();await app.click('[data-view="queue"]');await app.click('[data-draft="42"]');
  app.input('#schedule-at','2099-11-10T10:30');assert.equal(app.d.querySelector('#schedule-button').disabled,false);
  app.input('#draft-title','Unsaved headline');assert.equal(app.d.querySelector('#schedule-button').disabled,true);assert.equal(app.d.querySelector('#reserve-button').disabled,true);
  await app.click('[data-draft="42"]');app.input('#schedule-at','2099-11-10T10:30');
  const evergreen=app.d.querySelector('#evergreen');evergreen.checked=true;evergreen.dispatchEvent(new app.w.Event('change',{bubbles:true}));
  await app.click('#schedule-button');
  const request=app.requests.find(r=>r.url.endsWith('/schedule'));const body=JSON.parse(request.opts.body);
  assert.equal(body.expected_revision,'rev1');assert.equal(body.publish_at,new Date('2099-11-10T10:30').toISOString());assert.equal(body.evergreen,true);
  assert.equal(app.d.querySelector('h1').textContent,'Publishing');assert.match(app.d.querySelector('.notice').textContent,/Scheduled for/);
  assert.equal(app.requests.some(r=>r.url.endsWith('/publish')),false,'scheduling does not call immediate publishing');
  app.dom.window.close();
});

test('reserve approval sends explicit editorial approval and failed scheduling is reported honestly',async()=>{
  let reserveRead=false;
  const app=boot((url)=>{
    if(url.endsWith('/reserve')){reserveRead=true;return json({id:42,reserved:true,revision:'rev2'});}
    if(url.endsWith('/drafts/42')){const result=workspace();if(reserveRead)result.revision='rev2';return json(result);}
    if(url.endsWith('/schedule'))return json({id:42,scheduled:false});
  });
  await app.flush();await app.click('[data-view="queue"]');await app.click('[data-draft="42"]');await app.click('#reserve-button');
  const reserve=app.requests.find(r=>r.url.endsWith('/reserve'));
  assert.deepEqual(JSON.parse(reserve.opts.body),{expected_revision:'rev1',approved:true,evergreen:false});
  assert.match(app.d.querySelector('.notice').textContent,/can be published automatically/);
  app.input('#schedule-at','2099-11-10T10:30');await app.click('#schedule-button');
  assert.match(app.d.querySelector('.notice.error').textContent,/Scheduling was not confirmed/);
  assert.ok(app.d.querySelector('#article-editor'));
  assert.equal(JSON.parse(app.requests.find(r=>r.url.endsWith('/schedule')).opts.body).expected_revision,'rev2');
  app.dom.window.close();
});

test('schedule dates reject the past and invalid calendar values; covered stories cannot be decided',()=>{
  assert.equal(H.scheduleIso('2020-01-01T09:00'), '');
  assert.equal(H.scheduleIso('2099-02-31T09:00'), '');
  assert.equal(H.scheduleIso('tomorrow'), '');
  assert.equal(H.storyDecisionBody({...pending,covered:true},'write','angle'),null);
  assert.equal(H.storyDecisionBody({...pending,status:'approved'},'write','angle'),null);
  assert.deepEqual(H.storyDecisionBody(pending,'pass','ignored'),{write:[],pass:['p_1'],angles:{}});
});

test('failed delivery and held approvals remain visible with safe review actions',async()=>{
  const app=boot(url=>{
    if(url.endsWith('/app/publishing'))return json({...publishing(),last_delivery:{id:42,at:'2026-10-24T15:00:00Z',published:false,message:'The saved article changed after approval.'},attention:[{id:42,title:'Expired approval',status:'expired',message:'Approve this article again.',expires_at:'2026-10-24T14:00:00Z'},{id:0,title:'<script>Unsafe title</script>',status:'locked',message:'<img src=x onerror=alert(1)> Still locked'}]});
  });
  await app.flush();await app.click('[data-view="publishing"]');
  assert.match(app.d.querySelector('.notice.error').textContent,/last delivery did not publish.*changed after approval/);
  const held=Array.from(app.d.querySelectorAll('.publishing-section')).find(section=>section.textContent.includes('Needs your attention'));
  assert.ok(held);assert.match(held.textContent,/Approve this article again/);
  assert.equal(held.querySelectorAll('[data-draft]').length,1,'invalid article ids never create review actions');
  assert.equal(held.querySelector('script,img'),null,'held article data is escaped');
  await app.click('.notice.error [data-draft="42"]');assert.ok(app.d.querySelector('#article-editor'));
  app.dom.window.close();
});

test('source refresh holds filters until refreshed imports have been read back',async()=>{
  let finishRefresh,imported=false;
  const app=boot(url=>{
    if(url.endsWith('/wire/refresh'))return new Promise(resolve=>{finishRefresh=()=>{imported=true;resolve(json({refresh:{added:1}}));};});
    if(url.includes('/app/wire?'))return json(wire([{...pending,title:imported?'Newly imported story':'Earlier story'}]));
  });
  await app.flush();await app.click('[data-action="refresh-wire"]');
  assert.equal(app.d.querySelector('#wire-source').disabled,true);
  assert.equal(app.d.querySelector('#wire-search').disabled,true);
  const reads=app.requests.filter(r=>r.url.includes('/app/wire?')).length;
  app.d.querySelector('#wire-filter-form').dispatchEvent(new app.w.Event('submit',{bubbles:true,cancelable:true}));await app.flush();
  assert.equal(app.requests.filter(r=>r.url.includes('/app/wire?')).length,reads);
  finishRefresh();await app.flush();
  assert.equal(app.d.querySelector('.wire-title').textContent,'Newly imported story');
  assert.equal(app.d.querySelector('#wire-source').disabled,false);
  assert.match(app.d.querySelector('.notice').textContent,/1 new story added/);
  app.dom.window.close();
});

test('publishing or rejecting clears an unused schedule choice before returning to the queue',async()=>{
  for(const action of ['publish','reject']){
    const app=boot(url=>{
      if(url.endsWith('/publish'))return json({id:42,published:true,post_status:'publish',title:'Saved article',permalink:'https://example.com/published'});
      if(url.endsWith('/reject'))return json({id:42,rejected:true});
    });
    let confirmations=0;app.w.confirm=()=>{confirmations++;return true;};
    await app.flush();await app.click('[data-view="queue"]');await app.click('[data-draft="42"]');app.input('#schedule-at','2099-11-10T10:30');
    await app.click('[data-action="'+action+'"]');
    assert.equal(app.d.querySelector('h1').textContent,'Draft queue');
    const before=confirmations;await app.click('[data-view="wire"]');
    assert.equal(confirmations,before,'completed article leaves no ghost unsaved warning');
    app.dom.window.close();
  }
});
