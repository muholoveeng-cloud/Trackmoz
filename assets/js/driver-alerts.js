/**
 * TrackMoz — Alertas do motorista (som + popup + timers)
 */
(function (window, document) {
    'use strict';

    if (window.TrackMozDriverAlerts) return;

    const STORAGE_KEY = 'tmz_driver_alerts_seen';
    const MUTE_KEY = 'tmz_driver_alerts_mute';
    const POLL_MS = 45000;
    const POLL_URGENT_MS = 15000;
    let pollTimer = null;
    let hasUrgentModal = false;
    let activeModalId = null;

    function cfg() {
        return window.TRACKMOZ_DRIVER_ALERTS || {};
    }

    function apiUrl() {
        return (cfg().apiUrl || '/api/driver-alerts.php');
    }

    function getSeen() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function markSeen(id) {
        const seen = getSeen();
        seen[id] = Date.now();
        // limpar > 7 dias
        const cutoff = Date.now() - 7 * 24 * 3600 * 1000;
        Object.keys(seen).forEach((k) => {
            if (seen[k] < cutoff) delete seen[k];
        });
        localStorage.setItem(STORAGE_KEY, JSON.stringify(seen));
    }

    function isMuted() {
        return localStorage.getItem(MUTE_KEY) === '1';
    }

    function setMuted(v) {
        localStorage.setItem(MUTE_KEY, v ? '1' : '0');
        const btn = document.getElementById('tmzAlertMute');
        if (btn) {
            btn.innerHTML = v
                ? '<i class="bi bi-volume-mute-fill"></i>'
                : '<i class="bi bi-volume-up-fill"></i>';
            btn.title = v ? 'Activar som' : 'Silenciar alertas';
        }
    }

    // ── Som (Web Audio) ──
    let AudioCtx = window.AudioContext || window.webkitAudioContext;
    let audioCtx = null;

    function ensureAudio() {
        if (!AudioCtx) return null;
        if (!audioCtx) audioCtx = new AudioCtx();
        if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
        return audioCtx;
    }

    function beep(freq, duration, volume) {
        const ctx = ensureAudio();
        if (!ctx) return;
        try {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = freq;
            gain.gain.value = volume;
            osc.start();
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
            osc.stop(ctx.currentTime + duration);
        } catch (e) { /* ignore */ }
    }

    function playSound(type) {
        if (isMuted()) return;
        ensureAudio();
        if (type === 'urgent') {
            beep(880, 0.18, 0.08);
            setTimeout(() => beep(660, 0.18, 0.07), 200);
            setTimeout(() => beep(880, 0.22, 0.08), 420);
        } else if (type === 'alert') {
            beep(640, 0.16, 0.06);
            setTimeout(() => beep(780, 0.18, 0.06), 180);
        } else {
            beep(520, 0.12, 0.04);
        }
    }

    // ── UI ──
    function ensureStyles() {
        if (document.getElementById('tmz-alert-styles')) return;
        const style = document.createElement('style');
        style.id = 'tmz-alert-styles';
        style.textContent = `
#tmzAlertStack{position:fixed;top:72px;right:16px;z-index:1080;display:flex;flex-direction:column;gap:10px;max-width:min(380px,calc(100vw - 24px));pointer-events:none}
.tmz-toast{pointer-events:auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 12px 40px rgba(15,23,42,.14);padding:14px 16px;animation:tmzIn .28s ease;overflow:hidden;position:relative}
.tmz-toast::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px}
.tmz-toast.high::before{background:#dc2626}
.tmz-toast.medium::before{background:#2563eb}
.tmz-toast.low::before{background:#94a3b8}
.tmz-toast-title{font-weight:700;font-size:.92rem;color:#0f172a;margin:0 0 4px;display:flex;align-items:center;gap:8px}
.tmz-toast-msg{font-size:.82rem;color:#475569;margin:0 0 10px;line-height:1.4}
.tmz-toast-actions{display:flex;gap:8px;align-items:center}
.tmz-toast-actions a,.tmz-toast-actions button{font-size:.78rem;font-weight:600;border-radius:8px;padding:6px 12px;border:1px solid #e2e8f0;background:#f8fafc;color:#334155;text-decoration:none;cursor:pointer}
.tmz-toast-actions a.primary{background:#2563eb;border-color:#2563eb;color:#fff}
.tmz-toast-actions button:hover,.tmz-toast-actions a:hover{filter:brightness(.97)}
@keyframes tmzIn{from{opacity:0;transform:translateX(16px)}to{opacity:1;transform:none}}
#tmzTimerBar{position:fixed;left:50%;transform:translateX(-50%);bottom:18px;z-index:1070;display:none;align-items:center;gap:14px;background:rgba(255,255,255,.97);border:1px solid #e2e8f0;box-shadow:0 8px 30px rgba(15,23,42,.12);border-radius:999px;padding:10px 18px;max-width:calc(100vw - 24px);font-size:.82rem}
#tmzTimerBar.show{display:flex}
#tmzTimerBar .tmz-timer-clock{font-weight:800;font-variant-numeric:tabular-nums;color:#0f172a}
#tmzTimerBar .tmz-timer-prazo{font-weight:700}
#tmzTimerBar .tmz-timer-prazo.ok{color:#16a34a}
#tmzTimerBar .tmz-timer-prazo.warn{color:#d97706}
#tmzTimerBar .tmz-timer-prazo.late{color:#dc2626}
#tmzTimerBar a{font-weight:600;color:#2563eb;text-decoration:none;white-space:nowrap}
#tmzAlertMute{position:fixed;bottom:18px;right:18px;z-index:1071;width:42px;height:42px;border-radius:50%;border:1px solid #e2e8f0;background:#fff;box-shadow:0 4px 16px rgba(15,23,42,.1);color:#64748b;display:flex;align-items:center;justify-content:center;cursor:pointer}
#tmzAlertMute:hover{color:#2563eb;border-color:#93c5fd}
#tmzAlertModal{position:fixed;inset:0;z-index:1090;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.55);backdrop-filter:blur(2px)}
#tmzAlertModal.show{display:flex}
.tmz-modal-card{background:#fff;border-radius:18px;max-width:420px;width:100%;box-shadow:0 24px 60px rgba(15,23,42,.28);padding:22px 22px 18px;animation:tmzIn .28s ease;border:1px solid #e2e8f0}
.tmz-modal-card .tmz-modal-icon{width:52px;height:52px;border-radius:14px;background:#fef2f2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin-bottom:12px}
.tmz-modal-card h5{margin:0 0 8px;font-weight:800;color:#0f172a;font-size:1.1rem}
.tmz-modal-card p{margin:0 0 16px;color:#475569;font-size:.9rem;line-height:1.45}
.tmz-modal-actions{display:flex;flex-direction:column;gap:8px}
.tmz-modal-actions a,.tmz-modal-actions button{display:block;text-align:center;border-radius:10px;padding:11px 14px;font-weight:700;font-size:.9rem;text-decoration:none;cursor:pointer;border:1px solid transparent}
.tmz-modal-actions a.primary{background:#dc2626;color:#fff}
.tmz-modal-actions button.secondary{background:#f8fafc;border-color:#e2e8f0;color:#334155}
@media(max-width:576px){#tmzTimerBar{bottom:70px;flex-wrap:wrap;justify-content:center;border-radius:16px;padding:10px 14px}#tmzAlertMute{bottom:70px}}
`;
        document.head.appendChild(style);
    }

    function ensureDom() {
        ensureStyles();
        let stack = document.getElementById('tmzAlertStack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'tmzAlertStack';
            document.body.appendChild(stack);
        }
        if (!document.getElementById('tmzTimerBar')) {
            const bar = document.createElement('div');
            bar.id = 'tmzTimerBar';
            bar.innerHTML = `
                <span class="tmz-timer-clock" id="tmzTimerClock">00:00:00</span>
                <span class="text-muted" id="tmzTimerTitle" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
                <span class="tmz-timer-prazo" id="tmzTimerPrazo"></span>
                <a id="tmzTimerLink" href="#">Abrir</a>
            `;
            document.body.appendChild(bar);
        }
        if (!document.getElementById('tmzAlertMute')) {
            const btn = document.createElement('button');
            btn.id = 'tmzAlertMute';
            btn.type = 'button';
            btn.title = 'Silenciar alertas';
            btn.innerHTML = '<i class="bi bi-volume-up-fill"></i>';
            btn.addEventListener('click', () => setMuted(!isMuted()));
            document.body.appendChild(btn);
            setMuted(isMuted());
        }
        return stack;
    }

    function ensureModal() {
        ensureStyles();
        let modal = document.getElementById('tmzAlertModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'tmzAlertModal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.innerHTML = `
                <div class="tmz-modal-card">
                    <div class="tmz-modal-icon"><i class="bi bi-truck-front-fill"></i></div>
                    <h5 id="tmzModalTitle">Atenção</h5>
                    <p id="tmzModalMsg"></p>
                    <div class="tmz-modal-actions">
                        <a class="primary" id="tmzModalCta" href="#">Abrir missão</a>
                        <button type="button" class="secondary" id="tmzModalLater">Ver mais tarde</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            document.getElementById('tmzModalLater').addEventListener('click', () => {
                // Só esconde 2 min — volta a incomodar
                if (activeModalId) {
                    try {
                        const snooze = JSON.parse(localStorage.getItem('tmz_driver_alerts_snooze') || '{}') || {};
                        snooze[activeModalId] = Date.now() + 2 * 60 * 1000;
                        localStorage.setItem('tmz_driver_alerts_snooze', JSON.stringify(snooze));
                    } catch (e) { /* ignore */ }
                }
                hideModal();
            });
            document.getElementById('tmzModalCta').addEventListener('click', () => {
                if (activeModalId) markSeen(activeModalId);
            });
        }
        return modal;
    }

    function isSnoozed(id) {
        try {
            const snooze = JSON.parse(localStorage.getItem('tmz_driver_alerts_snooze') || '{}') || {};
            return snooze[id] && snooze[id] > Date.now();
        } catch (e) {
            return false;
        }
    }

    function hideModal() {
        const modal = document.getElementById('tmzAlertModal');
        if (modal) modal.classList.remove('show');
        activeModalId = null;
    }

    function showModal(alert) {
        if (activeModalId === alert.id) return;
        const modal = ensureModal();
        activeModalId = alert.id;
        document.getElementById('tmzModalTitle').textContent = alert.titulo || 'Nova missão';
        document.getElementById('tmzModalMsg').textContent = alert.mensagem || '';
        const cta = document.getElementById('tmzModalCta');
        cta.textContent = alert.cta || 'Abrir missão';
        cta.href = alert.link || '#';
        modal.classList.add('show');
        playSound(alert.sound || 'urgent');

        if (window.Notification && Notification.permission === 'granted') {
            try {
                const n = new Notification(alert.titulo || 'TrackMoz', {
                    body: alert.mensagem || '',
                    tag: alert.id,
                    requireInteraction: true,
                });
                n.onclick = () => {
                    if (alert.link) window.location.href = alert.link;
                    n.close();
                };
            } catch (e) { /* ignore */ }
        }
    }

    function showToast(alert) {
        const stack = ensureDom();
        const el = document.createElement('div');
        el.className = 'tmz-toast ' + (alert.priority || 'medium');
        el.dataset.alertId = alert.id;
        const icon = alert.tipo === 'atraso' ? 'bi-exclamation-triangle-fill text-danger'
            : alert.tipo === 'proxima' ? 'bi-geo-alt-fill text-primary'
            : alert.tipo === 'atribuicao' ? 'bi-truck-front-fill text-danger'
            : 'bi-bell-fill text-warning';
        el.innerHTML = `
            <div class="tmz-toast-title"><i class="bi ${icon}"></i>${escapeHtml(alert.titulo || 'Alerta')}</div>
            <p class="tmz-toast-msg">${escapeHtml(alert.mensagem || '')}</p>
            <div class="tmz-toast-actions">
                ${alert.link ? `<a class="primary" href="${escapeAttr(alert.link)}">Ver</a>` : ''}
                <button type="button" data-dismiss>Dispensar</button>
            </div>
        `;
        el.querySelector('[data-dismiss]').addEventListener('click', () => {
            markSeen(alert.id);
            el.remove();
        });
        stack.prepend(el);
        playSound(alert.sound || 'soft');

        // Browser notification (se permitido)
        if (window.Notification && Notification.permission === 'granted') {
            try {
                const n = new Notification(alert.titulo || 'TrackMoz', {
                    body: alert.mensagem || '',
                    tag: alert.id,
                });
                n.onclick = () => {
                    if (alert.link) window.location.href = alert.link;
                    n.close();
                };
            } catch (e) { /* ignore */ }
        }

        // Auto-dismiss low priority
        const ttl = alert.priority === 'high' ? 20000 : (alert.priority === 'medium' ? 14000 : 9000);
        setTimeout(() => {
            if (el.parentNode) el.remove();
        }, ttl);
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function escapeAttr(s) {
        return escapeHtml(s).replace(/'/g, '&#39;');
    }

    function formatHMS(totalSec) {
        totalSec = Math.max(0, Math.floor(totalSec || 0));
        const h = Math.floor(totalSec / 3600);
        const m = Math.floor((totalSec % 3600) / 60);
        const s = totalSec % 60;
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    let activeTimer = null;
    let timerTick = null;
    let baseTempo = 0;
    let tickStartedAt = 0;

    function updateTimerUi() {
        const bar = document.getElementById('tmzTimerBar');
        if (!bar || !activeTimer) return;
        const elapsed = baseTempo + (activeTimer.modo_conducao_ativo
            ? Math.floor((Date.now() - tickStartedAt) / 1000)
            : 0);
        const clock = document.getElementById('tmzTimerClock');
        if (clock) clock.textContent = formatHMS(elapsed);

        const prazoEl = document.getElementById('tmzTimerPrazo');
        if (prazoEl) {
            if (activeTimer.atrasada) {
                prazoEl.className = 'tmz-timer-prazo late';
                prazoEl.textContent = 'Atrasada (' + Math.abs(activeTimer.dias_restantes) + 'd)';
            } else if (activeTimer.dias_restantes === null || activeTimer.dias_restantes === undefined) {
                prazoEl.className = 'tmz-timer-prazo';
                prazoEl.textContent = 'Sem prazo';
            } else if (activeTimer.dias_restantes <= 1) {
                prazoEl.className = 'tmz-timer-prazo warn';
                prazoEl.textContent = activeTimer.dias_restantes === 0 ? 'Vence hoje' : 'Vence amanhã';
            } else {
                prazoEl.className = 'tmz-timer-prazo ok';
                prazoEl.textContent = activeTimer.dias_restantes + 'd restantes';
            }
        }
    }

    function renderTimers(timers) {
        ensureDom();
        const bar = document.getElementById('tmzTimerBar');
        if (!timers || !timers.length) {
            activeTimer = null;
            if (timerTick) clearInterval(timerTick);
            timerTick = null;
            if (bar) bar.classList.remove('show');
            // Dashboard widget
            const dash = document.getElementById('tmzDashTimers');
            if (dash) dash.innerHTML = '<div class="tm-empty-state py-3 mb-0">Sem missões activas com timer.</div>';
            return;
        }

        // Prefer condução activa, senão a mais urgente
        activeTimer = timers.find((t) => t.modo_conducao_ativo) || timers[0];
        baseTempo = activeTimer.tempo_conducao_seg || 0;
        tickStartedAt = Date.now();

        document.getElementById('tmzTimerTitle').textContent = activeTimer.titulo || 'Missão';
        const link = document.getElementById('tmzTimerLink');
        link.href = activeTimer.modo_conducao_ativo ? (activeTimer.modo_link || activeTimer.link) : activeTimer.link;
        link.textContent = activeTimer.modo_conducao_ativo ? 'Modo condução' : 'Abrir missão';
        bar.classList.add('show');
        updateTimerUi();
        if (timerTick) clearInterval(timerTick);
        timerTick = setInterval(updateTimerUi, 1000);

        // Dashboard panel
        const dash = document.getElementById('tmzDashTimers');
        if (dash) {
            dash.innerHTML = timers.map((t) => {
                const prazoCls = t.atrasada ? 'text-danger' : (t.dias_restantes !== null && t.dias_restantes <= 1 ? 'text-warning' : 'text-success');
                const prazoTxt = t.atrasada
                    ? ('Atrasada ' + Math.abs(t.dias_restantes) + 'd')
                    : (t.dias_restantes === null ? 'Sem prazo' : (t.dias_restantes + 'd restantes'));
                return `<div class="tm-timer-row">
                    <div style="min-width:0">
                        <div class="fw-bold text-truncate">${escapeHtml(t.titulo)}</div>
                        <small class="text-muted">${escapeHtml((t.origem || '') + ' → ' + (t.destino || ''))}</small>
                    </div>
                    <div class="text-end flex-shrink-0">
                        <div class="tm-timer-clock">${formatHMS(t.tempo_conducao_seg || 0)}</div>
                        <small class="${prazoCls} fw-semibold">${prazoTxt}</small>
                    </div>
                </div>`;
            }).join('');
        }
    }

    function updateBadge(unread) {
        const dots = document.querySelectorAll('.tm-icon-btn[href*="notificacoes"] .dot, .tm-icon-btn[href*="notificacoes"] .cnt');
        // Recreate count badge on notif icon
        document.querySelectorAll('a.tm-icon-btn[href*="notificacoes"]').forEach((a) => {
            let badge = a.querySelector('.cnt, .dot');
            if (unread > 0) {
                if (!badge || !badge.classList.contains('cnt')) {
                    if (badge) badge.remove();
                    badge = document.createElement('span');
                    badge.className = 'cnt';
                    a.appendChild(badge);
                }
                badge.textContent = unread > 99 ? '99+' : String(unread);
            } else if (badge) {
                badge.remove();
            }
        });
    }

    function schedulePoll() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(poll, hasUrgentModal ? POLL_URGENT_MS : POLL_MS);
    }

    function processAlerts(alerts) {
        const seen = getSeen();
        hasUrgentModal = false;
        let modalShown = false;

        (alerts || []).forEach((a) => {
            if (!a || !a.id) return;
            const isModal = a.mode === 'modal' || a.require_ack === true || a.tipo === 'atribuicao';

            if (isModal) {
                if (seen[a.id] || isSnoozed(a.id)) return;
                hasUrgentModal = true;
                if (!modalShown) {
                    showModal(a);
                    modalShown = true;
                }
                return;
            }

            if (seen[a.id]) return;
            markSeen(a.id);
            showToast(a);
        });

        if (!modalShown && activeModalId) {
            // Alerta modal já não é pendente
            const still = (alerts || []).some((a) => a && a.id === activeModalId);
            if (!still) hideModal();
        }

        schedulePoll();
    }

    function getPosition() {
        return new Promise((resolve) => {
            if (!navigator.geolocation) return resolve(null);
            navigator.geolocation.getCurrentPosition(
                (pos) => resolve({
                    latitude: pos.coords.latitude,
                    longitude: pos.coords.longitude,
                }),
                () => resolve(null),
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
            );
        });
    }

    async function poll() {
        try {
            const pos = await getPosition();
            const body = pos ? JSON.stringify(pos) : null;
            const res = await fetch(apiUrl(), {
                method: body ? 'POST' : 'GET',
                headers: body ? { 'Content-Type': 'application/json' } : undefined,
                body: body || undefined,
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.ok) return;
            updateBadge(data.unread || 0);
            processAlerts(data.alerts || []);
            renderTimers(data.timers || []);
            if (typeof cfg().onData === 'function') cfg().onData(data);
        } catch (e) {
            // silencioso
        }
    }

    function requestNotifPermission() {
        if (!window.Notification || Notification.permission !== 'default') return;
        // pedir após interação
        const once = () => {
            Notification.requestPermission().catch(() => {});
            document.removeEventListener('click', once);
        };
        document.addEventListener('click', once, { once: true });
    }

    function start() {
        ensureDom();
        ensureModal();
        // unlock audio on first gesture
        const unlock = () => { ensureAudio(); document.removeEventListener('click', unlock); };
        document.addEventListener('click', unlock, { once: true });
        requestNotifPermission();

        // Se já está na página da missão atribuída, marcar como vista
        try {
            const m = window.location.pathname.match(/detalhes-missao\.php/);
            const params = new URLSearchParams(window.location.search);
            const mid = params.get('id') || params.get('missao_id');
            if (m && mid) {
                markSeen('atribuicao-' + mid);
            }
        } catch (e) { /* ignore */ }

        poll();
        schedulePoll();
    }

    window.TrackMozDriverAlerts = {
        start,
        poll,
        playSound,
        setMuted,
        isMuted,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window, document);
