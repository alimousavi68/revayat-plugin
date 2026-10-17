(function ($) {
 'use strict';
 const config = window.rvAdminNotifications;
 if (!config) return;
 let lastCounts = JSON.stringify(config.counts), lastRevision = config.revision, listLoading = false, listDirty = false;
 async function refreshList() {
  const list = document.querySelector('[data-rv-center-list]');
  if (!list) return;
  if (listLoading) { listDirty = true; return; }
  listLoading = true; listDirty = false; let failed = false; const controller = new AbortController(); const timer = setTimeout(function () { controller.abort(); }, 15000); list.setAttribute('aria-busy', 'true');
  try {
   const response = await fetch(location.href, {credentials:'same-origin', cache:'no-store', signal:controller.signal});
   if (!response.ok) throw new Error('refresh');
   const html = new DOMParser().parseFromString(await response.text(), 'text/html');
   const fresh = html.querySelector('[data-rv-center-list]');
   if (!fresh) throw new Error('missing');
   if (list.contains(document.activeElement)) listDirty = true; else list.innerHTML = fresh.innerHTML;
  } catch (error) { failed = true; listDirty = true; /* Keep the current queue usable when the network is unavailable. */ }
  finally { clearTimeout(timer); listLoading = false; list.removeAttribute('aria-busy'); if (listDirty && !failed && !list.contains(document.activeElement)) refreshList(); }
 }
 function update(data) {
  if (!data || !data.counts) return;
  const signature = JSON.stringify(data.counts);
  if (signature !== lastCounts || data.revision !== lastRevision || listDirty) refreshList();
  lastRevision = data.revision;
  lastCounts = signature;
  document.querySelectorAll('[data-rv-count]').forEach(function (el) {
   const count = Number(data.counts[el.dataset.rvCount] || 0);
   el.hidden = count === 0;
   const label = el.querySelector('.pending-count');
   if (label) label.textContent = count.toLocaleString('fa-IR');
  });
  document.querySelectorAll('[data-rv-stat]').forEach(function(el){ el.textContent = Number(data.counts[el.dataset.rvStat] || 0).toLocaleString('fa-IR'); });
  document.querySelectorAll('[data-rv-notification-preview]').forEach(function(el){ if (!el.contains(document.activeElement) && data.preview) el.innerHTML = data.preview; });
  document.querySelectorAll('[data-rv-queue]').forEach(function (el) { if (!el.contains(document.activeElement) && el.innerHTML !== data.widget) el.innerHTML = data.widget; });
 }
 $(document).on('heartbeat-send.rvNotifications', function (e, data) { data.rv_admin_notifications = true; });
 $(document).on('heartbeat-tick.rvNotifications', function (e, data) { update(data.rv_admin_notifications); readVisible(); });
 // Reading details acknowledges only the displayed version for the current operator.
 let reading = false;
 function readVisible() {
  if (document.hidden || reading) return;
  const detail = document.querySelector('[data-rv-request-detail], [data-rv-legacy-user]');
  if (!detail || detail.dataset.rvRead === 'done') return;
  reading = true;
  $.ajax({url:config.endpoint,method:'POST',timeout:10000,data:{action:'revayat_admin_notification_read',nonce:config.nonce,id:detail.dataset.rvRequestDetail || 0,legacy_user:detail.dataset.rvLegacyUser || 0,type:detail.dataset.rvType || '',version:detail.dataset.rvVersion}})
   .done(function (r) { if (r.success) { detail.dataset.rvRead = 'done'; update(r.data); } })
   .always(function () { reading = false; });
 }
 $(readVisible);
 document.addEventListener('visibilitychange', readVisible);
 window.addEventListener('focus', function () { readVisible(); if (!document.hidden && window.wp && wp.heartbeat) wp.heartbeat.connectNow(); });
})(jQuery);
