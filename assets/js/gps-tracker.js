/**
 * TrackMoz GPS Tracker — transmissão a cada 5s com fila offline.
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
        this.pendingQueue = [];
        this.isOnline = navigator.onLine;
        this.gpsLostAt = null;

        window.addEventListener('online', () => this._handleOnline());
        window.addEventListener('offline', () => { this.isOnline = false; });
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
        if (this.gpsLostAt) {
            this.gpsLostAt = null;
        }
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

    async _send(pos) {
        const payload = {
            latitude: pos.lat,
            longitude: pos.lng,
            speed: pos.speed,
            heading: pos.heading,
            accuracy: pos.accuracy,
        };
        if (this.missaoId) payload.missao_id = this.missaoId;
        if (this.vehicleId) payload.vehicle_id = this.vehicleId;

        if (!navigator.onLine) {
            this.pendingQueue.push(payload);
            this.isOnline = false;
            return;
        }

        try {
            const form = new FormData();
            Object.entries(payload).forEach(([k, v]) => {
                if (v != null) form.append(k, v);
            });
            const res = await fetch(`${this.baseUrl}/api/update-localizacao.php`, {
                method: 'POST', body: form,
            });
            const data = await res.json();
            if (data.checkpoint) this.onCheckpoint(data.checkpoint);
        } catch (e) {
            this.pendingQueue.push(payload);
        }
    }

    async _handleOnline() {
        this.isOnline = true;
        this.onOnline();
        const queue = [...this.pendingQueue];
        this.pendingQueue = [];
        for (const payload of queue) {
            try {
                const form = new FormData();
                Object.entries(payload).forEach(([k, v]) => {
                    if (v != null) form.append(k, v);
                });
                await fetch(`${this.baseUrl}/api/update-localizacao.php`, { method: 'POST', body: form });
            } catch (e) {
                this.pendingQueue.push(payload);
            }
        }
    }
};
