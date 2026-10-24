(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.LunaraDeskState = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const fields = ['title', 'content', 'excerpt', 'seo', 'deck', 'imageId', 'imageCredit', 'imageAlt', 'imageSource'];
  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function safeUrl(value) {
    try { const url = new URL(String(value)); return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : ''; }
    catch (_) { return ''; }
  }
  function isDirty(a, b) { return fields.some(key => String(a && a[key] || '') !== String(b && b[key] || '')); }
  function canPublish(state) { return Boolean(!state.dirty && !state.awaitingReadback && state.valid && state.enabled && state.permitted && !state.busy); }
  function canApplyCandidate(candidate, draftId, draft) { return Boolean(candidate && candidate.draftId === draftId && !isDirty(candidate.input, draft)); }
  function fromWorkspace(workspace) {
    const acf = workspace.acf || {};
    return { title:workspace.title || '', content:workspace.content || '', excerpt:workspace.excerpt || '', seo:acf.journal_seo_description || '', deck:acf.journal_deck || '', imageId:Number(workspace.featured_media)||0, imageUrl:safeUrl(workspace.image && workspace.image.url), imageSource:acf.journal_image_source_url || '', imageCredit:acf.journal_image_credit || '', imageAlt:acf.journal_image_alt || (workspace.image && workspace.image.alt_text) || '' };
  }
  function saveBody(draft, revision) {
    return {expected_revision:revision, title:draft.title, content:draft.content, excerpt:draft.excerpt, ...(draft.imageId?{featured_media:draft.imageId}:{}), acf:{journal_seo_description:draft.seo, journal_deck:draft.deck, journal_image_credit:draft.imageCredit||'', journal_image_alt:draft.imageAlt||'', journal_image_source_url:draft.imageSource||''}};
  }
  function chooseImage(draft, image) {
    if (draft.imageId === image.id) return {...draft};
    return {...draft, imageId:image.id, imageUrl:safeUrl(image.url), imageSource:safeUrl(image.url), imageCredit:'', imageAlt:image.alt||''};
  }
  function visibleDrafts(drafts) { return (drafts || []).filter(draft => draft.journal_status !== 'rejected'); }
  function voiceFlags(content, headline, banned) {
    const text = String(content || '').replace(/<[^>]*>/g, ' ');
    const flags = [];
    const phrases = [...new Set([...(banned || []), 'that matters because', 'this matters because', 'that uncertainty is the point', 'for context', 'notably'])];
    const found = phrases.filter(phrase => phrase && text.toLowerCase().includes(String(phrase).toLowerCase())).slice(0,5);
    if (found.length) flags.push('Check familiar phrasing: ' + found.map(p => '“' + p + '”').join(', ') + '.');
    if (/\b(new power play|fighting its own fan base|changes everything|game.chang|unprecedented)\b/i.test(headline || '')) flags.push('Check that the headline’s claim is supported by the reporting.');
    if (!/<a\s[^>]*href=/i.test(content || '')) flags.push('Check source attribution in the article. The source list below is kept separately.');
    return flags;
  }
  // Pitches (Lunara Dispatch 3.3.0 pitch gate): the Desk's decision state.
  function pendingPitches(pitches) { return (pitches || []).filter(p => p && p.status === 'pending'); }
  function togglePitchCall(calls, id, call) {
    const next = {...calls};
    if (next[id] === call) delete next[id]; else if (call === 'write' || call === 'pass') next[id] = call;
    return next;
  }
  function passRemaining(pending, calls) {
    const next = {...calls};
    pending.forEach(p => { if (!next[p.id]) next[p.id] = 'pass'; });
    return next;
  }
  function pitchCallCount(calls) { return Object.keys(calls || {}).length; }
  function pitchDecisionBody(pending, calls, angles) {
    const open = new Set(pending.map(p => p.id));
    const write = [], pass = [], notes = {};
    Object.keys(calls || {}).forEach(id => {
      if (!open.has(id)) return;
      if (calls[id] === 'write') { write.push(id); const note = String(angles && angles[id] || '').trim(); if (note) notes[id] = note.slice(0, 600); }
      else if (calls[id] === 'pass') pass.push(id);
    });
    return {write, pass, angles: notes};
  }
  function scheduleIso(value, now) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(String(value || ''));
    if (!match) return '';
    const date = new Date(value);
    if (!Number.isFinite(date.getTime()) || date.getFullYear() !== Number(match[1]) || date.getMonth() + 1 !== Number(match[2]) || date.getDate() !== Number(match[3]) || date.getHours() !== Number(match[4]) || date.getMinutes() !== Number(match[5]) || date.getTime() <= (now === undefined ? Date.now() : now)) return '';
    return date.toISOString();
  }
  function storyDecisionBody(story, call, angle) {
    if (!story || !story.id || story.covered || story.status !== 'pending' || !['write', 'pass'].includes(call)) return null;
    const id = String(story.id), note = String(angle || '').trim().slice(0,600);
    return {write:call === 'write' ? [id] : [], pass:call === 'pass' ? [id] : [], angles:call === 'write' && note ? {[id]:note} : {}};
  }
  return {scheduleIso, storyDecisionBody, pendingPitches, togglePitchCall, passRemaining, pitchCallCount, pitchDecisionBody, chooseImage, escapeHtml, safeUrl, isDirty, canPublish, canApplyCandidate, fromWorkspace, saveBody, visibleDrafts, voiceFlags};
});
