/**
 * visor.js — LeafBook PDF v1.5.1
 *
 * FIXES v1.5.1:
 *  1. CAMBIO DE PÁGINA BLOQUEADO: El panLayer se mueve fuera del .fbm-visor
 *     (ahora es hijo del .fbm-visor-wrap) para que no interfiera con los
 *     eventos de click/drag de StPageFlip cuando zoom = 1x.
 *
 *  2. FULLSCREEN EN IFRAME: Se usa document.documentElement como host del
 *     fullscreen en lugar del .fbm-contenedor-externo. Dentro de un iframe
 *     el elemento raíz sí puede entrar a pantalla completa; un div interior
 *     no siempre lo logra. El visor se ajusta por JS al 100% del viewport.
 *
 *  3. ZOOM PIXELADO: renderPaginaHD ahora calcula anchoPag y altoPag según
 *     el tamaño real del visor en ese momento (fullscreen o normal),
 *     garantizando que el canvas HD tenga la resolución correcta.
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

    // ══════════════════════════════════════════════════════
    // INIT — patrón defensivo para iframe Y shortcode
    // ══════════════════════════════════════════════════════
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
        document.addEventListener('DOMContentLoaded', window.fbmIniciarTodos);
    } else {
        setTimeout(window.fbmIniciarTodos, 0);
    }

    // ══════════════════════════════════════════════════════
    // INICIAR
    // ══════════════════════════════════════════════════════
    function iniciar(id, datos, elVisor) {
        if (typeof pdfjsLib === 'undefined') {
            return mostrarError(id, 'PDF.js no cargó. Verifica tu conexión a internet.');
        }
        if (typeof St === 'undefined' || typeof St.PageFlip === 'undefined') {
            return mostrarError(id, 'page-flip.js no cargó. Verifica tu conexión a internet.');
        }

        pdfjsLib.GlobalWorkerOptions.workerSrc = datos.workerSrc;

        var ancho      = parseInt(datos.ancho,     10) || 900;
        var alto       = parseInt(datos.alto,      10) || 600;
        var escalaBase = Math.min(2, Math.max(1,   parseFloat(datos.escala)   || 1.5));
        var calidad    = Math.min(1, Math.max(0.6, parseFloat(datos.calidad)  || 0.85));

        setProg(id, 5);
        setTxt(id, 'Conectando…');

        function cargarConRetry(url, intento) {
            intento = intento || 0;
            return pdfjsLib.getDocument({ url: url, withCredentials: false }).promise
                .catch(function (e) {
                    if (intento < 2 && e.message && /fetch|network|Unexpected/i.test(e.message)) {
                        setTxt(id, 'Reintentando… (' + (intento + 1) + '/2)');
                        return new Promise(function (ok) { setTimeout(ok, 1500 * (intento + 1)); })
                            .then(function () { return cargarConRetry(url, intento + 1); });
                    }
                    throw e;
                });
        }

        var _pdfDoc = null;

        cargarConRetry(datos.pdfUrl)
            .then(function (doc) {
                _pdfDoc = doc;
                var total = doc.numPages;
                setTxt(id, 'Procesando ' + total + ' páginas…');
                return renderTodas(doc, total, ancho, alto, id, escalaBase, calidad);
            })
            .then(function (dataUrls) {
                setTxt(id, 'Preparando páginas…');
                return precargarImagenes(dataUrls, id);
            })
            .then(function (imageSources) {
                setTxt(id, 'Montando visor…');
                crearFlipbook(id, imageSources, ancho, alto, elVisor, datos, _pdfDoc, escalaBase);
            })
            .catch(function (e) {
                console.error('[LeafBook PDF]', e);
                var msg = '';
                if      (e.name === 'MissingPDFException')                   msg = 'Archivo no encontrado (404). Verifica que el PDF existe en la Biblioteca de Medios.';
                else if (e.name === 'InvalidPDFException')                    msg = 'El archivo no es un PDF válido.';
                else if (e.message && /fetch|network|load/i.test(e.message)) msg = 'No se pudo descargar el PDF. Verifica la conexión o los permisos del archivo.';
                else                                                          msg = 'Error al cargar: ' + (e.message || e);
                mostrarError(id, msg);
            });
    }

    // ══════════════════════════════════════════════════════
    // PRE-CARGA
    // ══════════════════════════════════════════════════════
    function precargarImagenes(dataUrls, id) {
        var total    = dataUrls.length;
        var cargadas = 0;
        return Promise.all(dataUrls.map(function (src, idx) {
            return new Promise(function (resolve) {
                var img    = new Image();
                img.onload = function () {
                    cargadas++;
                    setProg(id, Math.round(90 + (cargadas / total) * 9));
                    resolve(src);
                };
                img.onerror = function () {
                    console.warn('[LeafBook PDF] Error precargando imagen', idx);
                    resolve(src);
                };
                img.src = src;
            });
        }));
    }

    // ══════════════════════════════════════════════════════
    // RENDER BASE — escala moderada para carga rápida
    // El HD se genera solo al hacer zoom.
    // ══════════════════════════════════════════════════════
    function renderTodas(doc, total, anchoVisor, altoVisor, id, escalaBase, calidad) {
        var imgs     = [];
        var chain    = Promise.resolve();
        var anchoPag = Math.floor(anchoVisor / 2);

        for (var i = 1; i <= total; i++) {
            (function (n) {
                chain = chain.then(function () {
                    return renderPagina(doc, n, anchoPag, altoVisor, escalaBase, calidad)
                        .then(function (dataUrl) {
                            imgs.push(dataUrl);
                            setProg(id, Math.round(5 + (n / total) * 80));
                            setTxt(id, 'Página ' + n + ' de ' + total + '…');
                        });
                });
            })(i);
        }
        return chain.then(function () { return imgs; });
    }

    function renderPagina(doc, n, anchoPag, altoPag, escalaBase, calidad) {
        return doc.getPage(n).then(function (pag) {
            var vp0      = pag.getViewport({ scale: 1 });
            var fitScale = Math.min(anchoPag / vp0.width, altoPag / vp0.height);
            var escalaFinal = fitScale * escalaBase;
            var maxScale    = Math.sqrt(10000000 / (vp0.width * vp0.height));
            escalaFinal     = Math.min(escalaFinal, maxScale);

            var vp     = pag.getViewport({ scale: escalaFinal });
            var canvas = document.createElement('canvas');
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
    function crearFlipbook(id, imageSources, ancho, alto, elVisor, datos, pdfDoc, escalaBase) {
        setProg(id, 99);

        var anchoPag = Math.floor(ancho / 2);

        var libroExistente = document.getElementById('fbm-book-' + id);
        if (libroExistente) libroExistente.remove();
        var panExistente = document.getElementById('fbm-pan-' + id);
        if (panExistente) panExistente.remove();

        // El libro va dentro del .fbm-visor
        var book = document.createElement('div');
        book.id        = 'fbm-book-' + id;
        book.className = 'fbm-libro';
        book.style.cssText = 'position:relative;width:' + ancho + 'px;height:' + alto + 'px;margin:0 auto;flex-shrink:0;';
        elVisor.appendChild(book);

        // FIX BUG 1: El panLayer va en .fbm-visor-wrap (padre del visor),
        // NO dentro del .fbm-visor. Así StPageFlip puede recibir los clicks
        // de flip normalmente cuando zoom = 1x, porque el panLayer está en
        // un contenedor hermano con pointer-events:none por defecto.
        var visorWrap = document.getElementById('fbm-visor-wrap-' + id);
        var panParent = visorWrap || elVisor;

        var panLayer = document.createElement('div');
        panLayer.id        = 'fbm-pan-' + id;
        panLayer.className = 'fbm-pan-layer';
        panLayer.setAttribute('aria-hidden', 'true');
        panParent.appendChild(panLayer);

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {

                var fb = new St.PageFlip(book, {
                    width:               anchoPag,
                    height:              alto,
                    size:                'fixed',
                    drawShadow:          true,
                    flippingTime:        600,
                    usePortrait:         true,
                    autoSize:            false,
                    maxShadowOpacity:    0.4,
                    showCover:           true,
                    mobileScrollSupport: false,
                    startZIndex:         0,
                });

                instancias[id] = {
                    flipBook:     fb,
                    imagenes:     imageSources,
                    imagenesBase: imageSources.slice(),
                    pdfDoc:       pdfDoc,
                    libroEl:      book,
                    panLayer:     panLayer,
                    zoom:         1,
                    panX:         0,
                    panY:         0,
                    isPanning:    false,
                    totalPaginas: imageSources.length,
                    ancho:        ancho,
                    alto:         alto,
                    escalaBase:   escalaBase,
                    hdTimer:      null,
                    hdZoom:       0,
                };

                fb.on('flip', function (e) {
                    var inst = instancias[id];
                    if (inst) { inst.panX = 0; inst.panY = 0; inst.isPanning = false; }
                    setInfo(id, 'Pág. ' + (e.data + 1) + ' / ' + imageSources.length);
                    resaltarMiniatura(id, e.data);
                    actualizarVista(id);
                });

                fb.on('changeOrientation', function () { refrescarFlipbook(id); });

                fb.on('init', function () {
                    var spin = document.getElementById('fbm-cargando-' + id);
                    if (spin) spin.style.display = 'none';
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
                fb.loadFromImages(imageSources);
            });
        });
    }

    // ══════════════════════════════════════════════════════
    // MINIATURAS
    // ══════════════════════════════════════════════════════
    function buildMiniaturas(id, imgs) {
        var grid = document.getElementById('fbm-min-grid-' + id);
        if (!grid) return;
        imgs.forEach(function (imgObj, idx) {
            var thumb       = document.createElement('div');
            thumb.className = 'fbm-miniatura';
            thumb.title     = 'Página ' + (idx + 1);
            var img         = document.createElement('img');
            img.src         = imgObj.src || imgObj;
            img.loading     = 'lazy';
            img.draggable   = false;
            var num         = document.createElement('span');
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
                    case 'primera':    if (inst) inst.flipBook.flip(0);                      break;
                    case 'anterior':   if (inst) inst.flipBook.flipPrev();                   break;
                    case 'siguiente':  if (inst) inst.flipBook.flipNext();                   break;
                    case 'ultima':     if (inst) inst.flipBook.flip(inst.totalPaginas - 1); break;
                    case 'zoom-mas':   hacerZoom(id, +1);                                    break;
                    case 'zoom-menos': hacerZoom(id, -1);                                    break;
                    case 'miniaturas': toggleMiniaturas(id);                                 break;
                    case 'fullscreen': hacerFullscreen(id);                                  break;
                    case 'compartir':  hacerCompartir(btn);                                  break;
                    case 'imprimir':   hacerImprimir(btn);                                   break;
                }
            });
        });
    }

    // ══════════════════════════════════════════════════════
    // RE-RENDER HD AL ZOOM
    // FIX BUG 3: anchoPag y altoPag se calculan desde el tamaño real
    // del visor en el momento del render (puede ser fullscreen).
    // ══════════════════════════════════════════════════════
    function programarReRenderHD(id) {
        var inst = instancias[id];
        if (!inst) return;
        if (inst.hdTimer) { clearTimeout(inst.hdTimer); inst.hdTimer = null; }

        if (inst.zoom <= 1.0001) {
            if (inst.hdZoom > 0 && inst.imagenesBase) {
                inst.hdZoom = 0;
                inst.flipBook.loadFromImages(inst.imagenesBase);
            }
            return;
        }

        inst.hdTimer = setTimeout(function () {
            inst.hdTimer = null;
            reRenderizarHD(id);
        }, 400);
    }

    function reRenderizarHD(id) {
        var inst = instancias[id];
        if (!inst || !inst.pdfDoc || inst.zoom <= 1.0001) return;
        if (inst.hdZoom === inst.zoom) return;

        // FIX: usar tamaño real del visor, no el ancho fijo del flipbook
        var visorEl   = getVisorEl(id);
        var modoSimple = inst.flipBook && typeof inst.flipBook.getOrientation === 'function'
            ? inst.flipBook.getOrientation() === 'portrait'
            : inst.ancho < 600;

        // En fullscreen el visor es más grande → más píxeles HD disponibles
        var anchoVisor = (visorEl && visorEl.clientWidth  > 0) ? visorEl.clientWidth  : inst.ancho;
        var altoVisor  = (visorEl && visorEl.clientHeight > 0) ? visorEl.clientHeight : inst.alto;
        var anchoPag   = modoSimple ? anchoVisor : Math.floor(anchoVisor / 2);

        var zoomActual = inst.zoom;
        var dpr        = Math.min(window.devicePixelRatio || 1, 2);
        // Escala HD = fitScale × zoom × DPR → siempre más nítido que la base
        var escalaHD   = inst.escalaBase * zoomActual * dpr;

        var paginasVisibles = getPaginasVisibles(inst);
        var total = inst.totalPaginas;
        var ordenRender = [];
        for (var i = 0; i < total; i++) {
            if (paginasVisibles.indexOf(i) !== -1) ordenRender.unshift(i);
            else ordenRender.push(i);
        }

        var nuevasImagenes = inst.imagenesBase.slice();
        var zoomCapturado  = zoomActual;
        var chain          = Promise.resolve();

        ordenRender.forEach(function (n) {
            chain = chain.then(function () {
                if (inst.zoom !== zoomCapturado || inst.zoom <= 1.0001) {
                    return Promise.reject('zoom-changed');
                }
                return renderPaginaHD(inst.pdfDoc, n + 1, anchoPag, altoVisor, escalaHD)
                    .then(function (dataUrl) {
                        if (inst.zoom !== zoomCapturado) return;
                        nuevasImagenes[n] = dataUrl;
                        if (paginasVisibles.indexOf(n) !== -1) {
                            inst.flipBook.loadFromImages(nuevasImagenes.slice());
                        }
                    });
            });
        });

        chain.then(function () {
            if (inst.zoom === zoomCapturado && inst.zoom > 1.0001) {
                inst.hdZoom = zoomCapturado;
                inst.flipBook.loadFromImages(nuevasImagenes.slice());
                inst.imagenes = nuevasImagenes;
            }
        }).catch(function (reason) {
            if (reason !== 'zoom-changed') console.warn('[LeafBook HD]', reason);
        });
    }

    function renderPaginaHD(doc, n, anchoPag, altoPag, escalaHD) {
        return doc.getPage(n).then(function (pag) {
            var vp0      = pag.getViewport({ scale: 1 });
            var fitScale = Math.min(anchoPag / vp0.width, altoPag / vp0.height);
            // 40MP límite para zoom HD sin agotar memoria
            var maxScale    = Math.sqrt(40000000 / (vp0.width * vp0.height));
            var escalaFinal = Math.min(fitScale * escalaHD, maxScale);
            var vp = pag.getViewport({ scale: escalaFinal });
            var canvas = document.createElement('canvas');
            canvas.width  = Math.round(vp.width);
            canvas.height = Math.round(vp.height);
            var ctx = canvas.getContext('2d', { alpha: false });
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            return pag.render({ canvasContext: ctx, viewport: vp }).promise
                .then(function () { return canvas.toDataURL('image/png'); });
        });
    }

    function getPaginasVisibles(inst) {
        if (!inst || !inst.flipBook) return [0];
        var idx = 0;
        if (typeof inst.flipBook.getCurrentPageIndex === 'function') {
            idx = inst.flipBook.getCurrentPageIndex() || 0;
        }
        var visibles = [idx];
        if (idx + 1 < inst.totalPaginas) visibles.push(idx + 1);
        return visibles;
    }

    // ══════════════════════════════════════════════════════
    // ZOOM
    // ══════════════════════════════════════════════════════
    var niveles = [1, 1.25, 1.5, 2, 2.5, 3];

    function getLibroEl(id)    { return document.getElementById('fbm-book-'       + id); }
    function getWrapEl(id)     { return document.getElementById('fbm-wrap-'       + id); }
    function getVisorEl(id)    { return document.getElementById('fbm-visor-'      + id); }
    function getPanLayerEl(id) { return document.getElementById('fbm-pan-'        + id); }

    function esFullscreenId(id) {
        var fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement;
        // En iframe el host es document.documentElement, no el wrap
        return !!fsEl && (fsEl === getWrapEl(id) || fsEl === document.documentElement);
    }

    function resetPan(id) {
        var inst = instancias[id];
        if (!inst) return;
        inst.panX = 0; inst.panY = 0; inst.isPanning = false;
        var panLayer = getPanLayerEl(id);
        if (panLayer) panLayer.classList.remove('is-dragging');
    }

    function sincronizarModoPan(id) {
        var inst     = instancias[id];
        var panLayer = getPanLayerEl(id);
        var visor    = getVisorEl(id);
        var activo   = !!(inst && inst.zoom > 1.0001);
        if (panLayer) {
            panLayer.classList.toggle('is-active', activo);
            panLayer.setAttribute('aria-hidden', activo ? 'false' : 'true');
        }
        if (visor) visor.classList.toggle('fbm-visor--zoomed', activo);
        if (!activo && inst) inst.isPanning = false;
    }

    // FIX BUG 2: En iframe, el fullscreen se aplica a document.documentElement.
    // La clase .fbm-wrap--fullscreen activa los estilos JS que ajustan el visor.
    function sincronizarLayoutViewport(id) {
        var inst  = instancias[id];
        var wrap  = getWrapEl(id);
        var visor = getVisorEl(id);
        var book  = getLibroEl(id);
        if (!inst || !wrap || !visor || !book) return;

        var esFS = esFullscreenId(id);
        wrap.classList.toggle('fbm-wrap--fullscreen', esFS);

        if (!esFS) {
            wrap.style.cssText  = 'max-width:' + inst.ancho + 'px;margin:0 auto;';
            visor.style.height  = '';
            book.style.maxWidth = 'none';
            return;
        }

        // En fullscreen: el wrap ocupa todo el viewport
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        wrap.style.cssText  = 'width:' + vw + 'px;height:' + vh + 'px;max-width:none;margin:0;';
        book.style.maxWidth = 'none';

        var usados    = 0;
        var controles = document.getElementById('fbm-controles-' + id);
        var infoBar   = wrap.querySelector('.fbm-info-bar');
        if (controles) usados += controles.offsetHeight;
        if (infoBar)   usados += infoBar.offsetHeight;
        visor.style.height = Math.max(260, vh - usados) + 'px';
        visor.style.width  = '100%';
    }

    // Offset para centrar portada / contraportada (showCover:true desplaza)
    function getOffsetPaginaUnica(inst) {
        if (!inst || !inst.flipBook) return 0;
        if (typeof inst.flipBook.getOrientation !== 'function') return 0;
        if (inst.flipBook.getOrientation() === 'portrait') return 0;

        var idx       = typeof inst.flipBook.getCurrentPageIndex === 'function'
                        ? (inst.flipBook.getCurrentPageIndex() || 0) : 0;
        var pageWidth = Math.floor(inst.ancho / 2);

        if (idx === 0) return -Math.round(pageWidth / 2);
        if (idx >= inst.totalPaginas - 1 && inst.totalPaginas % 2 === 0) return Math.round(pageWidth / 2);
        return 0;
    }

    function clampPan(id) {
        var inst  = instancias[id];
        var visor = getVisorEl(id);
        if (!inst || !visor) return;

        if (inst.zoom <= 1.0001) { inst.panX = 0; inst.panY = 0; return; }

        var viewportW = visor.clientWidth  || inst.ancho;
        var viewportH = visor.clientHeight || inst.alto;
        var scaledW   = inst.ancho * inst.zoom;
        var scaledH   = inst.alto  * inst.zoom;

        if (scaledW <= viewportW) {
            inst.panX = 0;
        } else {
            var maxPanX = Math.round((scaledW - viewportW) / 2);
            inst.panX   = Math.max(-maxPanX, Math.min(maxPanX, inst.panX || 0));
        }

        if (scaledH <= viewportH) {
            inst.panY = 0;
        } else {
            var maxPanY = Math.round((scaledH - viewportH) / 2);
            inst.panY   = Math.max(-maxPanY, Math.min(maxPanY, inst.panY || 0));
        }
    }

    function aplicarTransformacion(id) {
        var inst = instancias[id];
        var book = getLibroEl(id);
        if (!inst || !book) return;

        clampPan(id);
        sincronizarModoPan(id);

        var offsetX = getOffsetPaginaUnica(inst);
        var tx      = Math.round(offsetX + (inst.panX || 0));
        var ty      = Math.round(inst.panY || 0);

        book.style.transform       = 'translate3d(' + tx + 'px,' + ty + 'px,0) scale(' + inst.zoom + ')';
        book.style.transformOrigin = 'center top';
        book.style.transition      = inst.isPanning ? 'none' : 'transform 0.2s ease';
    }

    function actualizarVista(id) {
        var inst = instancias[id];
        if (!inst) return;

        sincronizarLayoutViewport(id);

        var visorEl  = getVisorEl(id);
        var altoBase = inst.alto;
        try {
            if (inst.flipBook && typeof inst.flipBook.getBoundsRect === 'function') {
                var b = inst.flipBook.getBoundsRect();
                if (b && b.height > 10) altoBase = b.height;
            }
        } catch (e) {}

        if (visorEl && !esFullscreenId(id)) {
            visorEl.style.height = Math.round(altoBase) + 'px';
        }

        aplicarTransformacion(id);
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
        if (idx === -1) idx = 0;
        idx       = Math.max(0, Math.min(niveles.length - 1, idx + dir));
        inst.zoom = niveles[idx];

        if (inst.zoom <= 1.0001) resetPan(id);
        else clampPan(id);

        actualizarVista(id);

        var zEl = document.getElementById('fbm-zoom-' + id);
        if (zEl) zEl.textContent = Math.round(inst.zoom * 100) + '%';

        programarReRenderHD(id);
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
    // FIX BUG 2: En iframe se usa document.documentElement como host.
    // Un div interior del iframe no siempre obtiene fullscreen real.
    // ══════════════════════════════════════════════════════
    function estaEnIframe() {
        try { return window.self !== window.top; } catch (e) { return true; }
    }

    function hacerFullscreen(id) {
        var enFS = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement);
        if (enFS) {
            var ex = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen;
            if (ex) ex.call(document);
            return;
        }

        // En iframe: usar documentElement para garantizar fullscreen real
        var host = estaEnIframe()
            ? document.documentElement
            : (getWrapEl(id) || document.documentElement);

        try {
            var req = host.requestFullscreen || host.webkitRequestFullscreen || host.mozRequestFullScreen;
            if (req) req.call(host);
        } catch (e) {
            window.open(window.location.href, '_blank');
        }
    }

    ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (ev) {
        document.addEventListener(ev, function () {
            document.querySelectorAll('.fbm-btn-fs').forEach(function (btn) {
                var idBtn  = btn.dataset.id;
                var activo = esFullscreenId(idBtn);
                btn.classList.toggle('fs-activo', activo);
                btn.title = activo ? 'Salir de pantalla completa' : 'Pantalla completa (F)';
            });

            document.querySelectorAll('.fbm-visor').forEach(function (v) {
                var id2  = v.dataset.id;
                var inst = instancias[id2];
                if (!inst) return;

                // Al salir de fullscreen: recentrar
                if (!esFullscreenId(id2)) {
                    inst.panX = 0;
                    inst.panY = 0;
                }

                sincronizarLayoutViewport(id2);
                [0, 80, 220].forEach(function (delay) {
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
        var enIframe = estaEnIframe();
        if (!enIframe && navigator.share) {
            navigator.share({ title: titulo, url: url }).catch(function () {});
        } else {
            var copiar = function (texto) {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(texto)
                        .then(function () { toast('🔗 Enlace copiado al portapapeles'); })
                        .catch(function () { prompt('Copia este enlace:', texto); });
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
    // SWIPE / TECLADO / ARRASTRE
    // ══════════════════════════════════════════════════════
    function conectarSwipe(id, elVisor, fb) {
        var sx = 0, sy = 0;
        elVisor.addEventListener('touchstart', function (e) {
            var inst = instancias[id];
            if (inst && inst.zoom > 1.0001) return;
            sx = e.touches[0].clientX;
            sy = e.touches[0].clientY;
        }, { passive: true });
        elVisor.addEventListener('touchend', function (e) {
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
            inst.isPanning  = true;
            inst.panStartX  = e.clientX;
            inst.panStartY  = e.clientY;
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
    // UTILIDADES
    // ══════════════════════════════════════════════════════
    function toast(msg) {
        var t = document.createElement('div');
        t.textContent   = msg;
        t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);'
            + 'background:#1e293b;color:#f1f5f9;padding:10px 20px;border-radius:8px;'
            + 'font-size:13px;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.4);'
            + 'pointer-events:none;opacity:1;transition:opacity .4s;';
        document.body.appendChild(t);
        setTimeout(function () { t.style.opacity = '0'; }, 2000);
        setTimeout(function () { if (t.parentNode) t.remove(); }, 2500);
    }

    function setInfo(id, txt) { var e = document.getElementById('fbm-info-'     + id); if (e) e.textContent = txt; }
    function setTxt(id,  txt) { var e = document.querySelector('#fbm-cargando-' + id + ' .fbm-cargando-texto'); if (e) e.textContent = txt; }
    function setProg(id, pct) { var e = document.getElementById('fbm-progreso-' + id); if (e) e.style.width = pct + '%'; }

    function mostrarError(id, msg) {
        var e = document.getElementById('fbm-cargando-' + id);
        if (e) e.innerHTML = '<div class="fbm-error" style="max-width:360px;margin:auto;">⚠️ ' + msg
            + '<br><small style="opacity:.7;font-size:11px;margin-top:6px;display:block;">Si el problema persiste, ve a <em>LeafBook PDF → Ajustes</em> para verificar la configuración.</small></div>';
        setInfo(id, 'Error');
    }

})();