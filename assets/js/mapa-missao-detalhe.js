/**
 * Mapa de detalhe da missao: recolha (origem), entrega (destino), rota OSRM, GPS motorista.
 * Nunca assume Maputo como padrao.
 */
window.MapaMissaoDetalhe = class {
    constructor(containerId, opts = {}) {
        this.containerId = containerId;
        this.baseUrl     = opts.baseUrl || '';
        this.recolhaTxt  = opts.recolhaTxt || opts.origemTxt || '';
        this.entregaTxt  = opts.entregaTxt || opts.destinoTxt || '';
        this.missaoId    = opts.missaoId || null;
        this.altura      = opts.altura || '280px';

        this.recolhaLat  = opts.recolhaLat != null ? parseFloat(opts.recolhaLat) : (opts.origemLat != null ? parseFloat(opts.origemLat) : null);
        this.recolhaLng  = opts.recolhaLng != null ? parseFloat(opts.recolhaLng) : (opts.origemLng != null ? parseFloat(opts.origemLng) : null);
        this.entregaLat  = opts.entregaLat != null ? parseFloat(opts.entregaLat) : (opts.destinoLat != null ? parseFloat(opts.destinoLat) : null);
        this.entregaLng  = opts.entregaLng != null ? parseFloat(opts.entregaLng) : (opts.destinoLng != null ? parseFloat(opts.destinoLng) : null);
        this.condutorLat = opts.condutorLat != null ? parseFloat(opts.condutorLat) : null;
        this.condutorLng = opts.condutorLng != null ? parseFloat(opts.condutorLng) : null;

        this.infoEl      = document.getElementById(opts.infoId || '');
        this.map         = null;
        this.layers      = { rota: null, truck: null };
        this.markers     = {};
    }

    async init() {
        const el = document.getElementById(this.containerId);
        if (!el) return;
        el.style.height = this.altura;

        await this._resolverCoordenadas();

        this.map = L.map(this.containerId).setView([-18.0, 35.0], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19,
        }).addTo(this.map);

        const bounds = [];
        const dot = (cor, tamanho = 12) => L.divIcon({
            html: `<div style="width:${tamanho}px;height:${tamanho}px;border-radius:50%;background:${cor};border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.35)"></div>`,
            className: '', iconAnchor: [tamanho / 2, tamanho / 2],
        });
        const pin = (cor, label) => L.divIcon({
            html: `<div style="display:flex;flex-direction:column;align-items:center;gap:2px"><div style="width:14px;height:14px;border-radius:50%;background:${cor};border:2px solid #fff;box-shadow:0 2px 5px rgba(0,0,0,.35)"></div><span style="font-size:10px;background:rgba(0,0,0,.7);color:#fff;padding:1px 4px;border-radius:3px;white-space:nowrap">${label}</span></div>`,
            className: '', iconAnchor: [7, 28],
        });

        if (this.recolhaLat != null && this.recolhaLng != null) {
            this.markers.recolha = L.marker([this.recolhaLat, this.recolhaLng], { icon: pin('#22c55e', 'Recolha') })
                .addTo(this.map).bindPopup('<strong>Recolha da carga</strong><br>' + this.recolhaTxt);
            bounds.push([this.recolhaLat, this.recolhaLng]);
        }
        if (this.entregaLat != null && this.entregaLng != null) {
            this.markers.entrega = L.marker([this.entregaLat, this.entregaLng], { icon: pin('#ef4444', 'Entrega') })
                .addTo(this.map).bindPopup('<strong>Entrega</strong><br>' + this.entregaTxt);
            bounds.push([this.entregaLat, this.entregaLng]);
        }
        if (this.condutorLat != null && this.condutorLng != null) {
            this._adicionarMarcadorCondutor(this.condutorLat, this.condutorLng);
            bounds.push([this.condutorLat, this.condutorLng]);
        }

        if (bounds.length) {
            await this._desenharRotaOsrm();
            this.map.fitBounds(bounds.length > 1 ? L.latLngBounds(bounds) : bounds[0], { padding: [40, 40], maxZoom: 12 });
        } else if (this.infoEl) {
            this.infoEl.textContent = 'Sem coordenadas disponiveis para exibir no mapa.';
            this.infoEl.classList.remove('d-none');
        }

        if (this.missaoId && this.condutorLat == null) {
            this._pollGps();
            setInterval(() => this._pollGps(), 5000);
        }

        setTimeout(() => this.map.invalidateSize(), 250);
    }

    async _resolverCoordenadas() {
        if (this.recolhaLat == null && this.recolhaTxt) {
            const c = await this._geocode(this.recolhaTxt);
            if (c) { this.recolhaLat = c.lat; this.recolhaLng = c.lng; }
        }
        if (this.entregaLat == null && this.entregaTxt) {
            const c = await this._geocode(this.entregaTxt);
            if (c) { this.entregaLat = c.lat; this.entregaLng = c.lng; }
        }
    }

    async _geocode(q) {
        try {
            const r = await fetch(this.baseUrl + '/api/geocode.php?q=' + encodeURIComponent(q));
            const d = await r.json();
            return d.ok ? d : null;
        } catch (e) { return null; }
    }

    async _desenharRotaOsrm() {
        if (this.recolhaLat == null || this.entregaLat == null) return;
        const url = `https://router.project-osrm.org/route/v1/driving/${this.recolhaLng},${this.recolhaLat};${this.entregaLng},${this.entregaLat}?overview=full&geometries=geojson`;
        try {
            const data = await fetch(url).then((r) => r.json());
            if (data.routes && data.routes[0]) {
                const coords = data.routes[0].geometry.coordinates.map((c) => [c[1], c[0]]);
                if (this.layers.rota) this.map.removeLayer(this.layers.rota);
                this.layers.rota = L.polyline(coords, { color: '#2563eb', weight: 4, opacity: 0.75 }).addTo(this.map);
                const km  = +(data.routes[0].distance / 1000).toFixed(1);
                const min = Math.round(data.routes[0].duration / 60);
                if (this.infoEl) {
                    this.infoEl.innerHTML = `<i class="bi bi-signpost-split me-1"></i>Rota por estrada: <strong>~${km} km</strong> · ~${min} min`;
                    this.infoEl.classList.remove('d-none');
                }
                return;
            }
        } catch (e) { /* fallback */ }
        if (this.layers.rota) this.map.removeLayer(this.layers.rota);
        this.layers.rota = L.polyline(
            [[this.recolhaLat, this.recolhaLng], [this.entregaLat, this.entregaLng]],
            { color: '#2563eb', weight: 2, dashArray: '6,5', opacity: 0.5 }
        ).addTo(this.map);
        if (this.infoEl) {
            this.infoEl.textContent = 'Rota aproximada (servico de estradas indisponivel)';
            this.infoEl.classList.remove('d-none');
        }
    }

    _adicionarMarcadorCondutor(lat, lng) {
        const truckIcon = L.divIcon({
            html: '<div style="font-size:22px">🚛</div>',
            className: '', iconAnchor: [11, 11],
        });
        if (this.layers.truck) {
            this.layers.truck.setLatLng([lat, lng]);
        } else {
            this.layers.truck = L.marker([lat, lng], { icon: truckIcon, zIndexOffset: 500 })
                .addTo(this.map).bindPopup('<strong>Condutor</strong><br>Localizacao actual');
        }
    }

    async _pollGps() {
        try {
            const r = await fetch(this.baseUrl + '/api/get-localizacao.php?missao_id=' + this.missaoId);
            const d = await r.json();
            if (!d.ok || !d.localizacao) return;
            const { lat, lng } = d.localizacao;
            this._adicionarMarcadorCondutor(lat, lng);
        } catch (e) { /* silencioso */ }
    }
};
