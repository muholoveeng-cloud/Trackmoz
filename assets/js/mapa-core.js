/**
 * TrackMoz Map Core — módulo base reutilizável (Leaflet + OSM + OSRM + Nominatim)
 * Arquitectura modular: substituir MapProvider para Google Maps no futuro.
 */
window.TrackMozMapCore = (function () {
    'use strict';

    const OSM_TILE = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    const MZ_CENTER = [-18.0, 35.0];
    const MZ_ZOOM = 6;

    const ICONES = {
        origem:   { cor: '#22c55e', label: 'Recolha' },
        destino:  { cor: '#ef4444', label: 'Entrega' },
        truck:    { cor: '#2563eb', emoji: '🚛' },
        em_transito:  { cor: '#22c55e', emoji: '🚛' },
        em_recolha:   { cor: '#3b82f6', emoji: '🚛' },
        parado:       { cor: '#f97316', emoji: '🚛' },
        emergencia:   { cor: '#ef4444', emoji: '🚨' },
        offline:      { cor: '#6b7280', emoji: '🚛' },
    };

    function divIcon(cor, size = 16, emoji = null) {
        const html = emoji
            ? `<div style="background:${cor};border-radius:50%;width:${size}px;height:${size}px;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);font-size:${size * 0.5}px">${emoji}</div>`
            : `<div style="background:${cor};width:${size}px;height:${size}px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4)"></div>`;
        return L.divIcon({ html, className: '', iconAnchor: [size / 2, size / 2], popupAnchor: [0, -size / 2] });
    }

    class LeafletProvider {
        constructor(containerId, options = {}) {
            this.baseUrl = options.baseUrl || '';
            this.map = L.map(containerId, {
                zoomControl: options.zoomControl !== false,
                attributionControl: true,
            }).setView(options.center || MZ_CENTER, options.zoom || MZ_ZOOM);

            L.tileLayer(OSM_TILE, {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(this.map);

            this.layers = {};
            setTimeout(() => this.map.invalidateSize(), 250);
            if (options.fullscreen) {
                window.addEventListener('resize', () => this.map.invalidateSize());
            }
        }

        createLayerGroup(name) {
            this.layers[name] = L.layerGroup().addTo(this.map);
            return this.layers[name];
        }

        addMarker(lat, lng, tipo = 'origem', popup = '') {
            const cfg = ICONES[tipo] || ICONES.origem;
            const icon = cfg.emoji
                ? divIcon(cfg.cor, 34, cfg.emoji)
                : divIcon(cfg.cor);
            const m = L.marker([lat, lng], { icon }).addTo(this.map);
            if (popup) m.bindPopup(popup);
            return m;
        }

        drawPolyline(coords, opts = {}) {
            return L.polyline(coords, {
                color: opts.color || '#2563eb',
                weight: opts.weight || 4,
                opacity: opts.opacity || 0.8,
                dashArray: opts.dashArray || null,
            }).addTo(this.map);
        }

        fitBounds(bounds, padding = [50, 50]) {
            if (bounds && bounds.length) {
                this.map.fitBounds(bounds, { padding });
            }
        }

        setView(lat, lng, zoom = 14) {
            this.map.setView([lat, lng], zoom);
        }

        onClick(cb) {
            this.map.on('click', (e) => cb(e.latlng.lat, e.latlng.lng));
        }
    }

    async function pesquisarLocais(baseUrl, query, limit = 6) {
        const res = await fetch(
            `${baseUrl}/api/nominatim-search.php?q=${encodeURIComponent(query)}&limit=${limit}`
        );
        const data = await res.json();
        return data.ok ? data.sugestoes : [];
    }

    async function reverseGeocode(baseUrl, lat, lng) {
        const res = await fetch(
            `${baseUrl}/api/reverse-geocode.php?lat=${lat}&lng=${lng}`
        );
        const data = await res.json();
        return data.ok ? data : null;
    }

    async function calcularRota(baseUrl, fromLat, fromLng, toLat, toLng) {
        const url = `${baseUrl}/api/route.php?from_lat=${fromLat}&from_lng=${fromLng}&to_lat=${toLat}&to_lng=${toLng}`;
        const res = await fetch(url);
        const data = await res.json();
        if (!data.ok) return null;

        const coords = (data.coordinates || []).map((c) => [c[1] ?? c.lat, c[0] ?? c.lng]);
        return {
            distancia_km: data.distancia_km,
            tempo_min: data.tempo_min,
            coordinates: coords,
            fallback: data.fallback,
            aviso: data.aviso,
        };
    }

    function haversine(lat1, lng1, lat2, lng2) {
        const R = 6371000;
        const toR = (d) => d * Math.PI / 180;
        const dLat = toR(lat2 - lat1);
        const dLng = toR(lng2 - lng1);
        const a = Math.sin(dLat / 2) ** 2
            + Math.cos(toR(lat1)) * Math.cos(toR(lat2)) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    return {
        LeafletProvider,
        ICONES,
        divIcon,
        pesquisarLocais,
        reverseGeocode,
        calcularRota,
        haversine,
        MZ_CENTER,
        MZ_ZOOM,
        OSM_TILE,
    };
})();
