(function () {
    'use strict';

    var instances = {};

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function getConfig(id, node) {
        var globalConfig = window['fbmData_' + id] || {};
        return {
            id: id,
            pdfUrl: globalConfig.pdfUrl || node.getAttribute('data-pdf'),
            workerSrc: globalConfig.workerSrc || '',
            height: Number(globalConfig.alto || node.getAttribute('data-alto') || 640)
        };
    }

    function LeafBookViewer(node) {
        this.node = node;
        this.id = node.getAttribute('data-id');
        this.config = getConfig(this.id, node);
        this.pdf = null;
        this.page = null;
        this.pageNumber = 1;
        this.pageCount = 0;
        this.zoom = 1;
        this.minZoom = 0.8;
        this.maxZoom = 4;
        this.fitScale = 1;
        this.renderTask = null;
        this.renderToken = 0;
        this.isDragging = false;
        this.dragStart = null;
        this.lastTap = 0;
        this.touchStart = null;
        this.pinchStart = null;

        this.stage = node.querySelector('.fbm-stage');
        this.shell = node.querySelector('.fbm-page-shell');
        this.canvas = node.querySelector('.fbm-page-canvas');
        this.links = node.querySelector('.fbm-link-layer');
        this.loader = node.querySelector('.fbm-cargando');
        this.info = document.getElementById('fbm-info-' + this.id);
        this.ctx = this.canvas.getContext('2d', { alpha: false });

        this.onResize = this.debounce(this.render.bind(this), 120);
    }

    LeafBookViewer.prototype.init = function () {
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
            } else if (event.key === 'Home') {
                self.goTo(1);
            } else if (event.key === 'End') {
                self.goTo(self.pageCount);
            } else if (event.key === 'Escape' && document.fullscreenElement) {
                document.exitFullscreen();
            }
        });

        this.stage.addEventListener('wheel', function (event) {
            if (!event.ctrlKey && !event.metaKey) {
                return;
            }

            event.preventDefault();
            self.zoomBy(event.deltaY < 0 ? 0.14 : -0.14);
        }, { passive: false });

        this.stage.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 || self.zoom <= 1.02) {
                return;
            }

            self.isDragging = true;
            self.dragStart = {
                x: event.clientX,
                y: event.clientY,
                left: self.stage.scrollLeft,
                top: self.stage.scrollTop
            };
            self.stage.setPointerCapture(event.pointerId);
            self.stage.classList.add('is-dragging');
        });

        this.stage.addEventListener('pointermove', function (event) {
            if (!self.isDragging || !self.dragStart) {
                return;
            }

            self.stage.scrollLeft = self.dragStart.left - (event.clientX - self.dragStart.x);
            self.stage.scrollTop = self.dragStart.top - (event.clientY - self.dragStart.y);
        });

        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) {
            self.stage.addEventListener(name, function () {
                self.isDragging = false;
                self.dragStart = null;
                self.stage.classList.remove('is-dragging');
            });
        });

        this.stage.addEventListener('touchstart', function (event) {
            if (event.touches.length === 1) {
                self.touchStart = {
                    x: event.touches[0].clientX,
                    y: event.touches[0].clientY,
                    time: Date.now()
                };
            } else if (event.touches.length === 2) {
                self.pinchStart = {
                    distance: self.touchDistance(event),
                    zoom: self.zoom
                };
            }
        }, { passive: true });

        this.stage.addEventListener('touchmove', function (event) {
            if (event.touches.length !== 2 || !self.pinchStart) {
                return;
            }

            event.preventDefault();
            var distance = self.touchDistance(event);
            var ratio = distance / self.pinchStart.distance;
            self.setZoom(self.pinchStart.zoom * ratio);
        }, { passive: false });

        this.stage.addEventListener('touchend', function (event) {
            if (self.pinchStart && event.touches.length < 2) {
                self.pinchStart = null;
                return;
            }

            if (!self.touchStart) {
                return;
            }

            var changed = event.changedTouches[0];
            var dx = changed.clientX - self.touchStart.x;
            var dy = changed.clientY - self.touchStart.y;
            var elapsed = Date.now() - self.touchStart.time;
            self.touchStart = null;

            if (self.zoom > 1.02 || elapsed > 500 || Math.abs(dx) < 48 || Math.abs(dx) < Math.abs(dy) * 1.5) {
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
            self.setZoom(self.zoom > 1.05 ? 1 : 2);
        });

        document.addEventListener('fullscreenchange', function () {
            self.node.classList.toggle('is-fullscreen', document.fullscreenElement === self.node);
            self.render();
        });

        window.addEventListener('resize', this.onResize);
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

    LeafBookViewer.prototype.goTo = function (pageNumber) {
        if (!this.pdf || !this.pageCount) {
            return Promise.resolve();
        }

        this.pageNumber = clamp(pageNumber, 1, this.pageCount);
        this.zoom = 1;
        this.updateInfo();
        return this.render();
    };

    LeafBookViewer.prototype.prev = function () {
        if (this.pageNumber > 1) {
            this.goTo(this.pageNumber - 1);
        }
    };

    LeafBookViewer.prototype.next = function () {
        if (this.pageNumber < this.pageCount) {
            this.goTo(this.pageNumber + 1);
        }
    };

    LeafBookViewer.prototype.setZoom = function (zoom) {
        var nextZoom = clamp(zoom, this.minZoom, this.maxZoom);
        if (Math.abs(nextZoom - this.zoom) < 0.02) {
            return;
        }

        this.zoom = nextZoom;
        this.render();
    };

    LeafBookViewer.prototype.zoomBy = function (amount) {
        this.setZoom(this.zoom + amount);
    };

    LeafBookViewer.prototype.render = function () {
        var self = this;
        var token = ++this.renderToken;

        if (!this.pdf) {
            return Promise.resolve();
        }

        if (this.renderTask) {
            this.renderTask.cancel();
            this.renderTask = null;
        }

        this.node.classList.add('is-rendering');

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
            var cssScale = self.fitScale * self.zoom;
            var viewport = page.getViewport({ scale: cssScale });
            var dpr = clamp(window.devicePixelRatio || 1, 1, 2.5);
            var outputScale = cssScale * dpr;
            var renderViewport = page.getViewport({ scale: outputScale });

            self.canvas.width = Math.floor(renderViewport.width);
            self.canvas.height = Math.floor(renderViewport.height);
            self.canvas.style.width = Math.floor(viewport.width) + 'px';
            self.canvas.style.height = Math.floor(viewport.height) + 'px';
            self.shell.style.width = Math.floor(viewport.width) + 'px';
            self.shell.style.height = Math.floor(viewport.height) + 'px';
            self.links.style.width = Math.floor(viewport.width) + 'px';
            self.links.style.height = Math.floor(viewport.height) + 'px';

            self.ctx.setTransform(1, 0, 0, 1, 0, 0);
            self.ctx.fillStyle = '#ffffff';
            self.ctx.fillRect(0, 0, self.canvas.width, self.canvas.height);

            self.renderTask = page.render({
                canvasContext: self.ctx,
                viewport: renderViewport
            });

            return self.renderTask.promise.then(function () {
                if (token !== self.renderToken) {
                    return null;
                }

                self.renderTask = null;
                self.renderLinks(page, viewport, token);
                self.centerPage();
                self.node.classList.remove('is-rendering');
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

    LeafBookViewer.prototype.centerPage = function () {
        var maxLeft = Math.max(0, this.stage.scrollWidth - this.stage.clientWidth);
        var maxTop = Math.max(0, this.stage.scrollHeight - this.stage.clientHeight);
        this.stage.scrollLeft = maxLeft / 2;
        this.stage.scrollTop = maxTop / 2;
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

    LeafBookViewer.prototype.debounce = function (callback, wait) {
        var timer = null;
        return function () {
            clearTimeout(timer);
            timer = setTimeout(callback, wait);
        };
    };

    function init() {
        var nodes = document.querySelectorAll('.fbm-visor[data-id]');
        nodes.forEach(function (node) {
            var id = node.getAttribute('data-id');
            if (instances[id]) {
                return;
            }

            instances[id] = new LeafBookViewer(node);
            instances[id].init();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.LeafBookViewer = {
        init: init,
        instances: instances
    };
}());
