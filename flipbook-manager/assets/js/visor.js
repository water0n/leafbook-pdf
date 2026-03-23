/**
 * visor.js — LeafBook PDF v1.4.15
 *
 * FIXES:
 *  1. El flipbook se monta sobre un contenedor HTML real y
 *     loadFromImages() recibe data-URLs (string[]) como espera StPageFlip.
 *
 *  2. Se conserva una precarga ligera y luego se instancia el libro con
 *     double-rAF para asegurar layout estable.
 *
 *  3. fbmIniciarTodos() idempotente invocada desde wp_add_inline_script al footer.
 */
(function () {
    'use strict';

    var instancias = window._lbInstancias = window._lbInstancias || {};
    var iniciados  = {};

    if (!window._lbResizeBound) {
        window._lbResizeBound = true;
        window.addEventListener('resize', function () {
            window.clearTimeout(window._lbResizeTimer);
            window._lbResizeTimer = window.setTimeout(function () {
                Object.keys(instancias).forEach(function (id) { refrescarFlipbook(id); });
            }, 80);
        });
    }

    window.fbmIniciarTodos = function () {
        document.querySelectorAll('.fbm-visor').forEach(function (elVisor) {
            var id    = elVisor.dataset.id;
            var datos = window['fbmData_' + id];
            if (datos && !iniciados[id]) {
                iniciados[id] = true;
                iniciar(id, datos, elVisor);
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.fbmIniciarTodos(); });
    } else {
        window.fbmIniciarTodos();
    }

    // ══════════════════════════════════════════════════════
    // INICIAR
    // ══════════════════════════════════════════════════════
    function iniciar(id, datos, elVisor) {
        if (typeof pdfjsLib === 'undefined') {
            return mostrarError(id, 'PDF.js no cargó. Verifica tu conexión a internet.');
        }

        pdfjsLib.GlobalWorkerOptions.workerSrc = datos.workerSrc;

        var ancho = parseInt(datos.ancho, 10) || 900;
        var alto  = parseInt(datos.alto,  10) || 600;
        var calidadRender = Math.min(1, Math.max(0.5, parseFloat(datos.calidad) || 0.85));
        var escalaRender  = Math.min(3, Math.max(1, parseFloat(datos.escala) || 1.5));

        setProg(id, 5);
        setTxt(id, 'Conectando…');

        function cargarConRetry(url, intento) {
            intento = intento || 0;
            return pdfjsLib.getDocument({ url: url, withCredentials: false }).promise
                .catch(function(e) {
                    if (intento < 2 && e.message && /fetch|network|Unexpected/i.test(e.message)) {
                        setTxt(id, 'Reintentando… (' + (intento+1) + '/2)');
                        return new Promise(function(ok){ setTimeout(ok, 1500*(intento+1)); })
                            .then(function(){ return cargarConRetry(url, intento+1); });
                    }
                    throw e;
                });
        }

        cargarConRetry(datos.pdfUrl)
        .then(function (doc) {
            var total = doc.numPages;
            setTxt(id, 'Procesando ' + total + ' páginas…');
            if (datos.buscar === '1') fbmIndexarTexto(id, doc);
            return renderTodas(doc, total, ancho, alto, id, { calidad: calidadRender, escala: escalaRender, zoomMax: 3 });
        })
        .then(function (dataUrls) {
            // Calienta la decodificación del browser, pero el flipbook recibe
            // string[] porque StPageFlip espera rutas/data-URLs.
            setTxt(id, 'Preparando páginas…');
            return precargarImagenes(dataUrls, id);
        })
        .then(function (imageSources) {
            crearFlipbook(id, imageSources, ancho, alto, elVisor, datos);
        })
        .catch(function (e) {
            console.error('[LeafBook PDF]', e);
            var msg = '';
            if      (e.name === 'MissingPDFException')                msg = 'Archivo no encontrado (404). Verifica que el PDF existe en la Biblioteca de Medios.';
            else if (e.name === 'InvalidPDFException')                msg = 'El archivo no es un PDF válido.';
            else if (e.message && /fetch|network|load/i.test(e.message)) msg = 'No se pudo descargar el PDF. Verifica la conexión o los permisos del archivo.';
            else                                                       msg = 'Error al cargar: ' + (e.message || e);
            mostrarError(id, msg);
        });
    }

    // ══════════════════════════════════════════════════════
    // PRE-CARGA DE IMÁGENES
    // Calienta la decodificación del browser, pero loadFromImages() debe
    // recibir string[] porque StPageFlip crea internamente sus Image().
    // ══════════════════════════════════════════════════════
    function precargarImagenes(dataUrls, id) {
        var total = dataUrls.length;
        var cargadas = 0;

        return Promise.all(dataUrls.map(function (src, idx) {
            return new Promise(function (resolve, reject) {
                var img    = new Image();
                img.onload = function () {
                    cargadas++;
                    setProg(id, Math.round(90 + (cargadas / total) * 9));
                    resolve(src);
                };
                img.onerror = function () {
                    // Si falla un decode, igual resolvemos para no bloquear todo
                    console.warn('[LeafBook PDF] Error precargando imagen', idx);
                    resolve(src);
                };
                img.src = src;
            });
        }));
    }

    // ══════════════════════════════════════════════════════
    // RENDERIZAR PÁGINAS → array de data URLs
    // ══════════════════════════════════════════════════════
    function renderTodas(doc, total, anchoVisor, altoVisor, id, renderCfg) {
        var imgs     = [];
        var chain    = Promise.resolve();
        var anchoPag = Math.floor(anchoVisor / 2);

        for (var i = 1; i <= total; i++) {
            (function (n) {
                chain = chain.then(function () {
                    return renderPagina(doc, n, anchoPag, altoVisor, renderCfg).then(function (dataUrl) {
                        imgs.push(dataUrl);
                        setProg(id, Math.round(5 + (n / total) * 80));
                        setTxt(id, 'Página ' + n + ' de ' + total + '…');
                    });
                });
            })(i);
        }
        return chain.then(function () { return imgs; });
    }

    function renderPagina(doc, n, anchoPag, altoPag, renderCfg) {
        renderCfg = renderCfg || {};
        var calidad = Math.min(1, Math.max(0.5, parseFloat(renderCfg.calidad) || 0.85));
        var escalaUsuario = Math.min(3, Math.max(1, parseFloat(renderCfg.escala) || 1.5));
        var zoomMax = Math.max(1, parseFloat(renderCfg.zoomMax) || 2);

        return doc.getPage(n).then(function (pag) {
            var vp0 = pag.getViewport({ scale: 1 });
            var fitScale = Math.min(anchoPag / vp0.width, altoPag / vp0.height);
            var escalaDeseada = fitScale * escalaUsuario * zoomMax;
            var maxPixels = calidad >= 0.98 ? 20000000 : (calidad >= 0.9 ? 16000000 : 12000000);
            var maxScalePorPixeles = Math.sqrt(maxPixels / (vp0.width * vp0.height));
            var escalaFinal = Math.min(escalaDeseada, maxScalePorPixeles);
            var vp = pag.getViewport({ scale: escalaFinal });

            var canvas  = document.createElement('canvas');
            canvas.width  = Math.round(vp.width);
            canvas.height = Math.round(vp.height);
            var ctx = canvas.getContext('2d', { alpha: false });
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            return pag.render({ canvasContext: ctx, viewport: vp }).promise
                .then(function () {
                    return calidad >= 0.9
                        ? canvas.toDataURL('image/png')
                        : canvas.toDataURL('image/jpeg', Math.max(0.7, calidad));
                });
        });
    }

    // ══════════════════════════════════════════════════════
    // CREAR FLIPBOOK
    // ══════════════════════════════════════════════════════
    function crearFlipbook(id, imageSources, ancho, alto, elVisor, datos) {
        setProg(id, 99);

        var spin = document.getElementById('fbm-cargando-' + id);
        if (spin) spin.style.display = 'none';

        var anchoPag = Math.floor(ancho / 2);

        var libroExistente = document.getElementById('fbm-book-' + id);
        if (libroExistente) libroExistente.remove();

        // StPageFlip necesita un elemento HTML raíz, no un canvas manual.
        var book = document.createElement('div');
        book.id = 'fbm-book-' + id;
        book.className = 'fbm-libro';
        book.style.cssText = 'position:relative;width:100%;margin:0 auto;';
        elVisor.appendChild(book);

        var panLayer = document.createElement('div');
        panLayer.id = 'fbm-pan-' + id;
        panLayer.className = 'fbm-pan-layer';
        panLayer.setAttribute('aria-hidden', 'true');
        elVisor.appendChild(panLayer);

        // Double rAF: garantiza layout completo antes de que StPageFlip lea getBoundingClientRect
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {

                var minPageWidth  = Math.max(160, Math.round(anchoPag * 0.42));
                var minPageHeight = Math.max(220, Math.round(alto * (minPageWidth / anchoPag)));
                var maxPageWidth  = Math.max(anchoPag, Math.round(((window.screen && window.screen.width) || ancho) * 0.46));
                var maxPageHeight = Math.max(alto, Math.round(((window.screen && window.screen.height) || alto) * 0.90));

                var fb = new St.PageFlip(book, {
                    width:               anchoPag,
                    height:              alto,
                    size:                'stretch',
                    minWidth:            minPageWidth,
                    maxWidth:            maxPageWidth,
                    minHeight:           minPageHeight,
                    maxHeight:           maxPageHeight,
                    drawShadow:          true,
                    flippingTime:        600,
                    usePortrait:         true,
                    autoSize:            true,
                    maxShadowOpacity:    0.4,
                    showCover:           true,
                    mobileScrollSupport: false,
                    startZIndex:         0,
                });

                fb.loadFromImages(imageSources);

                instancias[id] = {
                    flipBook:     fb,
                    imagenes:     imageSources,
                    libroEl:      book,
                    panLayer:     panLayer,
                    zoom:         1,
                    panX:         0,
                    panY:         0,
                    isPanning:    false,
                    totalPaginas: imageSources.length,
                    ancho:        ancho,
                    alto:         alto,
                };

                fb.on('flip', function (e) {
                    var inst = instancias[id];
                    if (inst) {
                        inst.panX = 0;
                        inst.panY = 0;
                        inst.isPanning = false;
                    }
                    setInfo(id, 'Pág. ' + (e.data + 1) + ' / ' + imageSources.length);
                    resaltarMiniatura(id, e.data);
                    actualizarVista(id);
                });

                fb.on('changeOrientation', function () {
                    refrescarFlipbook(id);
                });

                fb.on('init', function () {
                    setInfo(id, 'Pág. 1 / ' + imageSources.length);
                    setProg(id, 100);
                    setTimeout(function () {
                        var b = document.getElementById('fbm-progreso-' + id);
                        if (b) b.style.opacity = '0';
                    }, 500);
                    buildMiniaturas(id, imageSources);
                    refrescarFlipbook(id);

                    if (datos.autoplay === '1') {
                        var ap = setInterval(function () {
                            var inst = instancias[id];
                            if (!inst || inst.flipBook.getCurrentPageIndex() >= inst.totalPaginas - 1) {
                                clearInterval(ap);
                            } else {
                                inst.flipBook.flipNext();
                            }
                        }, 4000);
                    }
                });

                conectarBotones(id);
                conectarTeclado(id, elVisor);
                conectarSwipe(id, elVisor, fb);
                conectarArrastre(id, panLayer);

            }); // rAF 2
        }); // rAF 1
    }

    // ══════════════════════════════════════════════════════
    // MINIATURAS
    // ══════════════════════════════════════════════════════
    function buildMiniaturas(id, imgs) {
        var grid = document.getElementById('fbm-min-grid-' + id);
        if (!grid) return;

        imgs.forEach(function (imgObj, idx) {
            var thumb     = document.createElement('div');
            thumb.className = 'fbm-miniatura';
            thumb.title     = 'Página ' + (idx + 1);

            // Usar el mismo objeto Image pre-cargado (ya tiene src)
            var img       = document.createElement('img');
            img.src       = imgObj.src || imgObj;
            img.loading   = 'lazy';
            img.draggable = false;

            var num       = document.createElement('span');
            num.textContent = idx + 1;

            thumb.appendChild(img);
            thumb.appendChild(num);
            grid.appendChild(thumb);

            thumb.addEventListener('click', function () {
                var inst = instancias[id];
                if (inst) inst.flipBook.flip(idx);
            });
        });
        resaltarMiniatura(id, 0);
    }

    function resaltarMiniatura(id, idx) {
        var grid = document.getElementById('fbm-min-grid-' + id);
        if (!grid) return;
        grid.querySelectorAll('.fbm-miniatura').forEach(function (t, i) {
            t.classList.toggle('fbm-miniatura--activa', i === idx);
        });
        var activa = grid.querySelectorAll('.fbm-miniatura')[idx];
        if (activa) activa.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ══════════════════════════════════════════════════════
    // BOTONES
    // ══════════════════════════════════════════════════════
    function conectarBotones(id) {
        var ctrl = document.getElementById('fbm-controles-' + id);
        if (!ctrl) return;

        ctrl.querySelectorAll('[data-accion]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var inst   = instancias[id];
                var accion = btn.dataset.accion;
                switch (accion) {
                    case 'primera':    if(inst) inst.flipBook.flip(0);                      break;
                    case 'anterior':   if(inst) inst.flipBook.flipPrev();                   break;
                    case 'siguiente':  if(inst) inst.flipBook.flipNext();                   break;
                    case 'ultima':     if(inst) inst.flipBook.flip(inst.totalPaginas - 1); break;
                    case 'zoom-mas':   hacerZoom(id, +1);                                   break;
                    case 'zoom-menos': hacerZoom(id, -1);                                   break;
                    case 'miniaturas': toggleMiniaturas(id);                                break;
                    case 'fullscreen': hacerFullscreen(id);                                 break;
                    case 'compartir':  hacerCompartir(btn);                                 break;
                    case 'imprimir':   hacerImprimir(btn);                                  break;
                }
            });
        });
    }

    // ══════════════════════════════════════════════════════
    // ZOOM
    // ══════════════════════════════════════════════════════
    var niveles = [0.4, 0.5, 0.67, 0.8, 1, 1.25, 1.5, 2, 2.5, 3];

    function getLibroEl(id) {
        return document.getElementById('fbm-book-' + id);
    }

    function getWrapEl(id) {
        return document.getElementById('fbm-wrap-' + id);
    }

    function getVisorWrapEl(id) {
        return document.getElementById('fbm-visor-wrap-' + id);
    }

    function getPanLayerEl(id) {
        return document.getElementById('fbm-pan-' + id);
    }

    function esFullscreenId(id) {
        var fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement;
        return !!fsEl && fsEl === getWrapEl(id);
    }

    function resetPan(id) {
        var inst = instancias[id];
        if (!inst) return;
        inst.panX = 0;
        inst.panY = 0;
        inst.isPanning = false;
        var panLayer = getPanLayerEl(id);
        if (panLayer) panLayer.classList.remove('is-dragging');
    }

    function sincronizarModoPan(id) {
        var inst = instancias[id];
        var panLayer = getPanLayerEl(id);
        var visor = document.getElementById('fbm-visor-' + id);
        var activo = !!(inst && panLayer && inst.zoom > 1.0001);

        if (panLayer) {
            panLayer.classList.toggle('is-active', activo);
            panLayer.setAttribute('aria-hidden', activo ? 'false' : 'true');
        }
        if (visor) visor.classList.toggle('fbm-visor--zoomed', activo);
        if (!activo && inst) inst.isPanning = false;
    }

    function sincronizarLayoutViewport(id) {
        var inst = instancias[id];
        var wrap = getWrapEl(id);
        var visor = document.getElementById('fbm-visor-' + id);
        var visorWrap = getVisorWrapEl(id);
        var book = getLibroEl(id);
        if (!inst || !wrap || !visor || !visorWrap || !book) return;

        var esFS = esFullscreenId(id);
        wrap.classList.toggle('fbm-wrap--fullscreen', esFS);
        wrap.style.maxWidth = esFS ? 'none' : inst.ancho + 'px';
        wrap.style.width = esFS ? '100vw' : '';
        wrap.style.height = esFS ? (window.innerHeight + 'px') : '';
        book.style.maxWidth = 'none';

        if (!esFS) {
            visor.style.height = '';
            return;
        }

        var usados = 0;
        var controles = document.getElementById('fbm-controles-' + id);
        var infoBar = wrap.querySelector('.fbm-info-bar');
        var search = document.getElementById('fbm-search-res-' + id);

        if (controles) usados += controles.offsetHeight;
        if (infoBar) usados += infoBar.offsetHeight;
        if (search && search.style.display !== 'none') usados += search.offsetHeight;

        visor.style.height = Math.max(260, window.innerHeight - usados) + 'px';
    }

    function getOffsetPaginaUnica(inst, bounds) {
        if (!inst || !bounds || !inst.flipBook) return 0;
        if (typeof inst.flipBook.getOrientation !== 'function') return 0;
        if (inst.flipBook.getOrientation() !== 'landscape') return 0;

        var idx = typeof inst.flipBook.getCurrentPageIndex === 'function'
            ? inst.flipBook.getCurrentPageIndex()
            : 0;

        if (idx === 0) return -Math.round((bounds.pageWidth || 0) / 2);
        if (idx >= inst.totalPaginas - 1) return Math.round((bounds.pageWidth || 0) / 2);
        return 0;
    }

    function clampPan(id, bounds) {
        var inst = instancias[id];
        var visor = document.getElementById('fbm-visor-' + id);
        if (!inst || !visor) return;

        if (!bounds || inst.zoom <= 1.0001) {
            inst.panX = 0;
            inst.panY = 0;
            return;
        }

        var viewportW = visor.clientWidth || bounds.width || 0;
        var viewportH = visor.clientHeight || bounds.height || 0;
        var scaledW = (bounds.width || viewportW) * inst.zoom;
        var scaledH = (bounds.height || viewportH) * inst.zoom;
        var baseOffsetX = getOffsetPaginaUnica(inst, bounds);

        if (scaledW <= viewportW) {
            inst.panX = 0;
        } else {
            var maxPanX = Math.max(0, Math.round((scaledW - viewportW) / 2));
            var minX = -maxPanX - baseOffsetX;
            var maxX = maxPanX - baseOffsetX;
            inst.panX = Math.max(minX, Math.min(maxX, inst.panX || 0));
        }

        if (scaledH <= viewportH) {
            inst.panY = 0;
        } else {
            var maxPanY = Math.max(0, Math.round((scaledH - viewportH) / 2));
            inst.panY = Math.max(-maxPanY, Math.min(maxPanY, inst.panY || 0));
        }
    }

    function aplicarTransformacion(id, bounds) {
        var inst = instancias[id];
        var book = getLibroEl(id);
        if (!inst || !book) return;

        if (!bounds && inst.flipBook && typeof inst.flipBook.getBoundsRect === 'function') {
            bounds = inst.flipBook.getBoundsRect();
        }

        clampPan(id, bounds);
        sincronizarModoPan(id);

        var offsetX = getOffsetPaginaUnica(inst, bounds);
        var tx = Math.round(offsetX + (inst.panX || 0));
        var ty = Math.round(inst.panY || 0);

        book.style.transform = 'translate3d(' + tx + 'px,' + ty + 'px,0) scale(' + inst.zoom + ')';
        book.style.transformOrigin = 'center top';
        book.style.transition = inst.isPanning ? 'none' : 'transform 0.2s ease';
    }

    function actualizarVista(id) {
        var inst = instancias[id];
        if (!inst) return;

        sincronizarLayoutViewport(id);

        var visorEl = document.getElementById('fbm-visor-' + id);
        var bounds = inst.flipBook && typeof inst.flipBook.getBoundsRect === 'function'
            ? inst.flipBook.getBoundsRect()
            : null;
        var altoBase = bounds && bounds.height ? bounds.height : inst.alto;

        if (visorEl && !esFullscreenId(id)) {
            visorEl.style.height = Math.round(altoBase) + 'px';
        }

        aplicarTransformacion(id, bounds);
    }

    function refrescarFlipbook(id) {
        var inst = instancias[id];
        if (!inst || !inst.flipBook || typeof inst.flipBook.update !== 'function') return;

        sincronizarLayoutViewport(id);

        requestAnimationFrame(function () {
            inst.flipBook.update();
            requestAnimationFrame(function () {
                sincronizarLayoutViewport(id);
                actualizarVista(id);
            });
        });
    }

    function hacerZoom(id, dir) {
        var inst = instancias[id];
        if (!inst) return;
        var idx = niveles.indexOf(inst.zoom);
        if (idx === -1) idx = 3;
        idx = Math.max(0, Math.min(niveles.length - 1, idx + dir));
        inst.zoom = niveles[idx];

        if (inst.zoom <= 1.0001) {
            resetPan(id);
        } else {
            clampPan(id, inst.flipBook && typeof inst.flipBook.getBoundsRect === 'function' ? inst.flipBook.getBoundsRect() : null);
        }
        actualizarVista(id);

        var zEl = document.getElementById('fbm-zoom-' + id);
        if (zEl) zEl.textContent = Math.round(inst.zoom * 100) + '%';
    }

    // ══════════════════════════════════════════════════════
    // MINIATURAS PANEL
    // ══════════════════════════════════════════════════════
    function toggleMiniaturas(id) {
        var panel = document.getElementById('fbm-miniaturas-' + id);
        var btn   = document.getElementById('fbm-btn-min-' + id);
        if (!panel) return;
        var abierto = panel.classList.toggle('fbm-miniaturas--visible');
        panel.setAttribute('aria-hidden', abierto ? 'false' : 'true');
        if (btn) btn.classList.toggle('fbm-btn--activo', abierto);
        setTimeout(function () { refrescarFlipbook(id); }, 260);
    }

    // ══════════════════════════════════════════════════════
    // FULLSCREEN
    // ══════════════════════════════════════════════════════
    function hacerFullscreen(id) {
        var host = getWrapEl(id) || document.documentElement;
        try {
            if (!document.fullscreenElement && !document.webkitFullscreenElement && !document.mozFullScreenElement) {
                var req = host.requestFullscreen
                       || host.webkitRequestFullscreen
                       || host.mozRequestFullScreen;
                if (req) req.call(host);
            } else {
                var ex = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen;
                if (ex) ex.call(document);
            }
        } catch(e) { window.open(window.location.href, '_blank'); }
    }

    ['fullscreenchange','webkitfullscreenchange'].forEach(function(ev) {
        document.addEventListener(ev, function () {
            document.querySelectorAll('.fbm-btn-fs').forEach(function (btn) {
                var idBtn = btn.dataset.id;
                var activo = esFullscreenId(idBtn);
                btn.classList.toggle('fs-activo', activo);
                btn.title = activo ? 'Salir de pantalla completa' : 'Pantalla completa (F)';
            });

            document.querySelectorAll('.fbm-visor').forEach(function(v) {
                var id2  = v.dataset.id;
                var inst = instancias[id2];
                if (!inst) return;

                sincronizarLayoutViewport(id2);
                [0, 80, 220].forEach(function(delay) {
                    window.setTimeout(function () { refrescarFlipbook(id2); }, delay);
                });
            });
        });
    });

    // ══════════════════════════════════════════════════════
    // COMPARTIR / IMPRIMIR
    // ══════════════════════════════════════════════════════
    function hacerCompartir(btn) {
        var url      = btn.dataset.url    || window.location.href;
        var titulo   = btn.dataset.titulo || document.title;
        var enIframe = (window !== window.top);
        if (!enIframe && navigator.share) {
            navigator.share({ title: titulo, url: url }).catch(function(){});
        } else {
            var copiar = function(texto) {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(texto)
                        .then(function() { toast('🔗 Enlace copiado al portapapeles'); })
                        .catch(function() { prompt('Copia este enlace:', texto); });
                } else { prompt('Copia este enlace:', texto); }
            };
            copiar(url);
        }
    }

    function hacerImprimir(btn) {
        var url = btn.dataset.url;
        if (!url) return;
        window.open(url, '_blank');
    }

    // ══════════════════════════════════════════════════════
    // SWIPE / TECLADO
    // ══════════════════════════════════════════════════════
    function conectarSwipe(id, elVisor, fb) {
        var sx = 0, sy = 0;
        elVisor.addEventListener('touchstart', function(e) {
            var inst = instancias[id];
            if (inst && inst.zoom > 1.0001) return;
            sx = e.touches[0].clientX; sy = e.touches[0].clientY;
        }, { passive: true });
        elVisor.addEventListener('touchend', function(e) {
            var inst = instancias[id];
            if (inst && inst.zoom > 1.0001) return;
            var dx = e.changedTouches[0].clientX - sx;
            var dy = e.changedTouches[0].clientY - sy;
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                if (dx < 0) fb.flipNext(); else fb.flipPrev();
            }
        }, { passive: true });
    }

    function conectarArrastre(id, panLayer) {
        if (!panLayer || panLayer.dataset.dragBound === '1') return;
        panLayer.dataset.dragBound = '1';

        function finalizar(e) {
            var inst = instancias[id];
            if (!inst) return;
            inst.isPanning = false;
            panLayer.classList.remove('is-dragging');
            if (e && e.pointerId != null && panLayer.hasPointerCapture && panLayer.hasPointerCapture(e.pointerId)) {
                panLayer.releasePointerCapture(e.pointerId);
            }
            aplicarTransformacion(id);
        }

        panLayer.addEventListener('pointerdown', function (e) {
            var inst = instancias[id];
            if (!inst || inst.zoom <= 1.0001) return;
            inst.isPanning = true;
            inst.panStartX = e.clientX;
            inst.panStartY = e.clientY;
            inst.panOriginX = inst.panX || 0;
            inst.panOriginY = inst.panY || 0;
            panLayer.classList.add('is-dragging');
            if (panLayer.setPointerCapture) panLayer.setPointerCapture(e.pointerId);
            e.preventDefault();
            e.stopPropagation();
        });

        panLayer.addEventListener('pointermove', function (e) {
            var inst = instancias[id];
            if (!inst || !inst.isPanning) return;
            inst.panX = (inst.panOriginX || 0) + (e.clientX - inst.panStartX);
            inst.panY = (inst.panOriginY || 0) + (e.clientY - inst.panStartY);
            aplicarTransformacion(id);
            e.preventDefault();
            e.stopPropagation();
        });

        ['pointerup', 'pointercancel', 'lostpointercapture', 'pointerleave'].forEach(function (ev) {
            panLayer.addEventListener(ev, finalizar);
        });

        panLayer.addEventListener('click', function (e) {
            var inst = instancias[id];
            if (inst && inst.zoom > 1.0001) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
    }

    function conectarTeclado(id, elVisor) {
        elVisor.addEventListener('keydown', function (e) {
            var inst = instancias[id];
            if (!inst) return;
            switch (e.key) {
                case 'ArrowLeft':
                case 'ArrowUp':     inst.flipBook.flipPrev(); e.preventDefault(); break;
                case 'ArrowRight':
                case 'ArrowDown':   inst.flipBook.flipNext(); e.preventDefault(); break;
                case 'Home':        inst.flipBook.flip(0);                        break;
                case 'End':         inst.flipBook.flip(inst.totalPaginas - 1);   break;
                case '+':           hacerZoom(id, +1);                            break;
                case '-':           hacerZoom(id, -1);                            break;
                case 'f': case 'F': hacerFullscreen(id);                          break;
            }
        });
    }

    // ══════════════════════════════════════════════════════
    // TOAST / UTILS
    // ══════════════════════════════════════════════════════
    function toast(msg) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);'
            + 'background:#1e293b;color:#f1f5f9;padding:10px 20px;border-radius:8px;'
            + 'font-size:13px;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.4);'
            + 'pointer-events:none;opacity:1;transition:opacity .4s;';
        document.body.appendChild(t);
        setTimeout(function() { t.style.opacity='0'; }, 2000);
        setTimeout(function() { if(t.parentNode) t.remove(); }, 2500);
    }

    function setInfo(id, txt) { var e=document.getElementById('fbm-info-'+id);    if(e) e.textContent=txt; }
    function setTxt(id, txt)  { var e=document.querySelector('#fbm-cargando-'+id+' .fbm-cargando-texto'); if(e) e.textContent=txt; }
    function setProg(id, pct) { var e=document.getElementById('fbm-progreso-'+id); if(e) e.style.width=pct+'%'; }
    function mostrarError(id, msg) {
        var e=document.getElementById('fbm-cargando-'+id);
        if(e) e.innerHTML='<div class="fbm-error" style="max-width:360px;margin:auto;">⚠️ '+msg
            +'<br><small style="opacity:.7;font-size:11px;margin-top:6px;display:block;">Si el problema persiste, ve a <em>LeafBook PDF → Ajustes</em> para verificar la configuración.</small></div>';
        setInfo(id, 'Error');
    }

})();

