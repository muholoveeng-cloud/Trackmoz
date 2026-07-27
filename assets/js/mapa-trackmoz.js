/**
 * TrackMoz — mapa Leaflet dinâmico (missões, rotas, GPS motoristas)
 */
const TrackMozMap = (function () {
    'use strict';

    const iconeOrigem = L.divIcon({
        html: '<div style="background:#28a745;width:12px;height:12px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.4)"></div>',
        className: '', iconAnchor: [6, 6],
    });
    const iconeDestino = L.divIcon({
        html: '<div style="background:#dc3545;width:12px;height:12px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.4)"></div>',
        className: '', iconAnchor: [6, 6],
    });

    function iconeTruck(status, disponivel) {
        const bg = status === 'emergencia' ? '#dc3545'
            : (status === 'em_transito' || status === 'em_entrega') ? '#0d6efd'
            : disponivel ? '#198754' : '#fd7e14';
        return L.divIcon({
            html: `<div style="background:${bg};border-radius:50%;width:34px;height:34px;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 2px 5px rgba(0,0,0,.35);font-size:17px">🚛</div>`,
            className: '', iconAnchor: [17, 17],
        });
    }

    function iconeMotoristaLivre() {
        return L.divIcon({
            html: '<div style="background:#6f42c1;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 2px 5px rgba(0,0,0,.35);font-size:14px">👤</div>',
            className: '', iconAnchor: [14, 14],
        });
    }

    return class {
        constructor(containerId, options = {}) {
            this.baseUrl = options.baseUrl || '';
            this.map = L.map(containerId).setView([-18.0, 35.0], 6);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(this.map);

            this.layers = {
                rotas: L.layerGroup().addTo(this.map),
                origens: L.layerGroup().addTo(this.map),
                destinos: L.layerGroup().addTo(this.map),
                camioes: L.layerGroup().addTo(this.map),
                motoristas: L.layerGroup().addTo(this.map),
            };

            this.markers = {};
            this.motoristaMarkers = {};
            this.missoes = [];
            this.selectedId = null;
            this.onSelect = options.onSelect || null;
            this.rastrearUrl = options.rastrearUrl || null;

            setTimeout(() => this.map.invalidateSize(), 200);
            window.addEventListener('resize', () => this.map.invalidateSize());
        }

        limparCamadas() {
            Object.values(this.layers).forEach((g) => g.clearLayers());
            this.markers = {};
            this.motoristaMarkers = {};
        }

        async renderMissao(m) {
            const bounds = [];
            const oLat = parseFloat(m.origem_lat);
            const oLng = parseFloat(m.origem_lng);
            const dLat = parseFloat(m.destino_lat);
            const dLng = parseFloat(m.destino_lng);
            const gLat = parseFloat(m.lat);
            const gLng = parseFloat(m.lng);

            if (!isNaN(oLat) && !isNaN(oLng)) {
                L.marker([oLat, oLng], { icon: iconeOrigem })
                    .addTo(this.layers.origens)
                    .bindPopup(`<strong>Origem</strong><br>${m.origem || ''}`);
                bounds.push([oLat, oLng]);
            }

            if (!isNaN(dLat) && !isNaN(dLng)) {
                L.marker([dLat, dLng], { icon: iconeDestino })
                    .addTo(this.layers.destinos)
                    .bindPopup(`<strong>Destino</strong><br>${m.destino || ''}`);
                bounds.push([dLat, dLng]);
            }

            if (!isNaN(oLat) && !isNaN(dLat) && window.TrackMozMapCore) {
                const rota = await TrackMozMapCore.calcularRota(this.baseUrl, oLat, oLng, dLat, dLng);
                if (rota?.coordinates?.length) {
                    L.polyline(rota.coordinates, { color: '#0d6efd', weight: 3, opacity: 0.5 })
                        .addTo(this.layers.rotas);
                } else {
                    L.polyline([[oLat, oLng], [dLat, dLng]], {
                        color: '#0d6efd', weight: 2, opacity: 0.35, dashArray: '6,5',
                    }).addTo(this.layers.rotas);
                }
            }

            if (!isNaN(gLat) && !isNaN(gLng)) {
                const linkRastrear = this.rastrearUrl
                    ? `<br><a href="${this.rastrearUrl}?id=${m.id}">Rastrear</a>` : '';
                const mk = L.marker([gLat, gLng], {
                    icon: iconeTruck(m.status),
                    zIndexOffset: m.status === 'emergencia' ? 500 : 100,
                }).addTo(this.layers.camioes).bindPopup(
                    `<strong>${m.titulo}</strong><br>
                     <small>${m.nome_empresa || ''}</small><br>
                     Motorista: ${m.motorista_nome || '—'}<br>
                     ${m.origem} → ${m.destino}${linkRastrear}`
                );
                mk.on('click', () => this.selecionar(m.id));
                this.markers[m.id] = mk;
                bounds.push([gLat, gLng]);
            }

            return bounds;
        }

        async reloadAll(scope) {
            const res = await fetch(`${this.baseUrl}/api/get-mapa-dados.php?scope=${scope}`);
            const data = await res.json();
            if (!data.ok) return data;
            this.renderMissoes(data.missoes, data.motoristas);
            return data;
        }

        renderMotorista(mot) {
            if (this.motoristaMarkers[mot.id]) {
                this.motoristaMarkers[mot.id].setLatLng([mot.lat, mot.lng]);
                return [mot.lat, mot.lng];
            }
            const mk = L.marker([mot.lat, mot.lng], { icon: iconeMotoristaLivre() })
                .addTo(this.layers.motoristas)
                .bindPopup(`<strong>${mot.nome}</strong><br><small>Motorista disponível</small>`);
            this.motoristaMarkers[mot.id] = mk;
            return [mot.lat, mot.lng];
        }

        renderMissoes(missoes, motoristas = []) {
            this.limparCamadas();
            this.missoes = missoes;
            const allBounds = [];

            missoes.forEach((m) => {
                this.renderMissao(m).then((b) => { if (b) b.forEach((x) => allBounds.push(x)); });
            });

            const idsComMissao = new Set(
                missoes.filter((m) => m.caminhoneiro_id).map((m) => m.caminhoneiro_id)
            );

            motoristas.forEach((mot) => {
                if (idsComMissao.has(mot.id)) return;
                const b = this.renderMotorista(mot);
                if (b) allBounds.push(b);
            });

            if (allBounds.length) {
                this.map.fitBounds(allBounds, { padding: [40, 40] });
            } else {
                this.map.setView([-18.0, 35.0], 6);
            }
        }

        selecionar(id) {
            if (this.selectedId && this.onSelect) {
                this.onSelect(this.selectedId, false);
            }
            this.selectedId = id;
            if (this.onSelect) this.onSelect(id, true);
            this.focarMissao(id);
        }

        focarMissao(id) {
            const m = this.missoes.find((x) => x.id == id);
            if (!m) return;
            const pos = (m.lat && m.lng)
                ? [m.lat, m.lng]
                : (m.origem_lat ? [m.origem_lat, m.origem_lng] : null);
            if (pos) this.map.setView(pos, 11);
            if (this.markers[id]) this.markers[id].openPopup();
        }

        actualizarMissao(m) {
            const idx = this.missoes.findIndex((x) => x.id === m.id);
            if (idx >= 0) this.missoes[idx] = { ...this.missoes[idx], ...m };

            if (m.lat && m.lng && this.markers[m.id]) {
                this.markers[m.id].setLatLng([m.lat, m.lng]);
            } else if (m.lat && m.lng && !this.markers[m.id]) {
                this.renderMissao(this.missoes[idx] || m);
            }
        }

        async refreshFromApi(scope) {
            const res = await fetch(`${this.baseUrl}/api/get-mapa-dados.php?scope=${scope}`);
            const data = await res.json();
            if (!data.ok) return data;

            data.missoes.forEach((m) => this.actualizarMissao(m));

            data.motoristas.forEach((mot) => {
                if (this.motoristaMarkers[mot.id]) {
                    this.motoristaMarkers[mot.id].setLatLng([mot.lat, mot.lng]);
                }
            });

            return data;
        }

        iniciarPolling(scope, intervalMs = 8000) {
            this.refreshTimer = setInterval(() => this.refreshFromApi(scope), intervalMs);
        }
    };
})();
