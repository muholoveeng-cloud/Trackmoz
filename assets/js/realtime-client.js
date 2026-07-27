/**
 * TrackMoz Realtime — SSE com fallback para polling.
 * Socket.IO opcional via realtime/socket-server.js
 */
window.TrackMozRealtime = class {
    constructor(options = {}) {
        this.baseUrl = options.baseUrl || '';
        this.missionId = options.missionId || null;
        this.onEvent = options.onEvent || (() => {});
        this.pollInterval = options.pollInterval || 5000;
        this.lastEventId = 0;

        this._source = null;
        this._pollTimer = null;
        this._socket = null;
    }

    start() {
        if (typeof io !== 'undefined' && window.TMZ_SOCKET_URL) {
            this._startSocket();
        } else if (typeof EventSource !== 'undefined') {
            this._startSSE();
        } else {
            this._startPolling();
        }
    }

    stop() {
        if (this._source) { this._source.close(); this._source = null; }
        if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
        if (this._socket) { this._socket.disconnect(); this._socket = null; }
    }

    _startSocket() {
        this._socket = io(window.TMZ_SOCKET_URL, { transports: ['websocket', 'polling'] });
        this._socket.on('gps_update', (data) => this.onEvent({ type: 'gps_update', data }));
        this._socket.on('checkpoint', (data) => this.onEvent({ type: 'checkpoint', data }));
    }

    _startSSE() {
        const connect = () => {
            let url = `${this.baseUrl}/api/realtime-stream.php?last_id=${this.lastEventId}`;
            if (this.missionId) url += `&mission_id=${this.missionId}`;
            this._source = new EventSource(url);
            this._source.onmessage = (ev) => {
                try {
                    const data = JSON.parse(ev.data);
                    if (data.reconnect) {
                        this.lastEventId = data.last_id || this.lastEventId;
                        this._source.close();
                        setTimeout(connect, 500);
                        return;
                    }
                    if (data.id) this.lastEventId = data.id;
                    this.onEvent(data);
                } catch (e) { /* ignore */ }
            };
            this._source.onerror = () => {
                this._source.close();
                this._startPolling();
            };
        };
        connect();
    }

    _startPolling() {
        const poll = async () => {
            if (!this.missionId) return;
            try {
                const res = await fetch(
                    `${this.baseUrl}/api/get-localizacao.php?missao_id=${this.missionId}`
                );
                const data = await res.json();
                if (data.ok) {
                    this.onEvent({ type: 'poll', data });
                }
            } catch (e) { /* ignore */ }
        };
        poll();
        this._pollTimer = setInterval(poll, this.pollInterval);
    }
};
