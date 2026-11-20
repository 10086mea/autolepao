'use strict';

const $ = (id) => document.getElementById(id);
const state = { accounts: [], routes: [], tasks: [], csrf: '', points: [], policy: null, qr: null };
const beijing = (seconds) => new Date(seconds * 1000).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai', hour12: false });
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
const labels = { scheduled: '等待开始', preparing: '准备中', running: '进行中', submitting: '提交中', completed: '已结束', failed: '失败', uncertain: '需要核对', cancelled: '已取消' };
let toastTimer;

function toast(message) {
  $('toast').textContent = message;
  $('toast').hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { $('toast').hidden = true; }, 5500);
}

async function api(path, body, method = body === undefined ? 'GET' : 'POST') {
  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || '请求未完成，请稍后重试');
  return result;
}

async function busy(button, action) {
  button.disabled = true;
  try { return await action(); }
  catch (error) { toast(error.message); }
  finally { button.disabled = false; }
}

// Leaflet's OSM basemap uses WGS84. Lepao checkpoints and uploads use GCJ02.
function gcjOffset(lat, lng) {
  if (lng < 72.004 || lng > 137.8347 || lat < .8293 || lat > 55.8271) return [0, 0];
  const x = lng - 105, y = lat - 35;
  let dy = -100 + 2 * x + 3 * y + .2 * y * y + .1 * x * y + .2 * Math.sqrt(Math.abs(x));
  dy += (20 * Math.sin(6 * x * Math.PI) + 20 * Math.sin(2 * x * Math.PI)) * 2 / 3;
  dy += (20 * Math.sin(y * Math.PI) + 40 * Math.sin(y / 3 * Math.PI)) * 2 / 3;
  dy += (160 * Math.sin(y / 12 * Math.PI) + 320 * Math.sin(y * Math.PI / 30)) * 2 / 3;
  let dx = 300 + x + 2 * y + .1 * x * x + .1 * x * y + .1 * Math.sqrt(Math.abs(x));
  dx += (20 * Math.sin(6 * x * Math.PI) + 20 * Math.sin(2 * x * Math.PI)) * 2 / 3;
  dx += (20 * Math.sin(x * Math.PI) + 40 * Math.sin(x / 3 * Math.PI)) * 2 / 3;
  dx += (150 * Math.sin(x / 12 * Math.PI) + 300 * Math.sin(x / 30 * Math.PI)) * 2 / 3;
  const radians = lat / 180 * Math.PI;
  const magic = 1 - .006693421622965943 * Math.sin(radians) ** 2;
  return [dy * 180 / ((6378245 * (1 - .006693421622965943)) / (magic * Math.sqrt(magic)) * Math.PI),
    dx * 180 / (6378245 / Math.sqrt(magic) * Math.cos(radians) * Math.PI)];
}

function toGcj([lat, lng]) {
  const [dy, dx] = gcjOffset(lat, lng);
  return { a: lat + dy, o: lng + dx };
}

function toWgs(point) {
  let lat = Number(point.a), lng = Number(point.o);
  for (let i = 0; i < 4; i++) {
    const projected = toGcj([lat, lng]);
    lat += point.a - projected.a;
    lng += point.o - projected.o;
  }
  return [lat, lng];
}

const map = L.map('map', { doubleClickZoom: false }).setView([34.5, 105], 5);
const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19,
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
}).addTo(map);
tiles.on('tileerror', () => { $('map-error').hidden = false; });
tiles.on('load', () => { $('map-error').hidden = true; });
const drawing = L.layerGroup().addTo(map);
const checkpoints = L.layerGroup().addTo(map);
const generated = L.layerGroup().addTo(map);
const pointIcon = L.divIcon({ className: 'draw-point', iconSize: [13, 13], iconAnchor: [6, 6] });

