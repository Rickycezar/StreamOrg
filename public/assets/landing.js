/* Landing page testimonials carousel. Without this file the cards stay a swipeable strip. */
(function () {
    'use strict';

    document.querySelectorAll('[data-carousel]').forEach(function (root) {
        const track = root.querySelector('[data-carousel-track]');
        const cards = Array.prototype.slice.call(track.children);
        const dots = Array.prototype.slice.call(root.querySelectorAll('[data-carousel-dots] button'));
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let active = 0;
        let timer = null;

        if (!cards.length) return;

        function mark(index) {
            active = index;
            cards.forEach(function (card, i) { card.classList.toggle('active', i === index); });
            dots.forEach(function (dot, i) {
                if (i === index) dot.setAttribute('aria-current', 'true');
                else dot.removeAttribute('aria-current');
            });
        }

        function go(index) {
            const target = cards[(index + cards.length) % cards.length];
            track.scrollTo({
                left: target.offsetLeft - (track.clientWidth - target.offsetWidth) / 2,
                behavior: reduced ? 'auto' : 'smooth',
            });
        }

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) mark(cards.indexOf(entry.target));
            });
        }, { root: track, threshold: 0.6 });

        cards.forEach(function (card) { observer.observe(card); });

        const prev = root.querySelector('[data-carousel-prev]');
        const next = root.querySelector('[data-carousel-next]');
        if (prev) prev.addEventListener('click', function () { stop(); go(active - 1); });
        if (next) next.addEventListener('click', function () { stop(); go(active + 1); });
        dots.forEach(function (dot, i) { dot.addEventListener('click', function () { stop(); go(i); }); });

        cards.forEach(function (card, i) {
            card.addEventListener('click', function () {
                if (i !== active) { stop(); go(i); }
            });
        });

        function stop() {
            clearInterval(timer);
            timer = null;
        }

        if (!reduced && cards.length > 1) {
            timer = setInterval(function () { go(active + 1); }, 7000);
            root.addEventListener('pointerenter', stop);
            root.addEventListener('focusin', stop);
        }

        const middle = Math.floor((cards.length - 1) / 2);
        track.scrollLeft = cards[middle].offsetLeft - (track.clientWidth - cards[middle].offsetWidth) / 2;
        mark(middle);
        root.classList.add('ready');
    });
})();
