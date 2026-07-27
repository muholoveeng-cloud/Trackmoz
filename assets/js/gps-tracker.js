/**
 * TrackMoz GPS Tracker — transmissão com fila offline persistente (IndexedDB).
 */
window.TrackMozGpsTracker = class {
    constructor(options = {}) {
        this.baseUrl = options.baseUrl || '';
        this.missaoId = options.missaoId || null;
        this.vehicleId = options.vehicleId || null;
        this.intervalMs = options.intervalMs || 5000;
        this.onPosition = options.onPosition || (() => {});
        this.onError = options.onError || (() => {});
        this.onCheckpoint = options.onCheckpoint || (() => {});
        this.onOffline = options.onOffline || (() => {});
        this.onOnline = options.onOnline || (() => {});

        this.watchId = null;
        this.timerId = null;
        this.lastPos = null;
        this.gpsLostAt = null;
        this.isOnline = navigator.onLine;

        window.addEventListener('online', () => {
            this.isOnline = true;
            this.onOnline();
            if (window.TrackMozOffline) TrackMozOffline.flush();
        });
        window.addEventListener('offline', () => {
            this.isOnline = false;
        });
    }

    start() {
        if (!navigator.geolocation) {
            this.onError(new Error('Geolocalização não suportada'));
            return;
        }
        this.watchId = navigator.geolocation.watchPosition(
            (pos) => this._onGps(pos),
            (err) => this.onError(err),
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 3000 }
        );
        this.timerId = setInterval(() => this._tick(), this.intervalMs);
    }

    stop() {
        if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId);
        if (this.timerId) clearInterval(this.timerId);
        this.watchId = null;
        this.timerId = null;
    }

    _onGps(pos) {
        this.lastPos = {
            lat: +pos.coords.latitude.toFixed(6),
            lng: +pos.coords.longitude.toFixed(6),
            speed: pos.coords.speed != null ? +(pos.coords.speed * 3.6).toFixed(1) : null,
            heading: pos.coords.heading != null ? +pos.coords.heading.toFixed(1) : null,
            accuracy: pos.coords.accuracy != null ? +pos.coords.accuracy.toFixed(1) : null,
            ts: Date.now(),
        };
        this.gpsLostAt = null;
        this.onPosition(this.lastPos);
    }

    _tick() {
        if (!this.lastPos) {
            if (!this.gpsLostAt) this.gpsLostAt = Date.now();
            if (this.gpsLostAt && Date.now() - this.gpsLostAt > 60000) {
                this.onOffline({ segundos: Math.round((Date.now() - this.gpsLostAt) / 1000) });
            }
            return;
        }
        this._send(this.lastPos);
    }

    _payload(pos, withOpId) {
        const payload = {
            latitude: pos.lat,
            longitude: pos.lng,
            speed: pos.speed,
            heading: pos.heading,
            accuracy: pos.accuracy,
        };
        if (this.missaoId) payload.missao_id = this.missaoId;
        if (this.vehicleId) payload.vehicle_id = this.vehicleId;
        if (withOpId && window.TrackMozOffline) {
            payload.client_op_id = TrackMozOffline.uuid();
        }
        return payload;
    }

    async _enqueueGps(pos) {
        if (!window.TrackMozOffline) return;
        const url = `${this.baseUrl}/api/update-localizacao.php`;
        await TrackMozOffline.enqueue({
            type: 'gps',
            url: url,
            body: this._payload(pos, true),
            meta: { missaoId: this.missaoId },
        });
    }

    async _send(pos) {
        const url = `${this.baseUrl}/api/update-localizacao.php`;

        if (!navigator.onLine) {
            this.isOnline = false;
            await this._enqueueGps(pos);
            return;
        }

        try {
            const payload = this._payload(pos, false);
            const form = new FormData();
            Object.entries(payload).forEach(([k, v]) => {
                if (v != null) form.append(k, v);
            });
            const res = await fetch(url, { method: 'POST', body: form, credentials: 'same-origin' });
            const data = await res.json();
            if (data.checkpoint) this.onCheckpoint(data.checkpoint);
        } catch (e) {
            await this._enqueueGps(pos);
        }
    }
};
