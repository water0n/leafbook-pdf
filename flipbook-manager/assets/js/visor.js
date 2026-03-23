/**
 * visor.js — LeafBook PDF v1.4.11
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
            return renderTodas(doc, total, ancho, alto, id);
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
    function renderTodas(doc, total, anchoVisor, altoVisor, id) {
        var imgs     = [];
        var chain    = Promise.resolve();
        var anchoPag = Math.floor(anchoVisor / 2);

        for (var i = 1; i <= total; i++) {
            (function (n) {
                chain = chain.then(function () {
                    return renderPagina(doc, n, anchoPag, altoVisor).then(function (dataUrl) {
                        imgs.push(dataUrl);
                        setProg(id, Math.round(5 + (n / total) * 80));
                        setTxt(id, 'Página ' + n + ' de ' + total + '…');
                    });
                });
            })(i);
        }
        return chain.then(function () { return imgs; });
    }

    function renderPagina(doc, n, anchoPag, altoPag) {
        return doc.getPage(n).then(function (pag) {
            var vp0    = pag.getViewport({ scale: 1 });
            var escala = Math.min(anchoPag / vp0.width, altoPag / vp0.height) * 1.5;
            var vp     = pag.getViewport({ scale: escala });

            var canvas  = document.createElement('canvas');
            canvas.width  = vp.width;
            canvas.height = vp.height;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            return pag.render({ canvasContext: ctx, viewport: vp }).promise
                .then(function () { return canvas.toDataURL('image/jpeg', 0.88); });
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
        book.style.cssText = 'position:relative;width:100%;max-width:' + ancho + 'px;margin:0 auto;';
        elVisor.appendChild(book);

        // Double rAF: garantiza layout completo antes de que StPageFlip lea getBoundingClientRect
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {

                var minPageWidth  = Math.max(160, Math.round(anchoPag * 0.42));
                var minPageHeight = Math.max(220, Math.round(alto * (minPageWidth / anchoPag)));

                var fb = new St.PageFlip(book, {
                    width:               anchoPag,
                    height:              alto,
                    size:                'stretch',
                    minWidth:            minPageWidth,
                    maxWidth:            anchoPag,
                    minHeight:           minPageHeight,
                    maxHeight:           alto,
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
                    zoom:         1,
                    totalPaginas: imageSources.length,
                    ancho:        ancho,
                    alto:         alto,
                };

                fb.on('flip', function (e) {
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
    var niveles = [0.5, 0.6, 0.75, 1, 1.25, 1.5, 2];

    function getLibroEl(id) {
        return document.getElementById('fbm-book-' + id);
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

    function actualizarVista(id) {
        var inst = instancias[id];
        if (!inst) return;

        var book = getLibroEl(id);
        var visorEl = document.getElementById('fbm-visor-' + id);
        var bounds = inst.flipBook && typeof inst.flipBook.getBoundsRect === 'function'
            ? inst.flipBook.getBoundsRect()
            : null;
        var altoBase = bounds && bounds.height ? bounds.height : inst.alto;
        var offsetX = getOffsetPaginaUnica(inst, bounds);

        if (book) {
            book.style.transform = 'translateX(' + offsetX + 'px) scale(' + inst.zoom + ')';
            book.style.transformOrigin = 'center top';
            book.style.transition = 'transform 0.2s ease';
        }

        if (visorEl) {
            visorEl.style.height = Math.round(altoBase * inst.zoom) + 'px';
        }
    }

    function refrescarFlipbook(id) {
        var inst = instancias[id];
        if (!inst || !inst.flipBook || typeof inst.flipBook.update !== 'function') return;
        requestAnimationFrame(function () {
            inst.flipBook.update();
            requestAnimationFrame(function () {
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
        try {
            if (!document.fullscreenElement && !document.webkitFullscreenElement) {
                var req = document.documentElement.requestFullscreen
                       || document.documentElement.webkitRequestFullscreen
                       || document.documentElement.mozRequestFullScreen;
                if (req) req.call(document.documentElement);
            } else {
                var ex = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen;
                if (ex) ex.call(document);
            }
        } catch(e) { window.open(window.location.href, '_blank'); }
    }

    ['fullscreenchange','webkitfullscreenchange'].forEach(function(ev) {
        document.addEventListener(ev, function () {
            var esFS = !!(document.fullscreenElement || document.webkitFullscreenElement);
            document.querySelectorAll('.fbm-btn-fs').forEach(function (btn) {
                btn.classList.toggle('fs-activo', esFS);
                btn.title = esFS ? 'Salir de pantalla completa' : 'Pantalla completa (F)';
            });
            document.querySelectorAll('.fbm-visor').forEach(function(v) {
                var id2  = v.dataset.id;
                var inst = instancias[id2];
                if (!inst) return;
                if (!esFS) v.style.height = inst.alto + 'px';
                refrescarFlipbook(id2);
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
            sx = e.touches[0].clientX; sy = e.touches[0].clientY;
        }, { passive: true });
        elVisor.addEventListener('touchend', function(e) {
            var dx = e.changedTouches[0].clientX - sx;
            var dy = e.changedTouches[0].clientY - sy;
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                if (dx < 0) fb.flipNext(); else fb.flipPrev();
            }
        }, { passive: true });
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