function redraw() {
  drawing.clearLayers();
  generated.clearLayers();
  $('preview-summary').hidden = true;
  const line = [...state.points];
  if (document.querySelector('input[name=closed]:checked').value === 'true' && line.length > 2) line.push(line[0]);
  L.polyline(line, { color: '#246b66', weight: 4 }).addTo(drawing);
  state.points.forEach((point, index) => {
    const marker = L.marker(point, { draggable: true, icon: pointIcon }).addTo(drawing);
    marker.on('dragend', () => {
      state.points[index] = [marker.getLatLng().lat, marker.getLatLng().lng];
      redraw();
    });
  });
  let distance = 0;
  for (let i = 1; i < line.length; i++) distance += map.distance(line[i - 1], line[i]);
  $('draw-summary').textContent = state.points.length ? `${state.points.length} 个画线点 · 单程 ${(distance / 1000).toFixed(2)} km` : '点击地图开始画线';
}

map.on('click', (event) => {
  if (state.points.length >= 512) return toast('最多支持512个画线点');
  state.points.push([event.latlng.lat, event.latlng.lng]);
  redraw();
});
$('undo-button').onclick = () => { state.points.pop(); redraw(); };
$('clear-button').onclick = () => { state.points = []; redraw(); };
document.querySelectorAll('input[name=closed]').forEach((radio) => { radio.onchange = redraw; });
$('locate-button').onclick = () => {
  if (!navigator.geolocation) return toast('此浏览器不支持定位');
  navigator.geolocation.getCurrentPosition((position) => {
    map.setView([position.coords.latitude, position.coords.longitude], 17);
  }, () => toast('定位未完成，可以直接缩放地图选择位置'), { timeout: 10000 });
};

function renderAccounts() {
  const selected = $('account-select').value;
  $('accounts').innerHTML = state.accounts.length ? state.accounts.map((account) => {
    const semester = account.overview?.semester;
    const hosting = state.hosting?.find((item) => item.account_id === account.id);
    return `<article class="account ${account.id === selected ? 'selected' : ''}">
      <div class="account-top"><strong>${escapeHtml(account.nickname || account.uid)}</strong><span class="badge ${account.status === 'valid' ? '' : 'bad'}">${account.status === 'valid' ? '登录有效' : '请检测状态'}</span></div>
      <p>${account.provider === 'qq' ? 'QQ' : '微信'} · 乐跑 ID ${escapeHtml(account.uid)}</p>
      ${semester ? `<p>本学期已跑 ${semester.count} 次${semester.target ? ` / 目标 ${semester.target} 次` : ''}</p>` : ''}
      ${account.message ? `<p class="error">${escapeHtml(account.message)}</p>` : ''}
      ${hosting ? `<p>每日预约 · ${hosting.state === 'active' ? '已开启' : hosting.state === 'completed' ? '已完成' : '已暂停'} · ${hosting.completed}/${hosting.target} 次</p><p>${escapeHtml(hosting.message)}</p>` : ''}
      <div class="account-actions"><button data-check="${account.id}">刷新资料</button>${hosting?.state === 'active' ? `<button data-stop-hosting="${account.id}">暂停每日预约</button>` : ''}<button data-delete="${account.id}" class="danger">删除</button></div></article>`;
  }).join('') : '<p class="muted">扫码绑定你的乐跑账号，读取所属学校和跑区。</p>';
  $('account-select').innerHTML = '<option value="">请选择账号</option>' + state.accounts.map((a) => `<option value="${a.id}">${escapeHtml(a.nickname || a.uid)} · ${escapeHtml(a.uid)}</option>`).join('');
  if (state.accounts.some((a) => a.id === selected)) $('account-select').value = selected;
  $('accounts').querySelectorAll('[data-check]').forEach((button) => {
    button.onclick = () => busy(button, async () => {
      const result = await api(`/api/accounts/${button.dataset.check}/check`, {});
      toast(result.available ? '账号资料已更新' : result.message);
      await load();
      if ($('account-select').value === button.dataset.check) await selectAccount();
    });
  });
  $('accounts').querySelectorAll('[data-delete]').forEach((button) => {
    button.onclick = () => {
      if (!confirm('删除这个账号及它的本地任务记录？')) return;
      busy(button, async () => { await api(`/api/accounts/${button.dataset.delete}`, undefined, 'DELETE'); await load(); });
    };
  });
  $('accounts').querySelectorAll('[data-stop-hosting]').forEach((button) => {
    button.onclick = () => busy(button, async () => { await api(`/api/hosting/${button.dataset.stopHosting}/stop`, {}); await load(); });
  });
}

