/**
 * TrackMoz PWA — instalação nativa quando o Chrome libera beforeinstallprompt;
 * senão, guia manual clara + diagnóstico do servidor.
 */
(function () {
    'use strict';

    if (window.__tmPwaReady) return;
    window.__tmPwaReady = true;

    var cfg = window.TRACKMOZ_PWA || {};
    var baseUrl = (cfg.baseUrl || '').replace(/\/$/, '');
    var swUrl = cfg.swUrl || (baseUrl + '/sw.js');
    var scopeUrl = cfg.scopeUrl || (baseUrl + '/');
    var manifestUrl = cfg.manifestUrl || (baseUrl + '/manifest.php');
    var iconUrl = cfg.iconUrl || (baseUrl + '/assets/img/icons/icon-192.png');
    var healthUrl = baseUrl + '/api/pwa-health.php';

    var STORAGE_SNOOZE = 'tm_pwa_snooze_until_v6';
    var SNOOZE_MS = 2 * 60 * 60 * 1000;
    var deferredPrompt = null;
    var promptReady = false;
    var lastFailReason = '';

    try { localStorage.removeItem('tm_pwa_dismiss_until'); } catch (e) {}
    try { localStorage.removeItem('tm_pwa_snooze_until_v4'); } catch (e) {}
    try { localStorage.removeItem('tm_pwa_snooze_until_v5'); } catch (e) {}

    // Capturar ANTES de qualquer outra lógica (máxima prioridade)
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        promptReady = true;
        lastFailReason = '';
        if (document.getElementById('tm-pwa-banner')) {
            setBannerText('Pronto! Toque em Instalar.');
            setInstallBtn('Instalar', false);
            showBanner(true);
        }
    }, true);

    function isStandalone() {
        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true ||
            window.matchMedia('(display-mode: window-controls-overlay)').matches
        );
    }

    function isLocalHost() {
        var h = location.hostname;
        return h === 'localhost' || h === '127.0.0.1' || h === '::1';
    }

    function isIos() {
        return /iphone|ipad|ipod/i.test(navigator.userAgent) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function isAndroid() {
        return /android/i.test(navigator.userAgent);
    }

    function isInAppBrowser() {
        var ua = navigator.userAgent || '';
        return /FBAN|FBAV|Instagram|Line\/|Twitter|WhatsApp|MicroMessenger|TikTok/i.test(ua);
    }

    function isChromium() {
        var ua = navigator.userAgent || '';
        if (/SamsungBrowser|OPR\//i.test(ua)) return false;
        return /Chrome|CriOS|Edg|Chromium/i.test(ua) && !/Firefox|FxiOS/i.test(ua);
    }

    function isSecureContextOk() {
        var host = location.hostname;
        return window.isSecureContext || location.protocol === 'https:' ||
            host === 'localhost' || host === '127.0.0.1';
    }

    function isSnoozed() {
        try {
            return parseInt(localStorage.getItem(STORAGE_SNOOZE) || '0', 10) > Date.now();
        } catch (e) {
            return false;
        }
    }

    function snooze() {
        try { localStorage.setItem(STORAGE_SNOOZE, String(Date.now() + SNOOZE_MS)); } catch (e) {}
    }

    function clearSnooze() {
        try { localStorage.removeItem(STORAGE_SNOOZE); } catch (e) {}
    }

    function needsInstallReminder() {
        return !isStandalone();
    }

    function ensureHeadTags() {
        if (!document.querySelector('link[rel="manifest"]')) {
            var link = document.createElement('link');
            link.rel = 'manifest';
            link.href = manifestUrl;
            document.head.appendChild(link);
        }
    }

    function setBannerText(msg) {
        var el = document.querySelector('#tm-pwa-banner .tm-pwa-banner__text');
        if (el) el.textContent = msg;
    }

    function setInstallBtn(label, disabled) {
        var btn = document.querySelector('#tm-pwa-banner [data-tm-pwa-install]');
        if (!btn) return;
        btn.textContent = label;
        btn.disabled = !!disabled;
        btn.style.opacity = disabled ? '0.7' : '1';
    }

    function defaultIntro() {
        if (isIos()) {
            return 'No iPhone: Partilhar → «Adicionar ao Ecrã Inicial».';
        }
        if (isInAppBrowser()) {
            return 'Abra este link no Chrome (menu ⋮ do Facebook/Instagram → «Abrir no Chrome»).';
        }
        if (promptReady) {
            return 'Toque em Instalar para adicionar o TrackMoz ao dispositivo.';
        }
        return 'Instale o TrackMoz para abrir como aplicação ligada a este site.';
    }

    function createBanner() {
        var existing = document.getElementById('tm-pwa-banner');
        if (existing) return existing;

        var el = document.createElement('div');
        el.id = 'tm-pwa-banner';
        el.className = 'tm-pwa-banner';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-label', 'Instalar TrackMoz');
        el.innerHTML =
            '<img class="tm-pwa-banner__icon" src="' + iconUrl + '" alt="TrackMoz" width="48" height="48" onerror="this.style.display=\'none\'">' +
            '<div class="tm-pwa-banner__body">' +
                '<p class="tm-pwa-banner__title">Instalar TrackMoz</p>' +
                '<p class="tm-pwa-banner__text">' + defaultIntro() + '</p>' +
                '<div class="tm-pwa-banner__actions">' +
                    '<button type="button" class="tm-pwa-banner__btn tm-pwa-banner__btn--primary" data-tm-pwa-install>Instalar</button>' +
                    '<button type="button" class="tm-pwa-banner__btn tm-pwa-banner__btn--ghost" data-tm-pwa-later>Agora não</button>' +
                '</div>' +
            '</div>' +
            '<button type="button" class="tm-pwa-banner__close" data-tm-pwa-later aria-label="Fechar">&times;</button>';

        document.body.appendChild(el);
        el.querySelectorAll('[data-tm-pwa-later]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                hideBanner();
                snooze();
            });
        });
        el.querySelector('[data-tm-pwa-install]').addEventListener('click', onInstallClick);
        return el;
    }

    function showBanner(force) {
        if (!needsInstallReminder()) return;
        if (!force && isSnoozed()) return;
        createBanner().classList.add('is-visible');
        setBannerText(defaultIntro());
        setInstallBtn(isIos() ? 'Como instalar' : 'Instalar', false);
        updateMenuInstallLinks(true);
    }

    function hideBanner() {
        var el = document.getElementById('tm-pwa-banner');
        if (el) el.classList.remove('is-visible');
    }

    function updateMenuInstallLinks(show) {
        document.querySelectorAll('[data-tm-pwa-menu-install]').forEach(function (node) {
            node.style.display = show && needsInstallReminder() ? '' : 'none';
        });
    }

    function registerSw() {
        if (!('serviceWorker' in navigator) || !isSecureContextOk()) {
            return Promise.reject(new Error('no-sw'));
        }
        return navigator.serviceWorker.register(swUrl, {
            scope: scopeUrl,
            updateViaCache: 'none',
        }).then(function (reg) {
            if (reg.waiting) {
                reg.waiting.postMessage({ type: 'SKIP_WAITING' });
            }
            return navigator.serviceWorker.ready;
        });
    }

    function manualSteps() {
        if (isAndroid()) {
            return 'No Chrome Android: toque em ⋮ (canto superior) → «Instalar aplicação» ou «Adicionar à tela inicial» → Instalar.';
        }
        return 'No Chrome/Edge do PC: ícone de instalação na barra de endereço (⊕ / monitor) ou menu ⋮ → «Instalar TrackMoz» / «Aplicações» → «Instalar este site como uma aplicação».';
    }

    function looksLikeHostChallenge(text) {
        if (!text || typeof text !== 'string') return false;
        return /aes\.js|toNumbers\(|slowAES|__test=/i.test(text);
    }

    function checkHostBlocksPwa() {
        // Alguns alojamentos gratuitos (ex.: site.je) envolvem JS/JSON num HTML com aes.js —
        // o Chrome deixa de conseguir validar manifest/SW e nunca dispara a instalação.
        return Promise.all([
            fetch(swUrl, { cache: 'no-store' }).then(function (r) {
                return r.text().then(function (t) {
                    return { ct: (r.headers.get('content-type') || ''), body: t };
                });
            }).catch(function () { return null; }),
            fetch(manifestUrl, { cache: 'no-store' }).then(function (r) {
                return r.text().then(function (t) {
                    return { ct: (r.headers.get('content-type') || ''), body: t };
                });
            }).catch(function () { return null; }),
        ]).then(function (parts) {
            var sw = parts[0];
            var mf = parts[1];
            var blocked = false;
            if (sw && (looksLikeHostChallenge(sw.body) || /text\/html/i.test(sw.ct) && sw.body.trim().charAt(0) === '<')) {
                blocked = true;
            }
            if (mf && (looksLikeHostChallenge(mf.body) || (mf.body.trim().charAt(0) === '<' && !/^\s*\{/.test(mf.body)))) {
                blocked = true;
            }
            var swLooksJs = sw && !looksLikeHostChallenge(sw.body) && /serviceWorker|addEventListener|trackmoz/i.test(sw.body);
            var mfLooksJson = mf && !looksLikeHostChallenge(mf.body) && mf.body.indexOf('"name"') !== -1;
            return { blocked: blocked && !(swLooksJs && mfLooksJson), swOk: !!swLooksJs, manifestOk: !!mfLooksJson };
        });
    }

    function diagnose() {
        return fetch(healthUrl, { cache: 'no-store' })
            .then(function (r) { return r.text().then(function (t) {
                if (looksLikeHostChallenge(t) || t.trim().charAt(0) === '<') return { _challenge: true };
                try { return JSON.parse(t); } catch (e) { return null; }
            }); })
            .catch(function () { return null; })
            .then(function (health) {
                return checkHostBlocksPwa().then(function (hostCheck) {
                    return registerSw().catch(function () { return null; }).then(function (ready) {
                        return { health: health, swReady: !!ready, hostCheck: hostCheck };
                    });
                });
            })
            .then(function (ctx) {
                var health = ctx.health;
                var hostCheck = ctx.hostCheck || {};

                if (isInAppBrowser()) {
                    lastFailReason = 'Está dentro de outra app (Facebook/Instagram…). Abra o link no Chrome.';
                    return;
                }
                if (isIos()) {
                    lastFailReason = 'No iPhone use Safari → Partilhar → Adicionar ao Ecrã Inicial.';
                    return;
                }
                if (!isSecureContextOk()) {
                    lastFailReason = 'Sem HTTPS o telemóvel não instala. Abra o site com https://';
                    return;
                }
                if ((health && health._challenge) || hostCheck.blocked) {
                    lastFailReason =
                        'O alojamento (site.je) bloqueia o Chrome com um filtro (aes.js) em cima do manifest/service worker. ' +
                        'Enquanto isso existir, a instalação automática NÃO funciona. ' +
                        'Solução: no painel do alojamento desactive a protecção/anti-bot, ou mude para um hosting normal (sem esse filtro).';
                    return;
                }
                if (health && health.ok === false) {
                    lastFailReason = health.hint || 'Faltam ficheiros PWA no servidor (icons/, sw.js, manifest.php).';
                    return;
                }
                if (!ctx.swReady || hostCheck.swOk === false) {
                    lastFailReason =
                        'Service worker não activou. No site.je isso costuma ser o filtro aes.js — desactive a protecção do hosting ou mude de servidor.';
                    return;
                }
                if (!isChromium()) {
                    lastFailReason = 'Use Google Chrome ou Microsoft Edge. ' + manualSteps();
                    return;
                }
                if (promptReady) {
                    lastFailReason = '';
                    return;
                }

                lastFailReason =
                    'O Chrome não liberou o instalador. Em trackmoz.site.je a causa mais comum é o filtro do alojamento (aes.js). ' +
                    'Desactive essa protecção no painel do hosting. Depois: desinstale ícone antigo → janela anónima → ' +
                    manualSteps();
            });
    }

    function onInstallClick() {
        if (isIos()) {
            setBannerText('Safari → Partilhar (□↑) → «Adicionar ao Ecrã Inicial» → Adicionar.');
            setInstallBtn('Entendi', false);
            return;
        }

        if (isInAppBrowser()) {
            setBannerText('Menu ⋮ → «Abrir no Chrome», depois Instalar.');
            return;
        }

        if (!isSecureContextOk()) {
            setBannerText('Abra com https:// no servidor.');
            return;
        }

        // prompt() só no clique, sem esperar
        if (deferredPrompt) {
            var ev = deferredPrompt;
            deferredPrompt = null;
            promptReady = false;
            setInstallBtn('A instalar…', true);
            setBannerText('Confirme no diálogo do sistema…');
            try {
                ev.prompt();
            } catch (err) {
                setBannerText(manualSteps());
                setInstallBtn('Instalar', false);
                return;
            }
            Promise.resolve(ev.userChoice).then(function (choice) {
                if (choice && choice.outcome === 'accepted') {
                    hideBanner();
                    clearSnooze();
                    updateMenuInstallLinks(false);
                } else {
                    setBannerText('Cancelado. Pode usar o menu do Chrome: ' + manualSteps());
                    setInstallBtn('Instalar', false);
                }
            }).catch(function () {
                setBannerText(manualSteps());
                setInstallBtn('Instalar', false);
            });
            return;
        }

        setInstallBtn('A verificar…', true);
        setBannerText('A verificar instalação…');
        diagnose().then(function () {
            if (deferredPrompt) {
                setInstallBtn('Instalar agora', false);
                setBannerText('Pronto! Toque outra vez em «Instalar agora».');
                return;
            }
            setInstallBtn('Ver como instalar', false);
            setBannerText(lastFailReason || manualSteps());
        });
    }

    function init() {
        ensureHeadTags();

        if (!needsInstallReminder()) {
            updateMenuInstallLinks(false);
            if (isSecureContextOk() && 'serviceWorker' in navigator) {
                registerSw().catch(function () {});
            }
            return;
        }

        updateMenuInstallLinks(true);

        window.addEventListener('appinstalled', function () {
            deferredPrompt = null;
            promptReady = false;
            hideBanner();
            clearSnooze();
            updateMenuInstallLinks(false);
        });

        if (!isIos() && isSecureContextOk()) {
            registerSw().then(function () {
                // Em alguns Chrome o evento só chega após o SW estar pronto
                setTimeout(function () {
                    if (promptReady) {
                        showBanner(true);
                        setBannerText('Pronto! Toque em Instalar.');
                    }
                }, 1000);
            }).catch(function () {});
        }

        setTimeout(function () {
            if (needsInstallReminder()) showBanner(false);
        }, 1400);

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && needsInstallReminder() && !isSnoozed()) {
                showBanner(false);
            }
        });
    }

    window.TrackMozPwa = {
        promptInstall: function () {
            clearSnooze();
            showBanner(true);
            onInstallClick();
        },
        isInstalled: isStandalone,
        showBanner: function () {
            clearSnooze();
            showBanner(true);
        },
        diagnose: diagnose,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
