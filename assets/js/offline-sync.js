/**
 * TrackMoz Offline Sync — IndexedDB outbox + cache de missão + banner.
 */
(function (global) {
    'use strict';

    var DB_NAME = 'trackmoz_offline_v1';
    var DB_VER = 1;
    var dbPromise = null;
    var flushing = false;
    var listeners = [];

    function uuid() {
        if (global.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function (resolve, reject) {
            if (!global.indexedDB) {
                reject(new Error('IndexedDB indisponível'));
                return;
            }
            var req = indexedDB.open(DB_NAME, DB_VER);
            req.onupgradeneeded = function (e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains('outbox')) {
                    var ob = db.createObjectStore('outbox', { keyPath: 'id' });
                    ob.createIndex('by_created', 'createdAt', { unique: false });
                }
                if (!db.objectStoreNames.contains('mission_cache')) {
                    db.createObjectStore('mission_cache', { keyPath: 'missaoId' });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
        return dbPromise;
    }

    function txStore(store, mode) {
        return openDb().then(function (db) {
            return db.transaction(store, mode).objectStore(store);
        });
    }

    function idbReq(req) {
        return new Promise(function (resolve, reject) {
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function notify() {
        return pendingCount().then(function (n) {
            var state = {
                online: !!navigator.onLine,
                pending: n,
                flushing: flushing,
            };
            listeners.forEach(function (fn) {
                try { fn(state); } catch (e) { /* ignore */ }
            });
            updateBanner(state);
            return state;
        });
    }

    function pendingCount() {
        return openDb().then(function (db) {
            return idbReq(db.transaction('outbox', 'readonly').objectStore('outbox').count());
        }).catch(function () { return 0; });
    }

    function listOutbox() {
        return openDb().then(function (db) {
            return idbReq(db.transaction('outbox', 'readonly').objectStore('outbox').getAll());
        }).then(function (rows) {
            rows = rows || [];
            rows.sort(function (a, b) { return (a.createdAt || 0) - (b.createdAt || 0); });
            return rows;
        });
    }

    function enqueue(item) {
        var row = {
            id: item.id || uuid(),
            type: item.type || 'request',
            url: item.url,
            method: item.method || 'POST',
            body: item.body || {},
            createdAt: Date.now(),
            tries: 0,
            meta: item.meta || {},
        };

        var prep = row.type === 'gps'
            ? listOutbox().then(function (rows) {
                var missaoId = row.meta && row.meta.missaoId;
                var stale = (rows || []).filter(function (r) {
                    if (r.type !== 'gps') return false;
                    var mid = r.meta && r.meta.missaoId;
                    return mid == null || mid === missaoId;
                });
                return Promise.all(stale.map(function (r) { return removeOutbox(r.id); }));
            })
            : Promise.resolve();

        return prep.then(function () {
            return openDb().then(function (db) {
                return idbReq(db.transaction('outbox', 'readwrite').objectStore('outbox').put(row));
            });
        }).then(function () {
            notify();
            if (navigator.onLine) flush();
            return row;
        });
    }

    function removeOutbox(id) {
        return openDb().then(function (db) {
            return idbReq(db.transaction('outbox', 'readwrite').objectStore('outbox').delete(id));
        });
    }

    function bumpTries(id, tries) {
        return openDb().then(function (db) {
            var store = db.transaction('outbox', 'readwrite').objectStore('outbox');
            return idbReq(store.get(id)).then(function (row) {
                if (!row) return;
                row.tries = tries;
                return idbReq(store.put(row));
            });
        });
    }

    function cacheMission(missaoId, data) {
        var row = Object.assign({ missaoId: Number(missaoId), savedAt: Date.now() }, data || {});
        return openDb().then(function (db) {
            return idbReq(db.transaction('mission_cache', 'readwrite').objectStore('mission_cache').put(row));
        });
    }

    function getCachedMission(missaoId) {
        return openDb().then(function (db) {
            return idbReq(db.transaction('mission_cache', 'readonly').objectStore('mission_cache').get(Number(missaoId)));
        });
    }

    function bodyToFormData(body) {
        var form = new FormData();
        Object.keys(body || {}).forEach(function (k) {
            var v = body[k];
            if (v != null && v !== '') form.append(k, v);
        });
        return form;
    }

    function sendItem(item) {
        return fetch(item.url, {
            method: item.method || 'POST',
            body: bodyToFormData(item.body),
            credentials: 'same-origin',
        }).then(function (r) {
            return r.json().then(function (data) {
                return { ok: r.ok, status: r.status, data: data };
            }).catch(function () {
                return { ok: r.ok, status: r.status, data: null };
            });
        });
    }

    function flush() {
        if (flushing || !navigator.onLine) {
            return notify();
        }
        flushing = true;
        notify();

        return listOutbox().then(function (items) {
            var chain = Promise.resolve();
            items.forEach(function (item) {
                chain = chain.then(function () {
                    if (!navigator.onLine) return;
                    return sendItem(item).then(function (res) {
                        var data = res.data || {};
                        var success =
                            res.ok &&
                            (data.success === true || data.ok === true || data.duplicate === true);
                        // 401/403: não reenviar em loop
                        if (res.status === 401 || res.status === 403) {
                            return removeOutbox(item.id);
                        }
                        if (success) {
                            return removeOutbox(item.id);
                        }
                        // Erro de negócio (success:false) — remover para não bloquear a fila
                        if (res.ok && data && data.success === false && !data.retry) {
                            return removeOutbox(item.id);
                        }
                        var tries = (item.tries || 0) + 1;
                        if (tries >= 8) return removeOutbox(item.id);
                        return bumpTries(item.id, tries);
                    }).catch(function () {
                        var tries = (item.tries || 0) + 1;
                        if (tries >= 8) return removeOutbox(item.id);
                        return bumpTries(item.id, tries);
                    });
                });
            });
            return chain;
        }).finally(function () {
            flushing = false;
            return notify();
        });
    }

    function ensureBanner() {
        var el = document.getElementById('tm-offline-banner');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'tm-offline-banner';
        el.setAttribute('role', 'status');
        el.style.cssText =
            'position:fixed;left:0;right:0;top:0;z-index:9999;padding:8px 12px;' +
            'font:600 13px/1.3 system-ui,sans-serif;text-align:center;display:none;' +
            'transition:background .2s,color .2s';
        document.body.appendChild(el);
        return el;
    }

    function updateBanner(state) {
        if (!document.body) return;
        var el = ensureBanner();
        if (state.online && state.pending === 0 && !state.flushing) {
            el.style.display = 'none';
            document.body.style.paddingTop = '';
            return;
        }
        el.style.display = 'block';
        document.body.style.paddingTop = '34px';
        if (!state.online) {
            el.style.background = '#b45309';
            el.style.color = '#fff';
            el.textContent = state.pending
                ? ('Offline · ' + state.pending + ' acção(ões) guardada(s) — sync quando houver rede')
                : 'Offline · algumas funções ficam em fila';
        } else if (state.flushing || state.pending > 0) {
            el.style.background = '#1d4ed8';
            el.style.color = '#fff';
            el.textContent = 'A sincronizar ' + (state.pending || '') + '…';
        }
    }

    function onChange(fn) {
        listeners.push(fn);
        notify();
        return function () {
            listeners = listeners.filter(function (x) { return x !== fn; });
        };
    }

    function init() {
        window.addEventListener('online', function () { flush(); });
        window.addEventListener('offline', function () { notify(); });
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { notify(); flush(); });
        } else {
            notify();
            flush();
        }
    }

    global.TrackMozOffline = {
        uuid: uuid,
        enqueue: enqueue,
        flush: flush,
        pendingCount: pendingCount,
        cacheMission: cacheMission,
        getCachedMission: getCachedMission,
        onChange: onChange,
        init: init,
        isOnline: function () { return !!navigator.onLine; },
    };

    init();
})(window);