function renderTasks() {
  $('task-count').textContent = state.tasks.filter((t) => ['scheduled', 'preparing', 'running', 'submitting'].includes(t.state)).length || '';
  $('task-list').innerHTML = state.tasks.length ? state.tasks.map((task) => `<article class="task-card">
    <div class="section-top"><h2>${escapeHtml(task.nickname || task.uid)} <span class="muted">· ${escapeHtml(task.uid)}</span></h2><span class="badge ${['failed', 'uncertain'].includes(task.state) ? 'bad' : ''}">${labels[task.state] || escapeHtml(task.state)}</span></div>
    <p>${escapeHtml(task.message)}</p><div class="task-meta"><span>${task.config.distance_km} km</span><span>配速 ${task.config.pace_s_per_km} 秒/公里</span><span>${task.state === 'running' ? '预计提交' : '计划开始'} ${beijing(task.due_at)}</span></div>
    ${task.state === 'scheduled' ? `<button class="small" data-cancel="${task.id}">取消预约</button>` : ''}
    <details><summary>详细信息</summary><p class="task-id">任务 ${task.id}</p><p>学期 ${escapeHtml(task.config.term_id)} · 跑区 ${escapeHtml(task.config.game_id)} · 打卡点 ${escapeHtml(task.config.assigned_checkpoints?.map((p) => p.name || p.id).join('、') || '无')}</p>
    ${task.result ? `<p>乐跑状态 ${escapeHtml(task.result.status)} · 记录 ${escapeHtml(task.result.record_id || '未返回')} · 成绩 ${task.result.record_status === null ? '等待判定' : escapeHtml(task.result.record_status)}</p>` : ''}
    <ul class="events">${task.events.map((event) => `<li><time>${beijing(event.at)}</time> ${escapeHtml(event.message)}</li>`).join('')}</ul></details></article>`).join('') : '<div class="task-card muted">还没有任务。画好路线后即可预约。</div>';
  $('task-list').querySelectorAll('[data-cancel]').forEach((button) => {
    button.onclick = () => busy(button, async () => { await api(`/api/tasks/${button.dataset.cancel}/cancel`, {}); await load(); });
  });
}

async function load() {
  const data = await api('/api/state');
  Object.assign(state, data);
  renderAccounts();
  renderTasks();
  $('worker-status').textContent = data.settings.worker ? '调度器在线' : '调度器未启动';
  $('worker-status').classList.toggle('bad', !data.settings.worker);
  $('saved-routes').innerHTML = '<option value="">载入路线…</option>' + data.routes.map((route) => `<option value="${route.id}">${escapeHtml(route.name)}</option>`).join('');
}

async function selectAccount() {
  checkpoints.clearLayers();
  state.policy = null;
  const id = $('account-select').value;
  if (!id) return;
  let account = state.accounts.find((item) => item.id === id);
  if (!account.overview) {
    const result = await api(`/api/accounts/${id}/check`, {});
    if (!result.available) throw new Error(result.message);
    account.overview = result.overview;
  }
  const overview = account.overview;
  const zones = overview.zones.length ? overview.zones : [{ id: overview.default_zone, name: '当前跑区' }];
  $('zone-select').innerHTML = zones.map((zone) => `<option value="${escapeHtml(zone.id)}">${escapeHtml(zone.name || zone.id)}</option>`).join('');
  $('zone-select').value = overview.default_zone || zones[0].id;
  await selectZone();
  renderAccounts();
}

