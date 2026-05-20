(function () {
    'use strict';

    var instances = {};

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function getConfig(pdfId, node) {
        var globalConfig = window['fbmData_' + pdfId] || {};
        return {
            id: pdfId,
            pdfUrl: node.getAttribute('data-pdf') || globalConfig.pdfUrl,
            workerSrc: node.getAttribute('data-worker-src') || globalConfig.workerSrc || '',
            height: Number(node.getAttribute('data-alto') || globalConfig.alto || 640)
        };
    }

    function LeafBookViewer(node) {
        this.node = node;
        this.id = node.getAttribute('data-instance-id') || node.getAttribute('data-id');
        this.pdfId = node.getAttribute('data-pdf-id') || node.getAttribute('data-id');
        this.config = getConfig(this.pdfId, node);
        this.pdf = null;
        this.page = null;
        this.pageNumber = 1;
        this.pageCount = 0;
        this.zoom = 1;
        this.minZoom = 1;
        this.maxZoom = 4;
        this.fitScale = 1;
        this.currentCssScale = 1;
        this.renderTask = null;
        this.renderToken = 0;
        this.isDragging = false;
        this.dragStart = null;
        this.turnStart = null;
        this.touchStart = null;
        this.touchPanStart = null;
        this.pinchStart = null;

        this.stage = node.querySelector('.fbm-stage');
        this.shell = node.querySelector('.fbm-page-shell');
        this.canvas = node.querySelector('.fbm-page-canvas');
        this.links = node.querySelector('.fbm-link-layer');
        this.loader = node.querySelector('.fbm-cargando');
        this.info = node.querySelector('.fbm-pagina-info');
        this.ctx = this.canvas.getContext('2d', { alpha: false });

        this.onResize = this.debounce(this.render.bind(this), 120);
    }

    LeafBookViewer.prototype.init = function () {
        this.node.setAttribute('data-leafbook-ready', '1');

        if (!window.pdfjsLib) {
            this.showError('PDF.js no esta disponible.');
            return;
        }

        if (this.config.workerSrc) {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = this.config.workerSrc;
        }

        this.bindEvents();
        this.load();
    };

    LeafBookViewer.prototype.load = function () {
        var self = this;

        window.pdfjsLib.getDocument({
            url: this.config.pdfUrl,
            disableAutoFetch: false,
            disableStream: false
        }).promise.then(function (pdf) {
            self.pdf = pdf;
            self.pageCount = pdf.numPages;
            self.node.classList.add('is-ready');
            self.updateInfo();
            return self.goTo(1);
        }).catch(function () {
            self.showError('No se pudo abrir el PDF.');
        });
    };

    LeafBookViewer.prototype.bindEvents = function () {
        var self = this;

        this.node.addEventListener('click', function (event) {
            var actionNode = event.target.closest('[data-accion]');
            if (!actionNode || !self.node.contains(actionNode)) {
                return;
            }

            event.preventDefault();
            self.handleAction(actionNode.getAttribute('data-accion'));
        });

        this.node.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') {
                self.prev();
            } else if (event.key === 'ArrowRight' || event.key === ' ') {
                event.preventDefault();
                self.next();
            } else if (event.key === '+' || event.key === '=') {
                self.setZoom(self.zoom * 1.15, self.viewportCenterAnchor());
            } else if (event.key === '-') {
                self.setZoom(self.zoom / 1.15, self.viewportCenterAnchor());
            } else if (event.key === '0') {
                self.setZoom(1, self.viewportCenterAnchor());
            } else if (event.key === 'Home') {
                self.goTo(1);
            } else if (event.key === 'End') {
                self.goTo(self.pageCount);
            } else if (event.key === 'Escape' && document.fullscreenElement) {
                document.exitFullscreen();
            }
        });

        this.stage.addEventListener('wheel', function (event) {
            event.preventDefault();
            var factor = Math.exp(-event.deltaY * 0.0012);
            self.setZoom(self.zoom * factor, self.pointerAnchor(event.clientX, event.clientY));
        }, { passive: false });

        this.stage.addEventListener('pointerdown', function (event) {
            if (event.pointerType === 'touch' || event.button !== 0 || event.target.closest('a')) {
                return;
            }

            self.node.focus({ preventScroll: true });

            if (self.zoom > 1.02) {
                self.isDragging = true;
                self.dragStart = {
                    x: event.clientX,
                    y: event.clientY,
                    left: self.stage.scrollLeft,
                    top: self.stage.scrollTop
                };
                self.stage.setPointerCapture(event.pointerId);
                self.stage.classList.add('is-dragging');
                return;
            }

            self.turnStart = {
                x: event.clientX,
                y: event.clientY,
                time: Date.now()
            };
            self.stage.setPointerCapture(event.pointerId);
        });

        this.stage.addEventListener('pointermove', function (event) {
            if (!self.isDragging || !self.dragStart) {
                return;
            }

            self.stage.scrollLeft = self.dragStart.left - (event.clientX - self.dragStart.x);
            self.stage.scrollTop = self.dragStart.top - (event.clientY - self.dragStart.y);
        });

        this.stage.addEventListener('pointerup', function (event) {
            if (self.isDragging) {
                self.stopDragging();
                return;
            }

            if (!self.turnStart) {
                return;
            }

            var dx = event.clientX - self.turnStart.x;
            var dy = event.clientY - self.turnStart.y;
            var elapsed = Date.now() - self.turnStart.time;
            self.turnStart = null;

            if (elapsed < 900 && Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.4) {
                if (dx < 0) {
                    self.next();
                } else {
                    self.prev();
                }
            }
        });

        ['pointercancel', 'pointerleave'].forEach(function (name) {
            self.stage.addEventListener(name, function () {
                self.stopDragging();
                self.turnStart = null;
            });
        });

        this.stage.addEventListener('touchstart', function (event) {
            self.node.focus({ preventScroll: true });

            if (event.touches.length === 1) {
                self.touchStart = {
                    x: event.touches[0].clientX,
                    y: event.touches[0].clientY,
                    time: Date.now()
                };
                self.touchPanStart = self.zoom > 1.02 ? {
                    x: event.touches[0].clientX,
                    y: event.touches[0].clientY,
                    left: self.stage.scrollLeft,
                    top: self.stage.scrollTop
                } : null;
            } else if (event.touches.length === 2) {
                self.pinchStart = {
                    distance: self.touchDistance(event),
                    zoom: self.zoom
                };
                self.touchStart = null;
                self.touchPanStart = null;
            }
        }, { passive: true });

        this.stage.addEventListener('touchmove', function (event) {
            if (event.touches.length === 2 && self.pinchStart) {
                event.preventDefault();
                var distance = self.touchDistance(event);
                var ratio = distance / self.pinchStart.distance;
                var center = self.touchCenter(event);
                self.setZoom(self.pinchStart.zoom * ratio, self.pointerAnchor(center.x, center.y));
                return;
            }

            if (event.touches.length === 1 && self.touchPanStart && self.zoom > 1.02) {
                event.preventDefault();
                self.stage.scrollLeft = self.touchPanStart.left - (event.touches[0].clientX - self.touchPanStart.x);
                self.stage.scrollTop = self.touchPanStart.top - (event.touches[0].clientY - self.touchPanStart.y);
            }
        }, { passive: false });

        this.stage.addEventListener('touchend', function (event) {
            if (self.pinchStart && event.touches.length < 2) {
                self.pinchStart = null;
                return;
            }

            if (self.touchPanStart) {
                self.touchPanStart = null;
                self.touchStart = null;
                return;
            }

            if (!self.touchStart || !event.changedTouches.length) {
                return;
            }

            var changed = event.changedTouches[0];
            var dx = changed.clientX - self.touchStart.x;
            var dy = changed.clientY - self.touchStart.y;
            var elapsed = Date.now() - self.touchStart.time;
            self.touchStart = null;

            if (self.zoom > 1.02 || elapsed > 700 || Math.abs(dx) < 48 || Math.abs(dx) < Math.abs(dy) * 1.5) {
                return;
            }

            if (dx < 0) {
                self.next();
            } else {
                self.prev();
            }
        }, { passive: true });

        this.stage.addEventListener('dblclick', function (event) {
            event.preventDefault();
            self.setZoom(self.zoom > 1.05 ? 1 : 2, self.pointerAnchor(event.clientX, event.clientY));
        });

        document.addEventListener('fullscreenchange', function () {
            self.node.classList.toggle('is-fullscreen', document.fullscreenElement === self.node);
            self.render({ preserveScroll: true });
        });

        window.addEventListener('resize', this.onResize);
    };

    LeafBookViewer.prototype.stopDragging = function () {
        this.isDragging = false;
        this.dragStart = null;
        this.stage.classList.remove('is-dragging');
    };

    LeafBookViewer.prototype.handleAction = function (action) {
        if (action === 'anterior') {
            this.prev();
        } else if (action === 'siguiente') {
            this.next();
        } else if (action === 'fullscreen') {
            this.toggleFullscreen();
        }
    };

    LeafBookViewer.prototype.goTo = function (pageNumber, direction) {
        if (!this.pdf || !this.pageCount) {
            return Promise.resolve();
        }

        this.pageNumber = clamp(pageNumber, 1, this.pageCount);
        this.zoom = 1;
        this.updateZoomState();
        this.updateInfo();
        this.animateTurn(direction);
        return this.render({ center: true });
    };

    LeafBookViewer.prototype.prev = function () {
        if (this.pageNumber > 1) {
            this.goTo(this.pageNumber - 1, 'prev');
        }
    };

    LeafBookViewer.prototype.next = function () {
        if (this.pageNumber < this.pageCount) {
            this.goTo(this.pageNumber + 1, 'next');
        }
    };

    LeafBookViewer.prototype.setZoom = function (zoom, anchor) {
        var nextZoom = clamp(zoom, this.minZoom, this.maxZoom);
        if (Math.abs(nextZoom - this.zoom) < 0.015) {
            return;
        }

        this.zoom = nextZoom;
        this.updateZoomState();
        this.render({ anchor: anchor || this.viewportCenterAnchor() });
    };

    LeafBookViewer.prototype.render = function (options) {
        var self = this;
        var token = ++this.renderToken;
        var scrollSnapshot = this.snapshotScroll();

        options = options || {};

        if (!this.pdf) {
            return Promise.resolve();
        }

        if (this.renderTask) {
            this.renderTask.cancel();
            this.renderTask = null;
        }

        return this.pdf.getPage(this.pageNumber).then(function (page) {
            if (token !== self.renderToken) {
                return null;
            }

            self.page = page;
            var baseViewport = page.getViewport({ scale: 1 });
            var padding = self.node.classList.contains('is-fullscreen') ? 36 : 28;
            var availableWidth = Math.max(260, self.stage.clientWidth - padding);
            var availableHeight = Math.max(260, self.stage.clientHeight - padding);

            self.fitScale = Math.min(availableWidth / baseViewport.width, availableHeight / baseViewport.height);
            self.currentCssScale = self.fitScale * self.zoom;

            var viewport = page.getViewport({ scale: self.currentCssScale });
            var dpr = clamp(window.devicePixelRatio || 1, 1, 2.5);
            var renderViewport = page.getViewport({ scale: self.currentCssScale * dpr });
            var tempCanvas = document.createElement('canvas');
            var tempContext = tempCanvas.getContext('2d', { alpha: false });

            tempCanvas.width = Math.floor(renderViewport.width);
            tempCanvas.height = Math.floor(renderViewport.height);
            tempContext.fillStyle = '#ffffff';
            tempContext.fillRect(0, 0, tempCanvas.width, tempCanvas.height);

            self.renderTask = page.render({
                canvasContext: tempContext,
                viewport: renderViewport
            });

            return self.renderTask.promise.then(function () {
                if (token !== self.renderToken) {
                    return null;
                }

                self.renderTask = null;
                self.paintCanvas(tempCanvas, viewport);
                self.renderLinks(page, viewport, token);
                self.restoreViewport(options, scrollSnapshot);
                self.updateInfo();
                return null;
            }).catch(function (error) {
                if (error && error.name === 'RenderingCancelledException') {
                    return null;
                }
                self.showError('No se pudo dibujar esta pagina.');
                return null;
            });
        });
    };

    LeafBookViewer.prototype.paintCanvas = function (sourceCanvas, viewport) {
        var cssWidth = Math.floor(viewport.width);
        var cssHeight = Math.floor(viewport.height);

        this.shell.style.transform = '';
        this.shell.style.width = cssWidth + 'px';
        this.shell.style.height = cssHeight + 'px';
        this.links.style.width = cssWidth + 'px';
        this.links.style.height = cssHeight + 'px';
        this.layoutShell(cssWidth, cssHeight);

        this.canvas.width = sourceCanvas.width;
        this.canvas.height = sourceCanvas.height;
        this.canvas.style.width = cssWidth + 'px';
        this.canvas.style.height = cssHeight + 'px';
        this.ctx.setTransform(1, 0, 0, 1, 0, 0);
        this.ctx.drawImage(sourceCanvas, 0, 0);
    };

    LeafBookViewer.prototype.layoutShell = function (width, height) {
        var extraBottom = 80;
        var horizontal = Math.max(18, Math.floor((this.stage.clientWidth - width) / 2));
        var top = Math.max(18, Math.floor((this.stage.clientHeight - height - extraBottom) / 2));
        var bottom = Math.max(extraBottom, top);

        if (this.zoom > 1.02) {
            horizontal = 24;
            top = 24;
            bottom = 110;
        }

        this.shell.style.margin = top + 'px ' + horizontal + 'px ' + bottom + 'px ' + horizontal + 'px';
    };

    LeafBookViewer.prototype.restoreViewport = function (options, scrollSnapshot) {
        if (options.anchor) {
            this.applyAnchor(options.anchor);
        } else if (options.preserveScroll && scrollSnapshot) {
            this.restoreScrollRatio(scrollSnapshot);
        } else {
            this.centerPage();
        }
    };

    LeafBookViewer.prototype.renderLinks = function (page, viewport, token) {
        var self = this;
        this.links.innerHTML = '';

        page.getAnnotations({ intent: 'display' }).then(function (annotations) {
            if (token !== self.renderToken) {
                return;
            }

            annotations.forEach(function (annotation) {
                if (!annotation.url || !annotation.rect) {
                    return;
                }

                var rect = window.pdfjsLib.Util.normalizeRect(
                    viewport.convertToViewportRectangle(annotation.rect)
                );
                var link = document.createElement('a');
                link.href = annotation.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.title = annotation.url;
                link.style.left = rect[0] + 'px';
                link.style.top = rect[1] + 'px';
                link.style.width = Math.max(1, rect[2] - rect[0]) + 'px';
                link.style.height = Math.max(1, rect[3] - rect[1]) + 'px';
                self.links.appendChild(link);
            });
        });
    };

    LeafBookViewer.prototype.pointerAnchor = function (clientX, clientY) {
        var stageRect = this.stage.getBoundingClientRect();
        var shellRect = this.shell.getBoundingClientRect();
        var width = Math.max(1, shellRect.width);
        var height = Math.max(1, shellRect.height);

        return {
            rx: clamp((clientX - shellRect.left) / width, 0, 1),
            ry: clamp((clientY - shellRect.top) / height, 0, 1),
            viewportX: clientX - stageRect.left,
            viewportY: clientY - stageRect.top
        };
    };

    LeafBookViewer.prototype.viewportCenterAnchor = function () {
        var rect = this.stage.getBoundingClientRect();
        return this.pointerAnchor(rect.left + rect.width / 2, rect.top + rect.height / 2);
    };

    LeafBookViewer.prototype.applyAnchor = function (anchor) {
        this.stage.scrollLeft = this.shell.offsetLeft + this.shell.offsetWidth * anchor.rx - anchor.viewportX;
        this.stage.scrollTop = this.shell.offsetTop + this.shell.offsetHeight * anchor.ry - anchor.viewportY;
    };

    LeafBookViewer.prototype.snapshotScroll = function () {
        return {
            leftRatio: this.stage.scrollLeft / Math.max(1, this.stage.scrollWidth - this.stage.clientWidth),
            topRatio: this.stage.scrollTop / Math.max(1, this.stage.scrollHeight - this.stage.clientHeight)
        };
    };

    LeafBookViewer.prototype.restoreScrollRatio = function (snapshot) {
        this.stage.scrollLeft = snapshot.leftRatio * Math.max(0, this.stage.scrollWidth - this.stage.clientWidth);
        this.stage.scrollTop = snapshot.topRatio * Math.max(0, this.stage.scrollHeight - this.stage.clientHeight);
    };

    LeafBookViewer.prototype.centerPage = function () {
        this.stage.scrollLeft = Math.max(0, this.shell.offsetLeft - (this.stage.clientWidth - this.shell.offsetWidth) / 2);
        this.stage.scrollTop = Math.max(0, this.shell.offsetTop - (this.stage.clientHeight - this.shell.offsetHeight) / 2);
    };

    LeafBookViewer.prototype.updateInfo = function () {
        if (!this.info) {
            return;
        }

        if (!this.pageCount) {
            this.info.textContent = '...';
            return;
        }

        this.info.textContent = this.pageNumber + ' / ' + this.pageCount;
    };

    LeafBookViewer.prototype.updateZoomState = function () {
        this.node.classList.toggle('is-zoomed', this.zoom > 1.02);
    };

    LeafBookViewer.prototype.animateTurn = function (direction) {
        var self = this;
        if (!direction) {
            return;
        }

        this.shell.classList.remove('is-turning-next', 'is-turning-prev');
        window.requestAnimationFrame(function () {
            self.shell.classList.add(direction === 'next' ? 'is-turning-next' : 'is-turning-prev');
        });
    };

    LeafBookViewer.prototype.toggleFullscreen = function () {
        if (document.fullscreenElement === this.node) {
            document.exitFullscreen();
            return;
        }

        if (this.node.requestFullscreen) {
            this.node.requestFullscreen();
        }
    };

    LeafBookViewer.prototype.showError = function (message) {
        this.node.classList.add('has-error');
        if (this.loader) {
            this.loader.innerHTML = '<p class="fbm-cargando-texto">' + message + '</p>';
        }
    };

    LeafBookViewer.prototype.touchDistance = function (event) {
        var first = event.touches[0];
        var second = event.touches[1];
        var dx = second.clientX - first.clientX;
        var dy = second.clientY - first.clientY;
        return Math.sqrt(dx * dx + dy * dy);
    };

    LeafBookViewer.prototype.touchCenter = function (event) {
        return {
            x: (event.touches[0].clientX + event.touches[1].clientX) / 2,
            y: (event.touches[0].clientY + event.touches[1].clientY) / 2
        };
    };

    LeafBookViewer.prototype.debounce = function (callback, wait) {
        var timer = null;
        return function () {
            clearTimeout(timer);
            timer = setTimeout(callback, wait);
        };
    };

    function init(root) {
        root = root || document;

        var nodes = root.matches && root.matches('.fbm-visor[data-id]')
            ? [root]
            : root.querySelectorAll('.fbm-visor[data-id]');

        nodes.forEach(function (node) {
            if (node.getAttribute('data-leafbook-ready') === '1') {
                return;
            }

            var id = node.getAttribute('data-instance-id') || node.getAttribute('data-id');
            instances[id] = new LeafBookViewer(node);
            instances[id].init();
        });
    }

    function observeDynamicShortcodes() {
        if (!window.MutationObserver || !document.documentElement) {
            return;
        }

        var timer = null;
        var observer = new MutationObserver(function (mutations) {
            var shouldInit = false;

            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (shouldInit || node.nodeType !== 1) {
                        return;
                    }

                    if (
                        (node.matches && node.matches('.fbm-visor[data-id]')) ||
                        (node.querySelector && node.querySelector('.fbm-visor[data-id]'))
                    ) {
                        shouldInit = true;
                    }
                });
            });

            if (!shouldInit) {
                return;
            }

            clearTimeout(timer);
            timer = setTimeout(function () {
                init();
            }, 80);
        });

        observer.observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init();
            observeDynamicShortcodes();
        });
    } else {
        init();
        observeDynamicShortcodes();
    }

    window.LeafBookViewer = {
        init: init,
        instances: instances
    };
}());
