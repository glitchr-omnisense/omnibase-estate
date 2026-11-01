/*
 * omnibase/estate - the before/after slider of a home's story.
 *
 * The markup (templates/client/_before_after.html.twig) works without this
 * script: the two photos side by side. Here each [data-estate-ba] becomes a
 * slider: the "after" photo laid over the "before" one and uncovered up to
 * the range input's value, which the keyboard moves as any range does
 * (arrows, Page Up/Down, Home, End). One gentle sweep when the slider first
 * comes into view tells it can be moved - not under prefers-reduced-motion.
 * No dependency; run again after a page swap (transparent.js, Turbo), it
 * starts only the sliders not started yet.
 */
(function () {
    'use strict';

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');

    function set(figure, value) {
        figure.style.setProperty('--estate-ba', value + '%');
    }

    function sweep(figure, range) {
        if (reduced && reduced.matches) {
            return;
        }
        var start = null;
        var touched = false;
        var stop = function () { touched = true; };
        range.addEventListener('pointerdown', stop, { once: true });
        range.addEventListener('keydown', stop, { once: true });
        function frame(time) {
            if (touched) {
                return;
            }
            if (null === start) {
                start = time;
            }
            var t = Math.min(1, (time - start) / 1400);
            // 50 → 62 → 50, eased.
            var value = 50 + 12 * Math.sin(Math.PI * t) * (1 - t * 0.2);
            range.value = String(Math.round(value));
            set(figure, value.toFixed(2));
            if (t < 1) {
                window.requestAnimationFrame(frame);
            } else {
                range.value = '50';
                set(figure, 50);
            }
        }
        window.requestAnimationFrame(frame);
    }

    function start(figure) {
        if (figure.classList.contains('is-slider')) {
            return;
        }
        var range = figure.querySelector('.estate-ba-range');
        if (!range) {
            return;
        }
        figure.classList.add('is-slider');
        range.hidden = false;
        set(figure, range.value);
        range.addEventListener('input', function () { set(figure, range.value); });

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        observer.disconnect();
                        sweep(figure, range);
                    }
                });
            }, { threshold: 0.6 });
            observer.observe(figure);
        }
    }

    function init(root) {
        (root || document).querySelectorAll('[data-estate-ba]').forEach(start);
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
    // Pages swapped in place (transparent.js announces them; Turbo too).
    ['transparent:load', 'turbo:load', 'load'].forEach(function (name) {
        document.addEventListener(name, function () { init(); });
    });
    window.EstateBeforeAfter = { init: init };
})();