async function selectZone() {
  const id = $('account-select').value;
  if (!id) return;
  state.policy = await api(`/api/accounts/${id}/zone`, { game_id: $('zone-select').value });
  checkpoints.clearLayers();
  const points = state.policy.rules.checkpoints;
  points.forEach((point) => L.circle(toWgs({ a: Number(point.lat), o: Number(point.lng) }), {
    color: '#b17a2d', weight: 1, radius: state.policy.rules.checkpoint_radius_m, fillOpacity: .12,
  }).bindTooltip(escapeHtml(point.name || point.id)).addTo(checkpoints));
  if (points.length && !state.points.length) map.fitBounds(points.map((p) => toWgs({ a: Number(p.lat), o: Number(p.lng) })), { padding: [75, 75], maxZoom: 17 });
  const window = state.policy.rules.windows[0];
  $('policy-summary').textContent = `学校 ${state.policy.school_id} · 学期 ${state.policy.term_id} · ${points.length} 个候选打卡点。里程 ${window.min_distance}–${window.max_distance} km，配速 ${Math.ceil(window.fastest_pace / 60)}–${Math.floor(window.slowest_pace / 60)} 分/公里。`;
}
$('account-select').onchange = () => selectAccount().catch((error) => toast(error.message));
$('zone-select').onchange = () => selectZone().catch((error) => toast(error.message));
$('schedule').onchange = () => { $('fixed-label').hidden = $('schedule').value !== 'fixed'; };
document.querySelectorAll('input[name=repeat]').forEach((input) => {
  input.onchange = () => {
    const daily = document.querySelector('input[name=repeat]:checked').value === 'daily';
    $('target-label').hidden = !daily;
    $('schedule').disabled = daily;
    if (daily) { $('schedule').value = 'random'; $('fixed-label').hidden = true; }
  };
});

function inputConfig() {
  const [minutes, seconds] = $('pace').value.split(':').map(Number);
  if (!Number.isFinite(minutes) || !Number.isFinite(seconds) || seconds < 0 || seconds > 59) throw new Error('配速请填写为 分:秒');
  return { game_id: $('zone-select').value, distance_km: Number($('distance').value),
    pace_s_per_km: minutes * 60 + seconds, control_points: state.points.map(toGcj),
    closed: document.querySelector('input[name=closed]:checked').value === 'true' };
}

function payload() {
  const fixed = $('fixed-time').value;
  return { account_id: $('account-select').value, config: inputConfig(), mode: $('schedule').value,
    requested_at: $('schedule').value === 'fixed' && fixed ? Date.parse(fixed + ':00+08:00') / 1000 : null };
}

$('preview-button').onclick = () => busy($('preview-button'), async () => {
  $('form-error').hidden = true;
  const result = await api('/api/preview', payload());
  generated.clearLayers();
  L.polyline(result.route.map(toWgs), { color: '#d57935', weight: 2, opacity: .8 }).addTo(generated);
  $('preview-summary').textContent = `检查通过 · ${result.route.length} 个采样点 · 开始 ${beijing(result.slot.start_at)} · 预计结束 ${beijing(result.slot.finish_at)}。随机预约以实际创建时的安排为准。`;
  $('preview-summary').hidden = false;
});

$('reservation').onsubmit = (event) => {
  event.preventDefault();
  const button = event.submitter;
  busy(button, async () => {
    $('form-error').hidden = true;
    try {
      const daily = document.querySelector('input[name=repeat]:checked').value === 'daily';
      const input = payload();
      if (daily && $('target').value) input.target = Number($('target').value);
      const result = await api(daily ? '/api/hosting' : '/api/tasks', input);
      toast(daily ? '已开启每日自动预约' : `已预约，北京时间 ${beijing(result.slot.start_at)} 开始`);
      await load();
      setTab('tasks');
    } catch (error) {
      $('form-error').textContent = error.message;
      $('form-error').hidden = false;
    }
  });
};
$('save-route').onclick = () => {
  const name = prompt('路线名称');
  if (!name) return;
  busy($('save-route'), async () => { await api('/api/routes', { name, config: inputConfig() }); await load(); toast('路线已保存'); });
};
$('saved-routes').onchange = () => {
  const route = state.routes.find((item) => item.id === $('saved-routes').value);
  if (!route) return;
  const config = JSON.parse(route.config);
  state.points = config.control_points.map(toWgs);
  $('distance').value = config.distance_km;
  $('pace').value = `${Math.floor(config.pace_s_per_km / 60)}:${String(config.pace_s_per_km % 60).padStart(2, '0')}`;
  document.querySelector(`input[name=closed][value="${Boolean(config.closed)}"]`).checked = true;
  redraw();
  if (state.points.length) map.fitBounds(state.points, { padding: [35, 35] });
};

