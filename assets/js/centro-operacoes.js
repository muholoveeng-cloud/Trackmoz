/**
 * TrackMoz Centro de Operações — automações profissionais.
 */
window.TrackMozCentroOperacoes = class {
    constructor(containerId, options = {}) {
        this.baseUrl = options.baseUrl || '';
        this.scope = options.scope || 'operacoes';
        this.detalheBase = options.detalheBase || '';
        this.userType = options.userType || '';
        this.filter = 'todos';
        this.tab = 'missoes';
        this.search = '';
        this.data = { missoes: [], motoristas: [], viaturas: [], emergencias: [], eventos: [], stats: {} };
        this.selectedId = null;
        this._loading = false;
        this._firstLoad = true;
        this._wallMode = false;
        this._soundOn = localStorage.getItem('tm_ops_sound') !== '0';
        this._refreshMs = 8000;
        this._prevPos = {};
        this._seenEvents = new Set(JSON.parse(sessionStorage.getItem('tm_ops_seen') || '[]'));
        this._watch = new Set(JSON.parse(localStorage.getItem('tm_ops_watch') || '[]').map(Number));
        this._lastResumoHour = -1;
        this._audioCtx = null;
        this._destroyed = false;
        this._abort = null;
        this._pageLeaveBound = () => this.parar();

        const Core = window.TrackMozMapCore;
        this.provider = new Core.LeafletProvider(containerId, {
            fullscreen: true,
            zoomControl: true,
            center: Core.MZ_CENTER,
            zoom: Core.MZ_ZOOM,
        });

        if (options.darkTiles) this._applyDarkTiles();

        this.layerRotas = this.provider.createLayerGroup('rotas');
        this.layerPontos = this.provider.createLayerGroup('pontos');
        this.layerMarcadores = this.provider.createLayerGroup('marcadores');
        this.layerGeo = this.provider.createLayerGroup('geofence');
        this.markers = {};
        this._rotaCache = {};

        this.els = {
            list: document.getElementById('ops-list'),
            detail: document.getElementById('ops-detail'),
            empty: document.getElementById('ops-empty'),
            feed: document.getElementById('ops-feed'),
            banner: document.getElementById('ops-banner'),
            toast: document.getElementById('ops-toast'),
            kpiTotal: document.getElementById('kpi-total'),
            kpiGps: document.getElementById('kpi-gps'),
            kpiOffline: document.getElementById('kpi-offline'),
            kpiEmerg: document.getElementById('kpi-emerg'),
            kpiRisco: document.getElementById('kpi-risco'),
            resumo: document.getElementById('ops-resumo'),
        };

        this.realtime = new TrackMozRealtime({
            baseUrl: this.baseUrl,
            onEvent: (ev) => this._onRealtime(ev),
        });

        this._labels = {
            em_transito: 'Em trânsito',
            em_recolha: 'Em recolha',
            parado: 'Parado',
            emergencia: 'Emergência',
            offline: 'Offline',
        };

        window.addEventListener('pagehide', this._pageLeaveBound);
        window.addEventListener('beforeunload', this._pageLeaveBound);
    }

    _applyDarkTiles() {
        const map = this.provider.map;
        map.eachLayer((layer) => {
            if (layer instanceof L.TileLayer) map.removeLayer(layer);
        });
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap &copy; CARTO',
            maxZoom: 19,
            subdomains: 'abcd',
        }).addTo(map);
    }

    _icone(estado, tipo = 'truck') {
        if (tipo === 'origem' || tipo === 'destino') {
            return L.divIcon({
                className: '',
                html: `<div class="ops-marker ${tipo}"></div>`,
                iconSize: [18, 18],
                iconAnchor: [9, 9],
            });
        }
        const st = estado || 'parado';
        const emoji = st === 'emergencia' ? '🚨' : '🚛';
        const extra = st === 'emergencia' ? ' blink' : '';
        return L.divIcon({
            className: '',
            html: `<div class="ops-marker ${st}${extra}">${emoji}</div>`,
            iconSize: [40, 40],
            iconAnchor: [20, 20],
            popupAnchor: [0, -22],
        });
    }

    async carregar(force = false) {
        if (this._destroyed) return null;
        if (this._loading && !force) return;
        this._loading = true;
        if (this._abort) {
            try { this._abort.abort(); } catch (e) { /* ignore */ }
        }
        this._abort = typeof AbortController !== 'undefined' ? new AbortController() : null;
        try {
            const res = await fetch(
                `${this.baseUrl}/api/get-mapa-dados.php?scope=${encodeURIComponent(this.scope)}&_=${Date.now()}`,
                {
                    credentials: 'same-origin',
                    signal: this._abort ? this._abort.signal : undefined,
                }
            );
            if (this._destroyed) return null;
            const raw = await res.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (parseErr) {
                console.error('get-mapa-dados raw', raw.slice(0, 500));
                this._showError('API devolveu resposta inválida (HTTP ' + res.status + ')');
                return null;
            }
            if (!data.ok) {
                this._showError(data.error || 'Falha ao carregar dados');
                return null;
            }
            const prevEmerg = new Set((this.data.emergencias || []).map((e) => e.id));
            this.data = data;
            this._detectParadoWatchdog();
            this._renderMap();
            this._renderKpis();
            this._renderList();
            this._renderDetail();
            this._renderFeed();
            this._renderResumo();
            this._processAutomations(prevEmerg);
            if (this._firstLoad) {
                this._autoFocus();
                this._firstLoad = false;
            }
            this._maybeHourlyResumo();
            return data;
        } catch (err) {
            if (err && err.name === 'AbortError') return null;
            console.error(err);
            this._showError('Sem ligação à API do mapa');
            return null;
        } finally {
            this._loading = false;
        }
    }

    _showError(msg) {
        if (!this.els.list) return;
        this.els.list.innerHTML = `<div class="ops-alert"><strong>Erro</strong><br>${this._esc(msg)}</div>`;
    }

    setFilter(f) {
        this.filter = f || 'todos';
        this._renderMap();
        this._renderList();
    }

    setTab(tab) {
        this.tab = tab || 'missoes';
        this._renderList();
    }

    setSearch(q) {
        this.search = (q || '').trim().toLowerCase();
        this._renderList();
    }

    toggleSound() {
        this._soundOn = !this._soundOn;
        localStorage.setItem('tm_ops_sound', this._soundOn ? '1' : '0');
        this._toast(this._soundOn ? 'Som de alertas ligado' : 'Som desligado');
        return this._soundOn;
    }

    toggleWallMode() {
        this._wallMode = !this._wallMode;
        document.body.classList.toggle('ops-wall', this._wallMode);
        this._refreshMs = this._wallMode ? 5000 : 8000;
        this.iniciarAtualizacao(this._refreshMs);
        this._toast(this._wallMode ? 'Modo parede activo' : 'Modo normal');
        if (this._wallMode) this.fitAll();
        return this._wallMode;
    }

    fitAll() {
        const bounds = [];
        Object.values(this.markers).forEach((mk) => {
            if (mk.getLatLng) {
                const ll = mk.getLatLng();
                if (ll) bounds.push([ll.lat, ll.lng]);
            }
        });
        if (bounds.length) this.provider.fitBounds(bounds, [50, 50]);
        else this.provider.map.setView(TrackMozMapCore.MZ_CENTER, TrackMozMapCore.MZ_ZOOM);
    }

    focusMissao(id, opts = {}) {
        this.selectedId = id;
        const m = this.data.missoes.find((x) => x.id === id);
        this._renderList();
        this._renderDetail();
        if (!m) return;

        const lat = m.lat || m.origem_lat;
        const lng = m.lng || m.origem_lng;
        if (lat && lng) {
            this.provider.setView(lat, lng, opts.zoom || 12);
            const mk = this.markers['m' + id];
            if (mk && mk.openPopup) mk.openPopup();
        }
        this._highlightRota(m);
        this._drawGeofence(m);
    }

    _autoFocus() {
        const list = this.data.missoes || [];
        const emerg = list.find((m) => m.estado_mapa === 'emergencia');
        if (emerg) {
            this.focusMissao(emerg.id, { zoom: 13 });
            return;
        }
        const crit = list.find((m) => (m.automacao?.prioridade || 0) >= 50);
        if (crit) {
            this.focusMissao(crit.id);
            return;
        }
        this.fitAll();
    }

    _missoesFiltradas() {
        let list = this.data.missoes || [];
        if (this.filter === 'risco') {
            list = list.filter((m) => m.automacao?.em_risco || m.automacao?.atraso || m.automacao?.desvio_rota);
        } else if (this.filter === 'watch') {
            list = list.filter((m) => this._watch.has(m.id));
        } else if (this.filter !== 'todos') {
            list = list.filter((m) => m.estado_mapa === this.filter);
        }
        if (this.search) {
            const q = this.search;
            list = list.filter((m) => {
                const blob = [
                    m.titulo, m.origem, m.destino, m.motorista_nome,
                    m.nome_empresa, m.status, String(m.id),
                    ...(m.automacao?.alertas || []),
                ].join(' ').toLowerCase();
                return blob.includes(q);
            });
        }
        return list;
    }

    _renderKpis() {
        const s = this.data.stats || {};
        if (this.els.kpiTotal) this.els.kpiTotal.textContent = s.total ?? 0;
        if (this.els.kpiGps) this.els.kpiGps.textContent = s.com_gps ?? 0;
        if (this.els.kpiOffline) this.els.kpiOffline.textContent = s.offline ?? 0;
        if (this.els.kpiEmerg) {
            this.els.kpiEmerg.textContent = s.emergencias_abertas ?? s.emergencia ?? 0;
        }
        if (this.els.kpiRisco) {
            const risco = (s.atraso || 0) + (s.em_risco || 0) + (s.desvio || 0);
            this.els.kpiRisco.textContent = risco;
        }
    }

    _renderResumo() {
        if (this.els.resumo) {
            this.els.resumo.textContent = this.data.resumo || '';
        }
    }

    _renderMap() {
        this.layerRotas.clearLayers();
        this.layerPontos.clearLayers();
        this.layerMarcadores.clearLayers();
        this.layerGeo.clearLayers();
        this.markers = {};
        const bounds = [];
        const missoes = this._missoesFiltradas();
        let comPosicao = 0;

        missoes.forEach((m) => {
            if (m.origem_lat && m.origem_lng) {
                L.marker([m.origem_lat, m.origem_lng], { icon: this._icone(null, 'origem'), zIndexOffset: 50 })
                    .addTo(this.layerPontos)
                    .bindPopup(`<strong>Recolha</strong><br>${this._esc(m.origem || '')}`);
                bounds.push([m.origem_lat, m.origem_lng]);
            }
            if (m.destino_lat && m.destino_lng) {
                L.marker([m.destino_lat, m.destino_lng], { icon: this._icone(null, 'destino'), zIndexOffset: 50 })
                    .addTo(this.layerPontos)
                    .bindPopup(`<strong>Entrega</strong><br>${this._esc(m.destino || '')}`);
                bounds.push([m.destino_lat, m.destino_lng]);
            }

            if (m.origem_lat && m.destino_lat) {
                const cor = m.estado_mapa === 'emergencia' ? '#f87171'
                    : (m.automacao?.desvio_rota ? '#fbbf24' : 'rgba(34,211,238,.35)');
                L.polyline(
                    [[m.origem_lat, m.origem_lng], [m.destino_lat, m.destino_lng]],
                    { color: cor, weight: 2, opacity: 0.7, dashArray: '4 8' }
                ).addTo(this.layerRotas);
            }

            const lat = m.lat || m.origem_lat;
            const lng = m.lng || m.origem_lng;
            if (!lat || !lng) return;

            comPosicao++;
            const estado = m.estado_mapa || 'parado';
            const z = estado === 'emergencia' ? 1000
                : (m.automacao?.atraso ? 800 : (m.automacao?.offline_longo ? 700 : 200));
            const mk = L.marker([lat, lng], {
                icon: this._icone(estado),
                zIndexOffset: z,
            })
                .addTo(this.layerMarcadores)
                .bindPopup(this._popupMissao(m));

            mk.on('click', () => this.focusMissao(m.id));
            this.markers['m' + m.id] = mk;
            bounds.push([lat, lng]);
        });

        if (this.filter === 'todos' || this.tab === 'motoristas') {
            (this.data.motoristas || []).forEach((mot) => {
                if (this._motoristaJaRenderizado(mot.id, missoes)) return;
                if (!mot.lat || !mot.lng) return;
                comPosicao++;
                const mk = L.marker([mot.lat, mot.lng], {
                    icon: this._icone(mot.estado_mapa || 'parado'),
                    zIndexOffset: 150,
                })
                    .addTo(this.layerMarcadores)
                    .bindPopup(`<strong>${this._esc(mot.nome)}</strong><br><small>Motorista · ${this._esc(mot.disponibilidade || '')}</small>`);
                this.markers['d' + mot.id] = mk;
                bounds.push([mot.lat, mot.lng]);
            });
        }

        if (this.els.empty) {
            this.els.empty.classList.toggle('show', comPosicao === 0 && (this.data.missoes || []).length > 0);
        }

        if (!this.selectedId && bounds.length && this._firstLoad) {
            setTimeout(() => this.provider.fitBounds(bounds, [60, 60]), 300);
        }
        setTimeout(() => this.provider.map.invalidateSize(), 200);
    }

    _motoristaJaRenderizado(id, missoes) {
        return missoes.some((m) => m.caminhoneiro_id === id && (m.lat || m.origem_lat));
    }

    _popupMissao(m) {
        const label = this._labels[m.estado_mapa] || m.status;
        const flags = (m.automacao?.alertas || []).map((a) => this._alertaLabel(a)).join(' · ');
        const gps = m.lat ? 'GPS activo' : 'Sem GPS (origem)';
        return `<div class="tm-popup">
            <strong>${this._esc(m.titulo || ('Missão #' + m.id))}</strong><br>
            <span class="ops-pop-badge pill-${this._esc(m.estado_mapa || 'parado')}">${this._esc(label)}</span><br>
            ${flags ? `<small style="color:#fbbf24">${this._esc(flags)}</small><br>` : ''}
            <small>${this._esc(m.nome_empresa || '')}</small><br>
            ${this._esc(m.motorista_nome || 'Sem motorista')}<br>
            ${this._esc(m.origem || '')} → ${this._esc(m.destino || '')}<br>
            <small style="opacity:.7">${gps}</small>
        </div>`;
    }

    _alertaLabel(a) {
        const map = {
            emergencia: 'Emergência',
            atraso: 'Atraso',
            prazo_risco: 'Prazo risco',
            offline: 'Offline',
            offline_escalado: 'Offline↑',
            desvio: 'Desvio',
            geofence_recolha: 'Zona recolha',
            geofence_entrega: 'Zona entrega',
            parado_longo: 'Parado↑',
        };
        return map[a] || a;
    }

    async _highlightRota(m) {
        if (!m.origem_lat || !m.destino_lat) return;
        const key = 'sel-' + m.id;
        if (this.markers[key]) this.layerRotas.removeLayer(this.markers[key]);
        let coords = this._rotaCache[`${m.origem_lat},${m.origem_lng}-${m.destino_lat},${m.destino_lng}`];
        let tempoMin = null;
        if (!coords) {
            try {
                const rota = await TrackMozMapCore.calcularRota(
                    this.baseUrl, m.origem_lat, m.origem_lng, m.destino_lat, m.destino_lng
                );
                if (rota?.coordinates?.length) {
                    coords = rota.coordinates;
                    tempoMin = rota.tempo_min;
                    this._rotaCache[`${m.origem_lat},${m.origem_lng}-${m.destino_lat},${m.destino_lng}`] = coords;
                    this._rotaCache['eta-' + m.id] = tempoMin;
                }
            } catch (e) { /* ignore */ }
        } else {
            tempoMin = this._rotaCache['eta-' + m.id] || null;
        }
        if (!coords) coords = [[m.origem_lat, m.origem_lng], [m.destino_lat, m.destino_lng]];
        const line = L.polyline(coords, { color: '#22d3ee', weight: 4, opacity: 0.9 }).addTo(this.layerRotas);
        this.markers[key] = line;
        if (tempoMin != null) {
            m._eta_min = tempoMin;
            this._renderDetail();
        }
        try {
            this.provider.map.fitBounds(line.getBounds(), { padding: [60, 60] });
        } catch (e) { /* ignore */ }
    }

    _drawGeofence(m) {
        this.layerGeo.clearLayers();
        const r = (this.data.config && this.data.config.geofence_m) || 500;
        if (m.origem_lat && m.origem_lng) {
            L.circle([m.origem_lat, m.origem_lng], {
                radius: r, color: '#34d399', fillColor: '#34d399', fillOpacity: 0.08, weight: 1,
            }).addTo(this.layerGeo);
        }
        if (m.destino_lat && m.destino_lng) {
            L.circle([m.destino_lat, m.destino_lng], {
                radius: r, color: '#f87171', fillColor: '#f87171', fillOpacity: 0.08, weight: 1,
            }).addTo(this.layerGeo);
        }
    }

    _renderList() {
        if (!this.els.list) return;

        if (this.tab === 'feed') {
            this._renderFeedList();
            return;
        }

        if (this.tab === 'alertas') {
            const emerg = this.data.emergencias || [];
            const risco = (this.data.missoes || []).filter(
                (m) => m.automacao?.atraso || m.automacao?.offline_longo || m.automacao?.desvio_rota
            );
            if (!emerg.length && !risco.length) {
                this.els.list.innerHTML = `<div class="ops-empty-list">Sem alertas abertos.</div>`;
                return;
            }
            let html = '';
            emerg.forEach((e) => {
                html += `<button type="button" class="ops-item" data-mid="${e.missao_id || ''}">
                    <div class="ops-item-title">
                        <span>${this._esc(e.titulo)}</span>
                        <span class="ops-pill pill-emergencia">${this._esc(e.gravidade || e.tipo || 'alerta')}</span>
                    </div>
                    <div class="ops-item-meta">${this._esc(e.status)} · ${this._esc(this._relTime(e.quando))}</div>
                </button>`;
            });
            risco.forEach((m) => {
                const tags = (m.automacao?.alertas || []).map((a) => this._alertaLabel(a)).join(', ');
                html += `<button type="button" class="ops-item" data-missao="${m.id}">
                    <div class="ops-item-title">
                        <span>${this._esc(m.titulo || ('#' + m.id))}</span>
                        <span class="ops-pill pill-offline">${this._esc(tags)}</span>
                    </div>
                    <div class="ops-item-meta">${this._esc(m.motorista_nome || '')} · ${this._esc(this._labels[m.estado_mapa] || '')}</div>
                </button>`;
            });
            this.els.list.innerHTML = html;
            this._bindListClicks();
            return;
        }

        if (this.tab === 'motoristas') {
            let mots = this.data.motoristas || [];
            if (this.search) {
                mots = mots.filter((m) => (m.nome || '').toLowerCase().includes(this.search));
            }
            if (!mots.length) {
                this.els.list.innerHTML = `<div class="ops-empty-list">Nenhum motorista com GPS.</div>`;
                return;
            }
            this.els.list.innerHTML = mots.map((mot) => `
                <button type="button" class="ops-item" data-driver="${mot.id}">
                    <div class="ops-item-title">
                        <span>${this._esc(mot.nome)}</span>
                        <span class="ops-pill pill-${this._esc(mot.estado_mapa || 'parado')}">${this._esc(this._labels[mot.estado_mapa] || '—')}</span>
                    </div>
                    <div class="ops-item-meta">${this._esc(mot.telefone || '')} · ${this._esc(mot.disponibilidade || '')}<br>${this._esc(this._relTime(mot.atualizado_em))}</div>
                </button>
            `).join('');
            this.els.list.querySelectorAll('[data-driver]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const id = parseInt(btn.dataset.driver, 10);
                    const mk = this.markers['d' + id];
                    if (mk) {
                        const ll = mk.getLatLng();
                        this.provider.setView(ll.lat, ll.lng, 13);
                        mk.openPopup();
                    }
                });
            });
            return;
        }

        const list = this._missoesFiltradas();
        if (!list.length) {
            this.els.list.innerHTML = `<div class="ops-empty-list">Nenhuma missão neste filtro.</div>`;
            return;
        }

        this.els.list.innerHTML = list.map((m) => {
            const a = m.automacao || {};
            const badges = [];
            if (a.atraso) badges.push('<span class="ops-mini danger">ATRASO</span>');
            else if (a.em_risco) badges.push('<span class="ops-mini warn">RISCO</span>');
            if (a.desvio_rota) badges.push('<span class="ops-mini warn">DESVIO</span>');
            if (a.near_destino) badges.push('<span class="ops-mini ok">ENTREGA</span>');
            else if (a.near_origem) badges.push('<span class="ops-mini ok">RECOLHA</span>');
            if (this._watch.has(m.id)) badges.push('<span class="ops-mini">👁</span>');
            return `<button type="button" class="ops-item ${this.selectedId === m.id ? 'active' : ''}" data-missao="${m.id}">
                <div class="ops-item-title">
                    <span>${this._esc(m.titulo || ('#' + m.id))}</span>
                    <span class="ops-pill pill-${this._esc(m.estado_mapa || 'parado')}">${this._esc(this._labels[m.estado_mapa] || m.status)}</span>
                </div>
                <div class="ops-item-meta">
                    ${this._esc(m.origem || '—')} → ${this._esc(m.destino || '—')}<br>
                    ${this._esc(m.motorista_nome || 'Sem motorista')}
                    ${m.lat ? ' · GPS' : ' · sem GPS'}
                    ${badges.length ? '<br>' + badges.join(' ') : ''}
                </div>
            </button>`;
        }).join('');
        this._bindListClicks();
    }

    _bindListClicks() {
        this.els.list.querySelectorAll('[data-missao]').forEach((btn) => {
            btn.addEventListener('click', () => this.focusMissao(parseInt(btn.dataset.missao, 10)));
        });
        this.els.list.querySelectorAll('[data-mid]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const mid = parseInt(btn.dataset.mid, 10);
                if (mid) this.focusMissao(mid);
            });
        });
    }

    _renderFeedList() {
        const ev = this.data.eventos || [];
        if (!ev.length) {
            this.els.list.innerHTML = `<div class="ops-empty-list">Sem eventos recentes.</div>`;
            return;
        }
        this.els.list.innerHTML = ev.map((e) => `
            <button type="button" class="ops-item ops-feed-item nivel-${this._esc(e.nivel || 'ok')}" data-mid="${e.missao_id || ''}">
                <div class="ops-item-title">
                    <span>${this._esc(e.titulo)}</span>
                    <span class="ops-item-meta">${this._esc(this._relTime(e.quando))}</span>
                </div>
                <div class="ops-item-meta">${this._esc(e.msg)}</div>
            </button>
        `).join('');
        this._bindListClicks();
    }

    _renderFeed() {
        // mini feed no HUD (últimos 4)
        if (!this.els.feed) return;
        const ev = (this.data.eventos || []).slice(0, 4);
        if (!ev.length) {
            this.els.feed.innerHTML = '';
            return;
        }
        this.els.feed.innerHTML = ev.map((e) => `
            <button type="button" class="ops-feed-chip nivel-${this._esc(e.nivel)}" data-mid="${e.missao_id || ''}">
                <strong>${this._esc(e.titulo)}</strong> ${this._esc(e.msg)}
            </button>
        `).join('');
        this.els.feed.querySelectorAll('[data-mid]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const mid = parseInt(btn.dataset.mid, 10);
                if (mid) this.focusMissao(mid);
            });
        });
    }

    _renderDetail() {
        if (!this.els.detail) return;
        const m = this.data.missoes.find((x) => x.id === this.selectedId);
        if (!m) {
            this.els.detail.classList.remove('show');
            this.els.detail.innerHTML = '';
            return;
        }
        const a = m.automacao || {};
        const url = this.detalheBase ? (this.detalheBase + m.id) : '#';
        const tel = m.motorista_telefone ? String(m.motorista_telefone).replace(/\s+/g, '') : '';
        const chatUrl = m.caminhoneiro_id
            ? `${this.baseUrl}/pages/chat.php?user=${m.caminhoneiro_id}`
            : `${this.baseUrl}/pages/chat.php`;
        const disputaUrl = `${this.baseUrl}/pages/shared/disputa.php?missao_id=${m.id}`;
        const watching = this._watch.has(m.id);
        const eta = m._eta_min != null ? `~${Math.round(m._eta_min)} min (rota)` : '—';
        const prazo = m.prazo_entrega
            ? new Date(m.prazo_entrega).toLocaleDateString('pt-MZ')
            : '—';

        let candHtml = '';
        if ((m.candidatos || []).length) {
            candHtml = `<div class="ops-cand">
                <div class="ops-cand-title">Sugestão de reatribuição</div>
                ${(m.candidatos || []).map((c) => `
                    <div class="ops-cand-row">
                        <span>${this._esc(c.nome)} · ${c.dist_km} km</span>
                        <span>
                            ${c.telefone ? `<a href="tel:${this._esc(String(c.telefone).replace(/\s+/g, ''))}" class="ops-link">Ligar</a>` : ''}
                            <a href="${this.baseUrl}/pages/chat.php?user=${c.id}" class="ops-link">Chat</a>
                        </span>
                    </div>
                `).join('')}
            </div>`;
        }

        let geoActions = '';
        if (a.near_origem) {
            geoActions += `<button type="button" class="ops-btn primary" data-geo="recolha"><i class="bi bi-geo-alt"></i> Confirmar recolha</button>`;
        }
        if (a.near_destino) {
            geoActions += `<button type="button" class="ops-btn primary" data-geo="entrega"><i class="bi bi-flag"></i> Confirmar entrega</button>`;
        }

        this.els.detail.classList.add('show');
        this.els.detail.innerHTML = `
            <h3>${this._esc(m.titulo || ('Missão #' + m.id))}</h3>
            <dl>
                <dt>Estado</dt><dd>${this._esc(this._labels[m.estado_mapa] || m.status)}</dd>
                <dt>Empresa</dt><dd>${this._esc(m.nome_empresa || '—')}</dd>
                <dt>Motorista</dt><dd>${this._esc(m.motorista_nome || '—')}</dd>
                <dt>Rota</dt><dd>${this._esc(m.origem || '')} → ${this._esc(m.destino || '')}</dd>
                <dt>ETA</dt><dd>${this._esc(eta)}</dd>
                <dt>Prazo</dt><dd class="${a.atraso ? 'text-danger' : (a.em_risco ? 'text-warn' : '')}">${this._esc(prazo)}</dd>
                <dt>GPS</dt><dd>${m.lat ? (m.lat.toFixed(4) + ', ' + m.lng.toFixed(4)) : 'Indisponível'} · ${this._esc(this._relTime(m.atualizado_em))}</dd>
                ${a.dist_origem_m != null ? `<dt>→ Recolha</dt><dd>${a.dist_origem_m} m${a.near_origem ? ' · na zona' : ''}</dd>` : ''}
                ${a.dist_destino_m != null ? `<dt>→ Entrega</dt><dd>${a.dist_destino_m} m${a.near_destino ? ' · na zona' : ''}</dd>` : ''}
                ${a.desvio_rota ? `<dt>Desvio</dt><dd class="text-warn">~${a.dist_rota_m} m da rota</dd>` : ''}
            </dl>
            ${candHtml}
            <div class="ops-detail-actions">
                <a class="ops-btn primary" href="${this._esc(url)}"><i class="bi bi-box-arrow-up-right"></i> Abrir</a>
                ${tel ? `<a class="ops-btn" href="tel:${this._esc(tel)}"><i class="bi bi-telephone"></i> Ligar</a>` : ''}
                <a class="ops-btn" href="${this._esc(chatUrl)}"><i class="bi bi-chat-dots"></i> Chat</a>
                <a class="ops-btn" href="${this._esc(disputaUrl)}"><i class="bi bi-shield-exclamation"></i> Disputa</a>
                <button type="button" class="ops-btn" id="ops-watch">${watching ? '✓ A acompanhar' : '👁 Acompanhar'}</button>
                ${geoActions}
                <button type="button" class="ops-btn" id="ops-detail-close">Fechar</button>
            </div>
        `;

        document.getElementById('ops-detail-close')?.addEventListener('click', () => {
            this.selectedId = null;
            this.layerGeo.clearLayers();
            this._renderDetail();
            this._renderList();
        });
        document.getElementById('ops-watch')?.addEventListener('click', () => {
            this._toggleWatch(m.id);
            this._renderDetail();
            this._renderList();
        });
        this.els.detail.querySelectorAll('[data-geo]').forEach((btn) => {
            btn.addEventListener('click', () => this._aplicarGeofence(m.id, btn.dataset.geo));
        });
    }

    _toggleWatch(id) {
        if (this._watch.has(id)) this._watch.delete(id);
        else this._watch.add(id);
        localStorage.setItem('tm_ops_watch', JSON.stringify([...this._watch]));
        fetch(`${this.baseUrl}/api/ops-geofence-acao.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ missao_id: id, acao: 'acompanhar' }),
        }).catch(() => {});
    }

    async _aplicarGeofence(missaoId, acao) {
        try {
            const res = await fetch(`${this.baseUrl}/api/ops-geofence-acao.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ missao_id: missaoId, acao, aplicar: 1 }),
            });
            const d = await res.json();
            this._toast(d.message || (d.ok ? 'Actualizado' : 'Falha'));
            if (d.ok) this.carregar(true);
        } catch (e) {
            this._toast('Erro ao actualizar estado');
        }
    }

    _detectParadoWatchdog() {
        const limiarM = 80;
        const limiarSeg = (this.data.config && this.data.config.parado_seg) || 1200;
        (this.data.missoes || []).forEach((m) => {
            if (!m.lat || !m.lng) return;
            if (!['em_transito', 'em_recolha'].includes(m.estado_mapa)) {
                delete this._prevPos[m.id];
                return;
            }
            const prev = this._prevPos[m.id];
            const now = Date.now();
            if (!prev) {
                this._prevPos[m.id] = { lat: m.lat, lng: m.lng, since: now };
                return;
            }
            const dist = TrackMozMapCore.haversine(prev.lat, prev.lng, m.lat, m.lng);
            if (dist > limiarM) {
                this._prevPos[m.id] = { lat: m.lat, lng: m.lng, since: now };
                return;
            }
            if ((now - prev.since) / 1000 >= limiarSeg) {
                m.automacao = m.automacao || {};
                m.automacao.parado_longo = true;
                if (!m.automacao.alertas) m.automacao.alertas = [];
                if (!m.automacao.alertas.includes('parado_longo')) {
                    m.automacao.alertas.push('parado_longo');
                }
                const eid = 'parado-' + m.id;
                if (!this._seenEvents.has(eid)) {
                    this._pushLocalEvent({
                        id: eid,
                        tipo: 'parado_longo',
                        nivel: 'warn',
                        titulo: 'Paragem prolongada',
                        msg: (m.titulo || '#' + m.id) + ' · sem movimento',
                        missao_id: m.id,
                        quando: new Date().toISOString(),
                    });
                    this._markSeen(eid);
                }
            }
        });
    }

    _processAutomations(prevEmergIds) {
        // Novas emergências → som + banner + foco
        (this.data.emergencias || []).forEach((e) => {
            if (prevEmergIds.has(e.id)) return;
            const sid = 'emg-new-' + e.id;
            if (this._seenEvents.has(sid) && !this._firstLoad) return;
            if (!this._firstLoad) {
                this._sound(880);
                this._showBanner('🚨 Emergência: ' + (e.titulo || ''), 'danger', e.missao_id);
                if (e.missao_id) this.focusMissao(e.missao_id, { zoom: 13 });
            }
            this._markSeen(sid);
        });

        // Offline longo / atraso novos
        (this.data.missoes || []).forEach((m) => {
            const a = m.automacao || {};
            if (a.offline_longo) {
                const sid = 'off-esc-' + m.id;
                if (!this._seenEvents.has(sid)) {
                    if (!this._firstLoad) {
                        this._sound(440);
                        this._showBanner('📡 GPS offline prolongado: ' + (m.titulo || '#' + m.id), 'warn', m.id);
                    }
                    this._markSeen(sid);
                }
            }
            if (a.atraso) {
                const sid = 'atr-' + m.id;
                if (!this._seenEvents.has(sid)) {
                    if (!this._firstLoad) {
                        this._showBanner('⏰ Prazo ultrapassado: ' + (m.titulo || '#' + m.id), 'danger', m.id);
                    }
                    this._markSeen(sid);
                }
            }
            if (a.desvio_rota) {
                const sid = 'desv-' + m.id;
                if (!this._seenEvents.has(sid)) {
                    if (!this._firstLoad) {
                        this._toast('Desvio de rota: ' + (m.titulo || '#' + m.id));
                    }
                    this._markSeen(sid);
                }
            }
            if (a.near_destino || a.near_origem) {
                const sid = 'geo-' + m.id + (a.near_destino ? '-d' : '-o');
                if (!this._seenEvents.has(sid)) {
                    if (!this._firstLoad) {
                        this._toast(a.near_destino ? 'Na zona de entrega' : 'Na zona de recolha: ' + (m.titulo || ''));
                    }
                    this._markSeen(sid);
                }
            }
        });
    }

    _pushLocalEvent(ev) {
        if (!this.data.eventos) this.data.eventos = [];
        this.data.eventos.unshift(ev);
        this._renderFeed();
    }

    _markSeen(id) {
        this._seenEvents.add(id);
        const arr = [...this._seenEvents].slice(-80);
        sessionStorage.setItem('tm_ops_seen', JSON.stringify(arr));
    }

    _maybeHourlyResumo() {
        const h = new Date().getHours();
        if (h === this._lastResumoHour) return;
        if (this._lastResumoHour >= 0 && this.data.resumo) {
            this._toast('Resumo: ' + this.data.resumo);
            this._pushLocalEvent({
                id: 'resumo-' + Date.now(),
                tipo: 'resumo',
                nivel: 'ok',
                titulo: 'Resumo horário',
                msg: this.data.resumo,
                missao_id: null,
                quando: new Date().toISOString(),
            });
        }
        this._lastResumoHour = h;
    }

    _showBanner(text, nivel, missaoId) {
        if (!this.els.banner) return;
        this.els.banner.className = 'ops-banner show nivel-' + (nivel || 'warn');
        this.els.banner.innerHTML = `<span>${this._esc(text)}</span>
            <button type="button" class="ops-banner-x" aria-label="Fechar">&times;</button>`;
        this.els.banner.querySelector('.ops-banner-x')?.addEventListener('click', (e) => {
            e.stopPropagation();
            this.els.banner.classList.remove('show');
        });
        this.els.banner.onclick = () => {
            if (missaoId) this.focusMissao(missaoId);
            this.els.banner.classList.remove('show');
        };
        clearTimeout(this._bannerTimer);
        this._bannerTimer = setTimeout(() => this.els.banner.classList.remove('show'), 12000);
    }

    _toast(text) {
        if (!this.els.toast) return;
        this.els.toast.textContent = text;
        this.els.toast.classList.add('show');
        clearTimeout(this._toastTimer);
        this._toastTimer = setTimeout(() => this.els.toast.classList.remove('show'), 4000);
    }

    _sound(freq = 660) {
        if (!this._soundOn) return;
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!this._audioCtx) this._audioCtx = new Ctx();
            const ctx = this._audioCtx;
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            o.type = 'sine';
            o.frequency.value = freq;
            g.gain.value = 0.08;
            o.connect(g);
            g.connect(ctx.destination);
            o.start();
            g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
            o.stop(ctx.currentTime + 0.4);
        } catch (e) { /* ignore */ }
    }

    _relTime(dt) {
        if (!dt) return 'sem sinal';
        const ts = Date.parse(dt);
        if (Number.isNaN(ts)) return dt;
        const diff = Math.floor((Date.now() - ts) / 1000);
        if (diff < 60) return 'agora';
        if (diff < 3600) return `há ${Math.floor(diff / 60)} min`;
        if (diff < 86400) return `há ${Math.floor(diff / 3600)} h`;
        return `há ${Math.floor(diff / 86400)} d`;
    }

    _esc(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    _onRealtime(ev) {
        if (ev.type === 'gps_update' && ev.data?.mission_id) {
            const key = 'm' + ev.data.mission_id;
            if (this.markers[key] && ev.data.lat) {
                this.markers[key].setLatLng([ev.data.lat, ev.data.lng]);
            }
        }
        if (ev.type === 'emergencia' || ev.type === 'emergency') {
            this.carregar(true);
        }
    }

    iniciarAtualizacao(intervalMs = 8000) {
        if (this._destroyed) return;
        this._refreshMs = intervalMs;
        try { this.realtime.start(); } catch (e) { /* ignore */ }
        if (this._refreshTimer) clearInterval(this._refreshTimer);
        this._refreshTimer = setInterval(() => this.carregar(), this._refreshMs);
    }

    parar() {
        if (this._destroyed) return;
        this._destroyed = true;
        try { this.realtime.stop(); } catch (e) { /* ignore */ }
        if (this._refreshTimer) {
            clearInterval(this._refreshTimer);
            this._refreshTimer = null;
        }
        clearTimeout(this._bannerTimer);
        clearTimeout(this._toastTimer);
        if (this._abort) {
            try { this._abort.abort(); } catch (e) { /* ignore */ }
            this._abort = null;
        }
        window.removeEventListener('pagehide', this._pageLeaveBound);
        window.removeEventListener('beforeunload', this._pageLeaveBound);
        try {
            if (this.layerRotas) this.layerRotas.clearLayers();
            if (this.layerPontos) this.layerPontos.clearLayers();
            if (this.layerMarcadores) this.layerMarcadores.clearLayers();
            if (this.layerGeo) this.layerGeo.clearLayers();
            this.markers = {};
            if (this.provider && this.provider.map) {
                this.provider.map.remove();
                this.provider.map = null;
            }
        } catch (e) { /* ignore */ }
    }

    /**
     * Sai do centro: limpa timers/SSE/mapa e fecha a aba ou volta ao painel.
     */
    sair(homeUrl) {
        this.parar();
        const dest = homeUrl || (this.baseUrl + '/index.php');
        try { window.close(); } catch (e) { /* ignore */ }
        // Se o browser não permitir fechar (mesma aba), navegar de imediato.
        setTimeout(() => {
            try {
                location.replace(dest);
            } catch (e) {
                location.href = dest;
            }
        }, 80);
    }
};