// ══════════════════════════════════════════════════════════
// BÚSQUEDA EN PDF
// ══════════════════════════════════════════════════════════
var fbmTextoPorPagina = {};

function fbmIndexarTexto(id, pdfDoc) {
    fbmTextoPorPagina[id] = [];
    var total = pdfDoc.numPages;
    for (var i = 1; i <= total; i++) {
        (function(num) {
            pdfDoc.getPage(num)
            .then(function(pag){ return pag.getTextContent(); })
            .then(function(tc){
                fbmTextoPorPagina[id][num-1] =
                    tc.items.map(function(it){ return it.str; }).join(' ').toLowerCase();
            })
            .catch(function(){ fbmTextoPorPagina[id][num-1] = ''; });
        })(i);
    }
}

window.fbmLimpiarBusqueda = function(id) {
    var inp = document.getElementById('fbm-search-input-'+id);
    var res = document.getElementById('fbm-search-res-'+id);
    if (inp) inp.value = '';
    if (res) res.style.display = 'none';
    var inf = document.getElementById('fbm-search-info-'+id);
    if (inf) inf.textContent = '';
};

function fbmBuscar(id, termino) {
    var resEl   = document.getElementById('fbm-search-res-'+id);
    var listaEl = document.getElementById('fbm-search-res-lista-'+id);
    var infoEl  = document.getElementById('fbm-search-info-'+id);
    if (!resEl || !listaEl) return;

    termino = (termino||'').trim().toLowerCase();
    if (!termino) { resEl.style.display='none'; if(infoEl) infoEl.textContent=''; return; }

    var textos = fbmTextoPorPagina[id];
    if (!textos || !textos.length) { if(infoEl) infoEl.textContent='Indexando…'; return; }

    var hits = [];
    textos.forEach(function(txt,idx){ if(txt && txt.indexOf(termino)!==-1) hits.push(idx+1); });

    listaEl.innerHTML = '';
    if (!hits.length) {
        listaEl.innerHTML = '<span class="fbm-search-nada">Sin resultados para "<strong>'+termino+'</strong>"</span>';
        if (infoEl) infoEl.textContent = '0';
    } else {
        if (infoEl) infoEl.textContent = hits.length + ' pág.';
        hits.forEach(function(num) {
            var btn = document.createElement('button');
            btn.className   = 'fbm-search-res-btn';
            btn.textContent = 'Pág. ' + num;
            btn.onclick = function() {
                var inst = window._lbInstancias && window._lbInstancias[id];
                if (inst) inst.flipBook.flip(num-1);
                resEl.style.display = 'none';
            };
            listaEl.appendChild(btn);
        });
    }
    resEl.style.display = 'block';
}

document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.fbm-search-input').forEach(function(inp){
        var timer;
        inp.addEventListener('input', function(){
            clearTimeout(timer);
            var id = inp.dataset.id;
            timer = setTimeout(function(){ fbmBuscar(id, inp.value); }, 350);
        });
        inp.addEventListener('keydown', function(e){
            if (e.key === 'Escape') fbmLimpiarBusqueda(inp.dataset.id);
        });
    });
});