$('login-button').onclick = () => $('qr-dialog').showModal();
document.querySelectorAll('[data-close]').forEach((button) => { button.onclick = () => button.closest('dialog').close(); });
$('qr-dialog').addEventListener('close', () => { state.qr = null; });
async function startQr(provider, button) {
  state.qr = null;
  $('qr-status').textContent = '正在获取二维码…';
  $('qr-image').replaceChildren();
  const qr = await api('/api/bindings', { provider });
  const image = document.createElement('img');
  image.src = 'data:image/png;base64,' + qr.image;
  image.alt = `${provider === 'qq' ? 'QQ' : '微信'}登录二维码`;
  $('qr-image').replaceChildren(image);
  state.qr = qr.id;
  $('qr-status').textContent = '请使用手机扫码并确认登录';
  while (state.qr === qr.id && $('qr-dialog').open) {
    await new Promise((resolve) => setTimeout(resolve, 2500));
    if (state.qr !== qr.id || !$('qr-dialog').open) break;
    const result = await api(`/api/bindings/${qr.id}`);
    if (state.qr !== qr.id) break;
    if (result.state === 'connected') {
      $('qr-dialog').close();
      await load();
      $('account-select').value = result.account_id;
      await selectAccount();
      toast('登录成功');
      break;
    }
    const messages = { waiting: '等待扫码', scanned: '已扫码，请在手机上确认', working: '正在登录乐跑…', expired: '二维码已过期，请重新获取', failed: result.message || '扫码未完成，请重新获取' };
    $('qr-status').textContent = messages[result.state] || '等待授权';
    if (['expired', 'failed'].includes(result.state)) { state.qr = null; break; }
  }
}
for (const provider of ['wechat', 'qq']) {
  $(`${provider}-qr`).onclick = () => busy($(`${provider}-qr`), () => startQr(provider, $(`${provider}-qr`)));
}
$('settings-button').onclick = () => {
  $('wechat-appid').value = state.settings.wechat_app_id;
  $('wechat-config-state').textContent = state.settings.wechat_configured ? '已配置微信授权' : '尚未配置微信授权';
  $('settings-dialog').showModal();
};
$('settings-form').onsubmit = (event) => {
  event.preventDefault();
  busy(event.submitter, async () => {
    await api('/api/settings', { wechat_app_id: $('wechat-appid').value, wechat_secret: $('wechat-secret').value });
    $('wechat-secret').value = '';
    $('settings-dialog').close();
    await load(); toast('设置已保存');
  });
};
function setTab(tab) {
  $('workspace').hidden = tab !== 'workspace';
  $('tasks').hidden = tab !== 'tasks';
  document.querySelectorAll('[data-tab]').forEach((button) => button.classList.toggle('active', button.dataset.tab === tab));
  if (tab === 'workspace') map.invalidateSize();
}
document.querySelectorAll('[data-tab]').forEach((button) => { button.onclick = () => setTab(button.dataset.tab); });
$('refresh-tasks').onclick = () => busy($('refresh-tasks'), load);
async function refreshLoop() {
  try {
    if (!document.hidden && !$('qr-dialog').open && !$('settings-dialog').open) await load();
  } catch (error) { toast(error.message); }
  setTimeout(refreshLoop, 15000);
}
load().then(() => setTimeout(refreshLoop, 15000)).catch((error) => toast(error.message));
