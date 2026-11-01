/*
 * omnibase/estate - the home's plan editor (templates/admin/plan.html.twig).
 *
 * Rooms are polygons in centimetres on a 10 cm grid. Draw a rectangle, move a
 * room, drag a corner, double-click a wall to add a corner; select several
 * rooms (Shift + click) and merge them into one outline (an L), or split a
 * room along a vertical or horizontal line. Merging and splitting work on the
 * grid's cells: the rooms are filled cell by cell, the cells joined or cut,
 * the outline traced back - so the result is always a clean polygon on the
 * grid. An architect's plan can lie underneath, scaled by two points and a
 * known length. Saved as JSON to the controller, which checks it again.
 * No dependency; started once per [data-estate-plan-editor], again after a
 * page swap (transparent.js).
 */
(function () {
    'use strict';

    var GRID = 10;
    var NS = 'http://www.w3.org/2000/svg';
    var MAX_CELLS = 600; // 60 m along a side

    function el(name, attrs, parent) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        if (parent) {
            parent.appendChild(node);
        }
        return node;
    }
    function snap(v) { return Math.round(v / GRID) * GRID; }
    function area(points) {
        var sum = 0;
        for (var i = 0; i < points.length; i++) {
            var a = points[i], b = points[(i + 1) % points.length];
            sum += a[0] * b[1] - b[0] * a[1];
        }
        return Math.abs(sum) / 2;
    }
    function m2(cm2) { return Math.round(cm2 / 100) / 100; }
    function fmt(value) {
        if (null === value || undefined === value || isNaN(value)) {
            return '—';
        }
        return (Math.round(value * 100) / 100).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) + ' m²';
    }
    function contains(points, x, y) {
        var inside = false;
        for (var i = 0, j = points.length - 1; i < points.length; j = i++) {
            var xi = points[i][0], yi = points[i][1], xj = points[j][0], yj = points[j][1];
            if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) {
                inside = !inside;
            }
        }
        return inside;
    }
    function bounds(list) {
        var b = [Infinity, Infinity, -Infinity, -Infinity];
        list.forEach(function (points) {
            points.forEach(function (p) {
                b[0] = Math.min(b[0], p[0]); b[1] = Math.min(b[1], p[1]);
                b[2] = Math.max(b[2], p[0]); b[3] = Math.max(b[3], p[1]);
            });
        });
        return b;
    }
    function label(points) {
        // The centroid when inside, else the middle of the widest stretch at mid-height.
        var a = 0, cx = 0, cy = 0;
        for (var i = 0; i < points.length; i++) {
            var p = points[i], q = points[(i + 1) % points.length], c = p[0] * q[1] - q[0] * p[1];
            a += c; cx += (p[0] + q[0]) * c; cy += (p[1] + q[1]) * c;
        }
        var b = bounds([points]);
        cx = a ? cx / (3 * a) : (b[0] + b[2]) / 2;
        cy = a ? cy / (3 * a) : (b[1] + b[3]) / 2;
        if (contains(points, cx, cy)) {
            return [cx, cy];
        }
        var best = null;
        [cy, (b[1] + b[3]) / 2, b[1] + (b[3] - b[1]) / 4, b[1] + 3 * (b[3] - b[1]) / 4].forEach(function (y) {
            var xs = [];
            for (var i = 0, j = points.length - 1; i < points.length; j = i++) {
                var yi = points[i][1], yj = points[j][1];
                if ((yi > y) !== (yj > y)) {
                    xs.push(points[i][0] + (y - yi) * (points[j][0] - points[i][0]) / (yj - yi));
                }
            }
            xs.sort(function (m, n) { return m - n; });
            for (var k = 0; k + 1 < xs.length; k += 2) {
                if (!best || xs[k + 1] - xs[k] > best[2]) {
                    best = [(xs[k] + xs[k + 1]) / 2, y, xs[k + 1] - xs[k]];
                }
            }
        });
        return best ? [best[0], best[1]] : [cx, cy];
    }

    /* The grid's cells: rasterise, join, cut, trace back. */
    function cells(polygons) {
        var b = bounds(polygons);
        var x0 = Math.floor(b[0] / GRID), y0 = Math.floor(b[1] / GRID);
        var w = Math.ceil(b[2] / GRID) - x0, h = Math.ceil(b[3] / GRID) - y0;
        if (w > MAX_CELLS || h > MAX_CELLS) {
            throw new Error('Pièces trop grandes pour être réunies ou coupées.');
        }
        var filled = new Uint8Array(w * h);
        for (var j = 0; j < h; j++) {
            for (var i = 0; i < w; i++) {
                var x = (x0 + i + 0.5) * GRID, y = (y0 + j + 0.5) * GRID;
                for (var k = 0; k < polygons.length; k++) {
                    if (contains(polygons[k], x, y)) {
                        filled[j * w + i] = 1;
                        break;
                    }
                }
            }
        }
        return { x0: x0, y0: y0, w: w, h: h, filled: filled };
    }
    function components(grid) {
        var seen = new Uint8Array(grid.w * grid.h), parts = [];
        for (var start = 0; start < grid.filled.length; start++) {
            if (!grid.filled[start] || seen[start]) {
                continue;
            }
            var part = new Uint8Array(grid.w * grid.h), stack = [start];
            seen[start] = 1;
            while (stack.length) {
                var c = stack.pop(), i = c % grid.w, j = (c - i) / grid.w;
                part[c] = 1;
                [[i - 1, j], [i + 1, j], [i, j - 1], [i, j + 1]].forEach(function (n) {
                    if (n[0] < 0 || n[1] < 0 || n[0] >= grid.w || n[1] >= grid.h) {
                        return;
                    }
                    var k = n[1] * grid.w + n[0];
                    if (grid.filled[k] && !seen[k]) {
                        seen[k] = 1;
                        stack.push(k);
                    }
                });
            }
            parts.push({ x0: grid.x0, y0: grid.y0, w: grid.w, h: grid.h, filled: part });
        }
        return parts;
    }
    function trace(grid) {
        var at = function (i, j) { return i >= 0 && j >= 0 && i < grid.w && j < grid.h && grid.filled[j * grid.w + i]; };
        var next = {}, count = 0;
        var add = function (a, b) {
            var key = a[0] + ',' + a[1];
            if (next[key]) {
                throw new Error('La forme obtenue se touche par un coin ou a un trou : faites-en deux pièces.');
            }
            next[key] = b;
            count++;
        };
        for (var j = 0; j < grid.h; j++) {
            for (var i = 0; i < grid.w; i++) {
                if (!at(i, j)) {
                    continue;
                }
                if (!at(i, j - 1)) { add([i, j], [i + 1, j]); }
                if (!at(i + 1, j)) { add([i + 1, j], [i + 1, j + 1]); }
                if (!at(i, j + 1)) { add([i + 1, j + 1], [i, j + 1]); }
                if (!at(i - 1, j)) { add([i, j + 1], [i, j]); }
            }
        }
        var first = Object.keys(next)[0];
        if (!first) {
            return null;
        }
        var start = first.split(',').map(Number), point = start, loop = [], steps = 0;
        do {
            loop.push(point);
            point = next[point[0] + ',' + point[1]];
            steps++;
        } while (point && (point[0] !== start[0] || point[1] !== start[1]) && steps <= count);
        if (steps !== count) {
            throw new Error('La forme obtenue a un trou : faites-en deux pièces.');
        }
        // Collinear corners dropped, back to centimetres.
        var out = [];
        for (var n = 0; n < loop.length; n++) {
            var p = loop[(n + loop.length - 1) % loop.length], c = loop[n], q = loop[(n + 1) % loop.length];
            if ((c[0] - p[0]) * (q[1] - c[1]) - (c[1] - p[1]) * (q[0] - c[0]) !== 0) {
                out.push([(c[0] + grid.x0) * GRID, (c[1] + grid.y0) * GRID]);
            }
        }
        return out;
    }

    function Editor(root) {
        this.root = root;
        this.svg = root.querySelector('[data-svg]');
        this.stage = root.querySelector('[data-stage]');
        this.tip = root.querySelector('[data-tip]');
        this.status = root.querySelector('[data-status]');
        this.panel = root.querySelector('[data-room-panel]');
        var plan = JSON.parse(root.querySelector('[data-plan]').textContent || '{}');
        this.kinds = JSON.parse(root.querySelector('[data-kinds]').textContent || '[]');
        this.rooms = (plan.rooms || []).map(function (r) {
            return { id: r.id, name: r.name, kind: r.kind, level: r.level || 0, polygon: r.polygon, measured: r.measured, counted: r.counted };
        });
        this.declared = plan.declared;
        this.background = plan.background || { scale: null, x: 0, y: 0 };
        this.backgroundUrl = root.dataset.background || '';
        this.level = this.rooms.length ? this.rooms[0].level : 0;
        this.selected = [];
        this.tool = 'select';
        this.history = [];
        this.dirty = false;
        this.scalePoints = [];
        this.build();
        this.fit();
        this.render();
    }

    Editor.prototype.snapshot = function () {
        this.history.push(JSON.stringify(this.rooms));
        if (this.history.length > 60) {
            this.history.shift();
        }
        this.dirty = true;
        this.say('Modifications non enregistrées');
        this.root.querySelector('[data-action="undo"]').disabled = false;
    };
    Editor.prototype.undo = function () {
        if (!this.history.length) {
            return;
        }
        this.rooms = JSON.parse(this.history.pop());
        this.selected = [];
        this.root.querySelector('[data-action="undo"]').disabled = !this.history.length;
        this.render();
    };
    Editor.prototype.say = function (text, error) {
        this.status.textContent = text;
        this.status.classList.toggle('is-error', !!error);
    };

    Editor.prototype.build = function () {
        var self = this;
        var defs = el('defs', {}, this.svg);
        var small = el('pattern', { id: 'estate-grid-10', width: GRID, height: GRID, patternUnits: 'userSpaceOnUse' }, defs);
        el('path', { d: 'M ' + GRID + ' 0 L 0 0 0 ' + GRID, fill: 'none', stroke: '#e6e6e3', 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke' }, small);
        var big = el('pattern', { id: 'estate-grid-100', width: 100, height: 100, patternUnits: 'userSpaceOnUse' }, defs);
        el('rect', { width: 100, height: 100, fill: 'url(#estate-grid-10)' }, big);
        el('path', { d: 'M 100 0 L 0 0 0 100', fill: 'none', stroke: '#c9cbc7', 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke' }, big);
        this.layers = {
            background: el('g', {}, this.svg),
            grid: el('rect', { x: -50000, y: -50000, width: 100000, height: 100000, fill: 'url(#estate-grid-100)' }, this.svg),
            rooms: el('g', {}, this.svg),
            handles: el('g', {}, this.svg),
            overlay: el('g', {}, this.svg)
        };
        if (this.backgroundUrl) {
            this.bgImage = el('image', { href: this.backgroundUrl, opacity: 0.45, preserveAspectRatio: 'none' }, this.layers.background);
            var probe = new Image();
            probe.onload = function () { self.bgNatural = [probe.naturalWidth, probe.naturalHeight]; self.placeBackground(); if (!self.rooms.length) { self.fit(); } };
            probe.src = this.backgroundUrl;
        }

        // Kinds into the panel's select.
        var kind = this.panel.querySelector('[data-field="kind"]');
        this.kinds.forEach(function (k) {
            var option = document.createElement('option');
            option.value = k.value;
            option.textContent = k.label;
            kind.appendChild(option);
        });

        this.root.querySelectorAll('[data-tool]').forEach(function (button) {
            button.addEventListener('click', function () { self.setTool(button.dataset.tool); });
        });
        this.root.querySelectorAll('[data-action]').forEach(function (button) {
            button.addEventListener('click', function () { self.action(button.dataset.action); });
        });
        this.panel.querySelectorAll('[data-field]').forEach(function (input) {
            var event = 'checkbox' === input.type || 'SELECT' === input.tagName ? 'change' : 'input';
            input.addEventListener('focus', function () { self.fieldSnap = true; });
            input.addEventListener(event, function () { self.edit(input); });
        });
        this.root.querySelectorAll('[data-bg]').forEach(function (input) {
            input.value = self.background[input.dataset.bg] || 0;
            input.addEventListener('input', function () {
                self.background[input.dataset.bg] = parseInt(input.value || '0', 10) || 0;
                self.dirty = true;
                self.placeBackground();
            });
        });
        var opacity = this.root.querySelector('[data-bg-opacity]');
        if (opacity) {
            opacity.addEventListener('input', function () { if (self.bgImage) { self.bgImage.setAttribute('opacity', opacity.value / 100); } });
        }
        var length = this.root.querySelector('[data-scale-length]');
        if (length) {
            length.addEventListener('change', function () { self.applyScale(); });
        }

        this.svg.addEventListener('pointerdown', function (e) { self.down(e); });
        this.svg.addEventListener('pointermove', function (e) { self.move(e); });
        this.svg.addEventListener('pointerup', function (e) { self.up(e); });
        this.svg.addEventListener('pointercancel', function (e) { self.up(e); });
        this.svg.addEventListener('dblclick', function (e) { self.addCorner(e); });
        this.svg.addEventListener('wheel', function (e) {
            e.preventDefault();
            self.zoom(e.deltaY < 0 ? 0.85 : 1 / 0.85, self.point(e));
        }, { passive: false });
        this.keys = function (e) { self.key(e); };
        document.addEventListener('keydown', this.keys);
        window.addEventListener('beforeunload', function (e) {
            if (self.dirty && document.body.contains(self.root)) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    };

    Editor.prototype.placeBackground = function () {
        if (!this.bgImage || !this.bgNatural) {
            return;
        }
        var scale = this.background.scale || 1;
        this.bgImage.setAttribute('x', this.background.x || 0);
        this.bgImage.setAttribute('y', this.background.y || 0);
        this.bgImage.setAttribute('width', this.bgNatural[0] * scale);
        this.bgImage.setAttribute('height', this.bgNatural[1] * scale);
    };

    Editor.prototype.point = function (e) {
        var p = this.svg.createSVGPoint();
        p.x = e.clientX;
        p.y = e.clientY;
        var q = p.matrixTransform(this.svg.getScreenCTM().inverse());
        return [q.x, q.y];
    };

    Editor.prototype.setView = function (x, y, w, h) {
        this.view = [x, y, w, h];
        this.svg.setAttribute('viewBox', [x, y, w, h].map(function (v) { return Math.round(v * 10) / 10; }).join(' '));
    };
    Editor.prototype.fit = function () {
        var level = this.level;
        var shapes = this.rooms.filter(function (r) { return r.level === level; }).map(function (r) { return r.polygon; });
        var b;
        if (shapes.length) {
            b = bounds(shapes);
        } else if (this.bgNatural) {
            var s = this.background.scale || 1;
            b = [this.background.x, this.background.y, this.background.x + this.bgNatural[0] * s, this.background.y + this.bgNatural[1] * s];
        } else {
            b = [0, 0, 1000, 700];
        }
        var rect = this.stage.getBoundingClientRect();
        var ratio = rect.width && rect.height ? rect.width / rect.height : 1.5;
        var w = Math.max(300, b[2] - b[0]) * 1.15, h = Math.max(300, b[3] - b[1]) * 1.15;
        if (w / h < ratio) { w = h * ratio; } else { h = w / ratio; }
        this.setView((b[0] + b[2]) / 2 - w / 2, (b[1] + b[3]) / 2 - h / 2, w, h);
    };
    Editor.prototype.zoom = function (factor, around) {
        var v = this.view, c = around || [v[0] + v[2] / 2, v[1] + v[3] / 2];
        var w = Math.min(20000, Math.max(80, v[2] * factor)), f = w / v[2], h = v[3] * f;
        this.setView(c[0] - (c[0] - v[0]) * f, c[1] - (c[1] - v[1]) * f, w, h);
        this.render();
    };

    Editor.prototype.setTool = function (tool) {
        if ('scale' === tool && this.tool === 'scale') {
            tool = 'select';
        }
        this.tool = tool;
        this.scalePoints = [];
        this.stage.className = 'estate-plan-stage is-' + tool;
        this.root.querySelectorAll('[data-tool]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.tool === tool)); });
        this.render();
    };

    Editor.prototype.levels = function () {
        var levels = {};
        this.rooms.forEach(function (r) { levels[r.level] = true; });
        levels[this.level] = true;
        return Object.keys(levels).map(Number).sort(function (a, b) { return a - b; });
    };
    Editor.prototype.levelName = function (level) {
        return 0 === level ? 'Niveau principal' : (-1 === level ? 'Sous-sol' : (level > 0 ? 'Niveau +' + level : 'Niveau ' + level));
    };

    Editor.prototype.render = function () {
        var self = this;
        // Levels.
        var tabs = this.root.querySelector('[data-levels]');
        tabs.textContent = '';
        this.levels().forEach(function (level) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = self.levelName(level);
            b.setAttribute('aria-pressed', String(level === self.level));
            b.addEventListener('click', function () { self.level = level; self.selected = []; self.fit(); self.render(); });
            tabs.appendChild(b);
        });
        var add = document.createElement('button');
        add.type = 'button';
        add.textContent = '+ niveau';
        add.addEventListener('click', function () { self.level = Math.max.apply(null, self.levels()) + 1; self.selected = []; self.render(); });
        tabs.appendChild(add);

        // Rooms: the other levels faint, under the current one.
        this.layers.rooms.textContent = '';
        this.layers.handles.textContent = '';
        var font = Math.max(10, this.view[2] / 70);
        var order = this.rooms.map(function (r, i) { return i; }).sort(function (a, b) { return (self.rooms[a].level === self.level) - (self.rooms[b].level === self.level); });
        order.forEach(function (index) {
            var room = self.rooms[index];
            var current = room.level === self.level;
            var g = el('g', { class: 'room' + (current ? '' : ' is-other-level') + (room.counted ? '' : ' is-uncounted') + (self.selected.indexOf(index) >= 0 ? ' is-selected' : ''), 'data-index': index }, self.layers.rooms);
            el('polygon', { points: room.polygon.map(function (p) { return p.join(','); }).join(' ') }, g);
            if (current) {
                var at = label(room.polygon), drawn = m2(area(room.polygon));
                var text = el('text', { x: at[0], y: at[1], 'font-size': font }, g);
                var name = el('tspan', { x: at[0], dy: '-0.2em', 'font-weight': 700 }, text);
                name.textContent = room.name || '—';
                var surface = el('tspan', { x: at[0], dy: '1.2em', class: null !== room.measured && '' !== room.measured && undefined !== room.measured ? 'is-measured' : '' }, text);
                surface.textContent = fmt(self.surfaceOf(room)) + (self.isMeasured(room) ? ' (mesurée)' : '');
                void drawn;
            }
        });
        // Corners of the room selected alone.
        if (1 === this.selected.length && 'select' === this.tool) {
            var room = this.rooms[this.selected[0]];
            var r = Math.max(4, this.view[2] / 160);
            room.polygon.forEach(function (p, i) {
                el('circle', { class: 'handle', cx: p[0], cy: p[1], r: r, 'data-corner': i }, self.layers.handles);
            });
        }
        this.layers.overlay.textContent = '';
        this.scalePoints.forEach(function (p) { el('circle', { class: 'scale-point', cx: p[0], cy: p[1], r: Math.max(4, self.view[2] / 180) }, self.layers.overlay); });
        if (2 === this.scalePoints.length) {
            el('line', { class: 'split-line', x1: this.scalePoints[0][0], y1: this.scalePoints[0][1], x2: this.scalePoints[1][0], y2: this.scalePoints[1][1] }, this.layers.overlay);
        }

        this.root.querySelector('[data-action="merge"]').disabled = this.selected.length < 2;
        this.renderPanel();
        this.renderTotals();
    };

    Editor.prototype.isMeasured = function (room) {
        return null !== room.measured && undefined !== room.measured && '' !== room.measured && !isNaN(parseFloat(String(room.measured).replace(',', '.')));
    };
    Editor.prototype.surfaceOf = function (room) {
        return this.isMeasured(room) ? parseFloat(String(room.measured).replace(',', '.')) : m2(area(room.polygon));
    };

    Editor.prototype.renderPanel = function () {
        if (1 !== this.selected.length) {
            this.panel.hidden = true;
            return;
        }
        var room = this.rooms[this.selected[0]];
        this.panel.hidden = false;
        var set = function (field, value) {
            var input = this.panel.querySelector('[data-field="' + field + '"]');
            if (document.activeElement === input) {
                return;
            }
            if ('checkbox' === input.type) { input.checked = !!value; } else { input.value = null === value || undefined === value ? '' : value; }
        }.bind(this);
        set('name', room.name);
        set('kind', room.kind);
        set('level', room.level);
        set('measured', room.measured);
        set('counted', room.counted);
        this.panel.querySelector('[data-field="measured"]').placeholder = String(m2(area(room.polygon))).replace('.', ',');
        this.panel.querySelector('[data-drawn]').textContent = 'Dessinée : ' + fmt(m2(area(room.polygon))) + (this.isMeasured(room) ? ' — la mesure l\'emporte' : '');
    };

    Editor.prototype.renderTotals = function () {
        var self = this, living = 0, total = 0;
        this.rooms.forEach(function (r) { var s = self.surfaceOf(r); total += s; if (r.counted) { living += s; } });
        var rows = [
            ['Pièces', String(this.rooms.length)],
            ['Surface habitable', this.rooms.length ? fmt(living) : '—'],
            ['Toutes pièces', this.rooms.length ? fmt(total) : '—'],
            ['Surface déclarée', null === this.declared || undefined === this.declared ? 'non renseignée' : fmt(this.declared)]
        ];
        var dl = this.root.querySelector('[data-totals]');
        dl.textContent = '';
        rows.forEach(function (row) {
            var dt = document.createElement('dt'), dd = document.createElement('dd');
            dt.textContent = row[0];
            dd.textContent = row[1];
            dl.appendChild(dt);
            dl.appendChild(dd);
        });
        var gap = this.root.querySelector('[data-gap]');
        var diff = Math.round((living - (this.declared || 0)) * 100) / 100;
        gap.hidden = !this.rooms.length || null === this.declared || undefined === this.declared || Math.abs(diff) < 0.5;
        gap.textContent = 'Écart de ' + (diff > 0 ? '+' : '') + fmt(diff) + ' entre les pièces et la surface déclarée (bail, annonce) : l\'une des deux est à revoir.';
    };

    Editor.prototype.hit = function (e) {
        var target = e.target;
        if (target.dataset && undefined !== target.dataset.corner) {
            return { corner: parseInt(target.dataset.corner, 10) };
        }
        var g = target.closest ? target.closest('.room') : null;
        if (g && !g.classList.contains('is-other-level')) {
            return { room: parseInt(g.dataset.index, 10) };
        }
        return {};
    };

    Editor.prototype.down = function (e) {
        if (0 !== e.button) {
            return;
        }
        var p = this.point(e), hit = this.hit(e);
        try { this.svg.setPointerCapture(e.pointerId); } catch (error) { /* a pointer the browser does not track (a script's) */ }
        if ('rect' === this.tool) {
            this.drag = { kind: 'rect', from: [snap(p[0]), snap(p[1])] };
            this.draft = el('rect', { class: 'draft', x: this.drag.from[0], y: this.drag.from[1], width: 0, height: 0 }, this.layers.overlay);
            return;
        }
        if ('split-v' === this.tool || 'split-h' === this.tool) {
            if (undefined !== hit.room) {
                this.split(hit.room, 'split-v' === this.tool ? 'x' : 'y', 'split-v' === this.tool ? snap(p[0]) : snap(p[1]));
            }
            return;
        }
        if ('scale' === this.tool) {
            this.scalePoints.push(p);
            if (this.scalePoints.length > 2) {
                this.scalePoints = [p];
            }
            this.render();
            if (2 === this.scalePoints.length) {
                var length = this.root.querySelector('[data-scale-length]');
                if (length) { length.focus(); if (length.value) { this.applyScale(); } }
            }
            return;
        }
        if (undefined !== hit.corner) {
            this.snapshot();
            this.drag = { kind: 'corner', index: this.selected[0], corner: hit.corner };
            return;
        }
        if (undefined !== hit.room) {
            if (e.shiftKey) {
                var at = this.selected.indexOf(hit.room);
                if (at >= 0) { this.selected.splice(at, 1); } else { this.selected.push(hit.room); }
            } else if (this.selected.indexOf(hit.room) < 0) {
                this.selected = [hit.room];
            }
            this.drag = { kind: 'move', from: p, origin: this.selected.map(function (i) { return JSON.parse(JSON.stringify(this.rooms[i].polygon)); }, this), moved: false };
            this.render();
            return;
        }
        this.selected = [];
        this.drag = { kind: 'pan', from: [e.clientX, e.clientY], view: this.view.slice() };
        this.render();
    };

    Editor.prototype.move = function (e) {
        if (!this.drag) {
            return;
        }
        var p = this.point(e), d = this.drag;
        if ('rect' === d.kind) {
            var to = [snap(p[0]), snap(p[1])];
            var x = Math.min(d.from[0], to[0]), y = Math.min(d.from[1], to[1]), w = Math.abs(to[0] - d.from[0]), h = Math.abs(to[1] - d.from[1]);
            this.draft.setAttribute('x', x); this.draft.setAttribute('y', y); this.draft.setAttribute('width', w); this.draft.setAttribute('height', h);
            this.showTip((w / 100).toLocaleString('fr-FR') + ' × ' + (h / 100).toLocaleString('fr-FR') + ' m = ' + fmt(m2(w * h)));
        } else if ('corner' === d.kind) {
            this.rooms[d.index].polygon[d.corner] = [snap(p[0]), snap(p[1])];
            this.render();
            this.showTip(fmt(m2(area(this.rooms[d.index].polygon))));
        } else if ('move' === d.kind) {
            var dx = snap(p[0] - d.from[0]), dy = snap(p[1] - d.from[1]);
            if (!d.moved && (dx || dy)) {
                this.snapshot();
                d.moved = true;
            }
            this.selected.forEach(function (index, k) {
                this.rooms[index].polygon = d.origin[k].map(function (q) { return [q[0] + dx, q[1] + dy]; });
            }, this);
            this.render();
        } else if ('pan' === d.kind) {
            var rect = this.svg.getBoundingClientRect(), scale = d.view[2] / rect.width;
            this.setView(d.view[0] - (e.clientX - d.from[0]) * scale, d.view[1] - (e.clientY - d.from[1]) * scale, d.view[2], d.view[3]);
        }
    };

    Editor.prototype.up = function (e) {
        var d = this.drag;
        this.drag = null;
        this.hideTip();
        if (!d) {
            return;
        }
        if ('rect' === d.kind) {
            var p = this.point(e), to = [snap(p[0]), snap(p[1])];
            this.draft.remove();
            var x = Math.min(d.from[0], to[0]), y = Math.min(d.from[1], to[1]), w = Math.abs(to[0] - d.from[0]), h = Math.abs(to[1] - d.from[1]);
            if (w >= GRID * 3 && h >= GRID * 3) {
                this.snapshot();
                this.rooms.push({ id: null, name: 'Pièce ' + (this.rooms.length + 1), kind: 'other', level: this.level, polygon: [[x, y], [x + w, y], [x + w, y + h], [x, y + h]], measured: null, counted: true });
                this.selected = [this.rooms.length - 1];
                this.setTool('select');
                var name = this.panel.querySelector('[data-field="name"]');
                if (name) { name.focus(); name.select(); }
            }
        } else if ('corner' === d.kind) {
            var poly = this.rooms[d.index].polygon;
            // A corner dropped on its neighbour goes; a room left with fewer than three is undone.
            this.rooms[d.index].polygon = poly.filter(function (q, i) { var n = poly[(i + 1) % poly.length]; return q[0] !== n[0] || q[1] !== n[1]; });
            if (this.rooms[d.index].polygon.length < 3 || area(this.rooms[d.index].polygon) <= 0) {
                this.undo();
            }
        }
        this.render();
    };

    Editor.prototype.addCorner = function (e) {
        if ('select' !== this.tool || 1 !== this.selected.length) {
            return;
        }
        var p = this.point(e), room = this.rooms[this.selected[0]], best = null;
        room.polygon.forEach(function (a, i) {
            var b = room.polygon[(i + 1) % room.polygon.length];
            var dx = b[0] - a[0], dy = b[1] - a[1], len = dx * dx + dy * dy;
            var t = len ? Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / len)) : 0;
            var x = a[0] + t * dx, y = a[1] + t * dy, dist = Math.hypot(p[0] - x, p[1] - y);
            if (!best || dist < best.dist) {
                best = { dist: dist, at: i + 1, point: [snap(x), snap(y)] };
            }
        });
        if (best && best.dist < this.view[2] / 40) {
            this.snapshot();
            room.polygon.splice(best.at, 0, best.point);
            this.render();
        }
    };

    Editor.prototype.split = function (index, axis, at) {
        var room = this.rooms[index];
        var grid;
        try {
            grid = cells([room.polygon]);
        } catch (error) {
            this.say(error.message, true);
            return;
        }
        var line = 'x' === axis ? at / GRID - grid.x0 : at / GRID - grid.y0;
        var sides = [new Uint8Array(grid.filled.length), new Uint8Array(grid.filled.length)];
        for (var c = 0; c < grid.filled.length; c++) {
            if (!grid.filled[c]) { continue; }
            var i = c % grid.w, j = (c - i) / grid.w;
            sides[('x' === axis ? i : j) < line ? 0 : 1][c] = 1;
        }
        var parts = [];
        try {
            sides.forEach(function (side) {
                components({ x0: grid.x0, y0: grid.y0, w: grid.w, h: grid.h, filled: side }).forEach(function (part) {
                    var outline = trace(part);
                    if (outline && outline.length >= 3) { parts.push(outline); }
                });
            });
        } catch (error) {
            this.say(error.message, true);
            return;
        }
        if (parts.length < 2) {
            this.say('La ligne ne coupe pas la pièce.', true);
            return;
        }
        this.snapshot();
        var made = parts.map(function (outline, k) {
            return { id: 0 === k ? room.id : null, name: 0 === k ? room.name : room.name + ' (' + (k + 1) + ')', kind: room.kind, level: room.level, polygon: outline, measured: null, counted: room.counted };
        });
        this.rooms.splice.apply(this.rooms, [index, 1].concat(made));
        this.selected = made.map(function (r, k) { return index + k; });
        this.setTool('select');
        this.say('Pièce coupée en ' + made.length + ' : nommez-les.');
    };

    Editor.prototype.merge = function () {
        var self = this;
        var chosen = this.selected.slice().sort(function (a, b) { return a - b; });
        if (chosen.length < 2) {
            return;
        }
        var level = this.rooms[chosen[0]].level;
        if (chosen.some(function (i) { return self.rooms[i].level !== level; })) {
            this.say('Les pièces à réunir sont au même niveau.', true);
            return;
        }
        var outline;
        try {
            var parts = components(cells(chosen.map(function (i) { return self.rooms[i].polygon; })));
            if (1 !== parts.length) {
                throw new Error('Les pièces à réunir se touchent par un mur.');
            }
            outline = trace(parts[0]);
        } catch (error) {
            this.say(error.message, true);
            return;
        }
        this.snapshot();
        var first = this.rooms[chosen[0]];
        var merged = { id: first.id, name: first.name, kind: first.kind, level: level, polygon: outline, measured: null, counted: first.counted };
        for (var k = chosen.length - 1; k >= 0; k--) {
            this.rooms.splice(chosen[k], 1);
        }
        this.rooms.splice(chosen[0], 0, merged);
        this.selected = [chosen[0]];
        this.say('Pièces réunies : ' + fmt(m2(area(outline))) + '.');
        this.render();
    };

    Editor.prototype.edit = function (input) {
        if (1 !== this.selected.length) {
            return;
        }
        if (this.fieldSnap) {
            this.snapshot();
            this.fieldSnap = false;
        }
        var room = this.rooms[this.selected[0]], field = input.dataset.field;
        if ('counted' === field) {
            room.counted = input.checked;
        } else if ('level' === field) {
            room.level = parseInt(input.value || '0', 10) || 0;
            this.level = room.level;
        } else if ('kind' === field) {
            room.kind = input.value;
            var kind = this.kinds.filter(function (k) { return k.value === input.value; })[0];
            if (kind) { room.counted = kind.counted; }
            if (/^Pièce \d+$/.test(room.name) && kind) { room.name = kind.label; }
        } else if ('measured' === field) {
            room.measured = '' === input.value.trim() ? null : input.value.trim();
        } else {
            room.name = input.value;
        }
        this.dirty = true;
        this.render();
    };

    Editor.prototype.applyScale = function () {
        var length = parseFloat((this.root.querySelector('[data-scale-length]') || {}).value || '');
        if (2 !== this.scalePoints.length || !(length > 0)) {
            return;
        }
        var scale = this.background.scale || 1;
        var a = this.scalePoints[0], b = this.scalePoints[1];
        var pixels = Math.hypot(b[0] - a[0], b[1] - a[1]) / scale;
        if (pixels <= 0) {
            return;
        }
        // The image's pixels under the two points keep their place about its corner.
        this.background.scale = length / pixels;
        this.dirty = true;
        this.placeBackground();
        this.scalePoints = [];
        this.setTool('select');
        this.fit();
        this.render();
        this.say('Échelle donnée : 1 px = ' + this.background.scale.toFixed(3).replace('.', ',') + ' cm. Enregistrez.');
    };

    Editor.prototype.action = function (name) {
        if ('merge' === name) { this.merge(); }
        else if ('undo' === name) { this.undo(); }
        else if ('zoom-in' === name) { this.zoom(0.8); }
        else if ('zoom-out' === name) { this.zoom(1.25); }
        else if ('fit' === name) { this.fit(); this.render(); }
        else if ('delete' === name) { this.remove(); }
        else if ('save' === name) { this.save(); }
    };

    Editor.prototype.remove = function () {
        if (!this.selected.length) {
            return;
        }
        this.snapshot();
        var chosen = this.selected.slice().sort(function (a, b) { return b - a; });
        chosen.forEach(function (i) { this.rooms.splice(i, 1); }, this);
        this.selected = [];
        this.render();
    };

    Editor.prototype.key = function (e) {
        if (!document.body.contains(this.root)) {
            document.removeEventListener('keydown', this.keys);
            return;
        }
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target || {}).tagName || '');
        if ((e.ctrlKey || e.metaKey) && 'z' === e.key.toLowerCase() && !typing) {
            e.preventDefault();
            this.undo();
        } else if ((e.ctrlKey || e.metaKey) && 's' === e.key.toLowerCase()) {
            e.preventDefault();
            this.save();
        } else if (typing) {
            return;
        } else if ('Delete' === e.key || 'Backspace' === e.key) {
            if (this.selected.length) { e.preventDefault(); this.remove(); }
        } else if ('Escape' === e.key) {
            this.selected = [];
            this.setTool('select');
        } else if ('r' === e.key.toLowerCase() && !e.ctrlKey && !e.metaKey) {
            this.setTool('rect');
        } else if ('s' === e.key.toLowerCase() && !e.ctrlKey && !e.metaKey) {
            this.setTool('select');
        }
    };

    Editor.prototype.showTip = function (text) { this.tip.hidden = false; this.tip.textContent = text; };
    Editor.prototype.hideTip = function () { this.tip.hidden = true; };

    Editor.prototype.save = function () {
        var self = this;
        var body = {
            rooms: this.rooms.map(function (r) {
                return { id: r.id, name: r.name, kind: r.kind, level: r.level, polygon: r.polygon, measured: self.isMeasured(r) ? String(r.measured).replace(',', '.') : null, counted: r.counted };
            }),
            background: this.background
        };
        this.say('Enregistrement…');
        fetch(this.root.dataset.saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': this.root.dataset.token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().then(function (json) { return { ok: response.ok, json: json }; });
        }).then(function (result) {
            if (!result.ok) {
                self.say(result.json.error || 'Le plan n\'a pas été enregistré.', true);
                return;
            }
            var selectedIds = self.selected.map(function (i) { return self.rooms[i]; });
            self.rooms = result.json.rooms.map(function (r) {
                return { id: r.id, name: r.name, kind: r.kind, level: r.level, polygon: r.polygon, measured: r.measured, counted: r.counted };
            });
            self.declared = result.json.declared;
            self.selected = [];
            void selectedIds;
            self.dirty = false;
            self.say('Enregistré — surface habitable ' + fmt(result.json.livingArea) + '.');
            self.render();
        }).catch(function () {
            self.say('Le plan n\'a pas été enregistré (réseau).', true);
        });
    };

    function init() {
        document.querySelectorAll('[data-estate-plan-editor]').forEach(function (root) {
            if (root.estatePlanEditor) {
                return;
            }
            root.estatePlanEditor = new Editor(root);
        });
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    ['transparent:load', 'load'].forEach(function (name) { document.addEventListener(name, init); });
    window.EstatePlanEditor = { init: init, trace: trace, cells: cells, components: components };
})();
