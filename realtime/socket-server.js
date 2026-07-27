/**
 * TrackMoz — Servidor Socket.IO opcional (gratuito, open source)
 *
 * Iniciar: cd realtime && npm install && npm start
 * Porta padrão: 3001
 *
 * No frontend, definir: window.TMZ_SOCKET_URL = 'http://localhost:3001';
 */
const http = require('http');
const { Server } = require('socket.io');

const PORT = process.env.TMZ_SOCKET_PORT || 3001;

const server = http.createServer((req, res) => {
    if (req.method === 'POST' && req.url === '/broadcast') {
        let body = '';
        req.on('data', (chunk) => { body += chunk; });
        req.on('end', () => {
            try {
                const data = JSON.parse(body);
                io.emit(data.event || 'gps_update', data.payload || data);
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true }));
            } catch (e) {
                res.writeHead(400);
                res.end(JSON.stringify({ ok: false }));
            }
        });
        return;
    }
    res.writeHead(200);
    res.end('TrackMoz Realtime Server');
});

const io = new Server(server, {
    cors: { origin: '*', methods: ['GET', 'POST'] },
});

io.on('connection', (socket) => {
    console.log('Cliente conectado:', socket.id);

    socket.on('subscribe_mission', (missionId) => {
        socket.join('mission_' + missionId);
    });

    socket.on('disconnect', () => {
        console.log('Cliente desconectado:', socket.id);
    });
});

server.listen(PORT, () => {
    console.log(`TrackMoz Socket.IO em http://localhost:${PORT}`);
});
