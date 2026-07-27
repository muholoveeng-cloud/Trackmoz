/**
 * Seleccionar origem e destino — Nominatim + clique no mapa + OSRM via API
 */
window.MapaSeletorMissao = class {
    constructor(containerId, options = {}) {
        this.baseUrl = options.baseUrl || '';
        this.onUpdate = options.onUpdate || (() => {});
        this.onRotaInfo = options.onRotaInfo || (() => {});
        this.onGeoError = options.onGeoError || (() => {});

        const Core = window.TrackMozMapCore;
        this.provider = new Core.LeafletProvider(containerId, { zoomControl: true });
        this.map = this.provider.map;

        this.marcadores = { origem: null, destino: null };
        this.dados = { origem: null, destino: null };
        this.rotaLayer = null;
        this.rotaInfo = { km: 0, min: 0 };
        this.modo = 'origem';
        this._searchTimer = null;

        this.provider.onClick((lat, lng) => this._cliqueMapa(lat, lng));
    }

    setModo(modo) {
        this.modo = modo === 'destino' ? 'destino' : 'origem';
    }

    _icone(tipo) {
        const cfg = TrackMozMapCore.ICONES[tipo] || TrackMozMapCore.ICONES.origem;
        return TrackMozMapCore.divIcon(cfg.cor);
    }

    async _cliqueMapa(lat, lng) {
        const rev = await TrackMozMapCore.reverseGeocode(this.baseUrl, lat, lng);
        const info = {
            lat: +lat.toFixed(6),
            lng: +lng.toFixed(6),
            nome: rev?.nome || 'Local no mapa',
            endereco: rev?.endereco || `${lat.toFixed(5)}, ${lng.toFixed(5)}`,
        };
        this._colocarMarcador(this.modo, info, true);
    }

    _colocarMarcador(modo, info, draggable = false) {
        if (this.marcadores[modo]) {
            this.map.removeLayer(this.marcadores[modo]);
        }
        const label = modo === 'origem' ? 'Recolha' : 'Entrega';
        this.marcadores[modo] = L.marker([info.lat, info.lng], {
            icon: this._icone(modo),
            draggable,
        }).addTo(this.map).bindPopup(`<strong>${label}</strong><br>${info.endereco}`);

        if (draggable) {
            this.marcadores[modo].on('dragend', async (e) => {
                const ll = e.target.getLatLng();
                const rev = await TrackMozMapCore.reverseGeocode(this.baseUrl, ll.lat, ll.lng);
                info.lat = +ll.lat.toFixed(6);
                info.lng = +ll.lng.toFixed(6);
                info.endereco = rev?.endereco || info.endereco;
                info.nome = rev?.nome || info.nome;
                this.dados[modo] = info;
                this.onUpdate(modo, info);
                this._recalcularRota();
            });
        }

        this.dados[modo] = info;
        this.onUpdate(modo, info);
        this._recalcularRota();
    }

    setOrigem(lat, lng, nome = '', endereco = '') {
        if (lat == null || lng == null) return;
        this.modo = 'origem';
        this._colocarMarcador('origem', {
            lat: +lat, lng: +lng,
            nome: nome || 'Origem',
            endereco: endereco || nome || 'Origem',
        }, true);
        this.map.setView([lat, lng], 14);
    }

    setDestino(lat, lng, nome = '', endereco = '') {
        if (lat == null || lng == null) return;
        this.modo = 'destino';
        this._colocarMarcador('destino', {
            lat: +lat, lng: +lng,
            nome: nome || 'Destino',
            endereco: endereco || nome || 'Destino',
        }, true);
        this.map.setView([lat, lng], 14);
    }

    async pesquisar(texto, modo = null) {
        const m = modo || this.modo;
        const sugestoes = await TrackMozMapCore.pesquisarLocais(this.baseUrl, texto);
        return sugestoes;
    }

    async selecionarSugestao(sugestao, modo = null) {
        const m = modo || this.modo;
        this.setModo(m);
        if (m === 'origem') {
            this.setOrigem(sugestao.lat, sugestao.lng, sugestao.nome, sugestao.endereco);
        } else {
            this.setDestino(sugestao.lat, sugestao.lng, sugestao.nome, sugestao.endereco);
        }
        return sugestao;
    }

    async geocodificar(texto, modo = 'destino') {
        const sugestoes = await this.pesquisar(texto, modo);
        if (sugestoes.length) {
            return this.selecionarSugestao(sugestoes[0], modo);
        }
        return null;
    }

    async geocodificarDestino(texto) {
        return this.geocodificar(texto, 'destino');
    }

    async _recalcularRota() {
        const o = this.dados.origem;
        const d = this.dados.destino;
        if (!o || !d) return;

        if (this.rotaLayer) {
            this.map.removeLayer(this.rotaLayer);
            this.rotaLayer = null;
        }

        const rota = await TrackMozMapCore.calcularRota(
            this.baseUrl, o.lat, o.lng, d.lat, d.lng
        );

        if (rota && rota.coordinates.length) {
            this.rotaLayer = L.polyline(rota.coordinates, {
                color: '#2563eb', weight: 4, opacity: 0.8,
            }).addTo(this.map);
            this.provider.fitBounds(rota.coordinates);
            this.rotaInfo = { km: rota.distancia_km, min: rota.tempo_min };
            this.onRotaInfo(this.rotaInfo);
            return;
        }

        this.rotaLayer = L.polyline([[o.lat, o.lng], [d.lat, d.lng]], {
            color: '#2563eb', weight: 2, dashArray: '6,5', opacity: 0.5,
        }).addTo(this.map);
        this.provider.fitBounds([[o.lat, o.lng], [d.lat, d.lng]]);
        this.onRotaInfo({ km: 0, min: 0, aviso: 'Rota aproximada' });
    }

    usarLocalizacaoAtual() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('Navegador não suporta geolocalização'));
                return;
            }
            navigator.geolocation.getCurrentPosition(
                async (pos) => {
                    const lat = +pos.coords.latitude.toFixed(6);
                    const lng = +pos.coords.longitude.toFixed(6);
                    const rev = await TrackMozMapCore.reverseGeocode(this.baseUrl, lat, lng);
                    this.setOrigem(lat, lng, rev?.nome, rev?.endereco);
                    resolve({ lat, lng, accuracy: pos.coords.accuracy });
                },
                (err) => {
                    const msgs = { 1: 'Permissão negada.', 2: 'Localização indisponível.', 3: 'Tempo esgotado.' };
                    reject(new Error(msgs[err.code] || 'Erro GPS'));
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 }
            );
        });
    }

    limpar() {
        ['origem', 'destino'].forEach((k) => {
            if (this.marcadores[k]) {
                this.map.removeLayer(this.marcadores[k]);
                this.marcadores[k] = null;
                this.dados[k] = null;
            }
        });
        if (this.rotaLayer) {
            this.map.removeLayer(this.rotaLayer);
            this.rotaLayer = null;
        }
        this.rotaInfo = { km: 0, min: 0 };
        this.onRotaInfo(this.rotaInfo);
    }
};
