(function () {
  'use strict';
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.addEventListener('DOMContentLoaded', function () {
    buildFaq();
    initFaqAccordion();
    initForm();
    initCounters();
    if (reduceMotion) { revealAllInk(); return; }
    initLeaves();
    initInkReveals();
  });

  /* ---------- FAQ данные и генерация (без innerHTML для текста) ---------- */
  var FAQ = [
    { q: 'Сколько стоит проект?', a: 'Зависит от объёма. Лендинг с ИИ-фичей — от базового пакета, веб-приложение с кастомным ИИ считаем индивидуально. Точную оценку даём после короткого брифа.' },
    { q: 'Сколько занимает разработка?', a: 'Лендинг — 2–3 недели. Корпоративный сайт — 4–6 недель. Веб-приложение с кастомной ИИ-логикой — от 6 недель, зависит от интеграций и данных.' },
    { q: 'Какие ИИ-фичи вы делаете?', a: 'Чат-боты и ассистенты, умный и семантический поиск, автоматизация рутины, а также кастомная ИИ-логика, обученная на ваших данных и процессах.' },
    { q: 'Кому принадлежит код и данные?', a: 'Всё передаём вам: исходники, доступы и данные. Никакого vendor lock-in — вы не привязаны к нам после запуска.' },
    { q: 'Что происходит после запуска?', a: 'Предлагаем абонентскую поддержку: мониторинг продукта, доработки и дообучение ИИ по мере роста данных и задач.' }
  ];

  function buildFaq() {
    var list = document.querySelector('.faq-list');
    if (!list) return;
    FAQ.forEach(function (it, i) {
      var item = document.createElement('div');
      item.className = 'faq-item';

      var btn = document.createElement('button');
      btn.className = 'faq-q';
      btn.type = 'button';
      btn.setAttribute('aria-expanded', i === 0 ? 'true' : 'false');
      btn.id = 'faq-q-' + i;
      var panelId = 'faq-a-' + i;
      btn.setAttribute('aria-controls', panelId);

      var qText = document.createElement('span');
      qText.className = 'faq-q-text';
      qText.textContent = it.q;                 // textContent — без риска HTML-инъекции
      var icon = document.createElement('span');
      icon.className = 'faq-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = '+';
      btn.appendChild(qText);
      btn.appendChild(icon);

      var panel = document.createElement('p');
      panel.className = 'faq-a';
      panel.id = panelId;
      panel.setAttribute('role', 'region');
      panel.setAttribute('aria-labelledby', btn.id);
      panel.textContent = it.a;
      if (i !== 0) panel.hidden = true;

      item.appendChild(btn);
      item.appendChild(panel);
      list.appendChild(item);
    });
  }

  function initFaqAccordion() {
    var list = document.querySelector('.faq-list');
    if (!list) return;
    list.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.faq-q') : null;
      if (!btn || !list.contains(btn)) return;
      var isOpen = btn.getAttribute('aria-expanded') === 'true';
      // одиночное раскрытие: закрываем всё
      list.querySelectorAll('.faq-q').forEach(function (b) {
        b.setAttribute('aria-expanded', 'false');
        var p = document.getElementById(b.getAttribute('aria-controls'));
        if (p) p.hidden = true;
      });
      if (!isOpen) {
        btn.setAttribute('aria-expanded', 'true');
        var panel = document.getElementById(btn.getAttribute('aria-controls'));
        if (panel) panel.hidden = false;
      }
    });
  }

  /* ---------- Форма (демо, preventDefault) ---------- */
  function initForm() {
    var form = document.querySelector('.contact-form');
    var thanks = document.querySelector('.contact-thanks');
    if (!form || !thanks) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      form.hidden = true;
      thanks.hidden = false;
      thanks.setAttribute('tabindex', '-1');
      thanks.focus();
    });
  }

  /* ---------- Счётчики ---------- */
  function initCounters() {
    var els = [].slice.call(document.querySelectorAll('[data-count]'));
    if (!els.length) return;
    if (reduceMotion || !('IntersectionObserver' in window)) {
      els.forEach(function (el) { el.textContent = String(parseInt(el.getAttribute('data-count'), 10) || 0); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        var target = parseInt(en.target.getAttribute('data-count'), 10) || 0;
        var t0 = performance.now(), dur = 1400;
        var run = function (t) {
          var p = Math.min(1, (t - t0) / dur);
          var e = 1 - Math.pow(1 - p, 3);
          en.target.textContent = String(Math.round(target * e));
          if (p < 1) requestAnimationFrame(run);
        };
        requestAnimationFrame(run);
      });
    }, { threshold: 0.5 });
    els.forEach(function (el) { io.observe(el); });
  }

  function revealAllInk() {
    [].slice.call(document.querySelectorAll('[data-ink]')).forEach(function (el) { el.style.opacity = '1'; });
  }

  /* ---------- Плывущие кленовые листья (canvas) ---------- */
  function initLeaves() {
    var canvas = document.querySelector('.hero-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    if (!ctx) return;
    var count = 26;
    var w = 0, h = 0, dpr = Math.min(window.devicePixelRatio || 1, 2);
    var leaves = [];

    function spawn(fromLeft) {
      return {
        x: fromLeft ? -30 : Math.random() * w,
        y: Math.random() * h * 0.9,
        vx: 0.6 + Math.random() * 1.4,
        vy: 0.15 + Math.random() * 0.4,
        size: 4 + Math.random() * 7,
        rot: Math.random() * Math.PI * 2,
        vr: (Math.random() - 0.5) * 0.06,
        phase: Math.random() * Math.PI * 2,
        ink: Math.random() < 0.35
      };
    }
    function resize() {
      var r = canvas.getBoundingClientRect();
      w = r.width; h = r.height;
      canvas.width = w * dpr; canvas.height = h * dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      leaves = Array.from({ length: count }, function () { return spawn(false); });
    }
    resize();
    window.addEventListener('resize', resize);

    var t = 0, raf = null, onScreen = true;
    function tick() {
      raf = requestAnimationFrame(tick);
      if (document.hidden || !onScreen) return;
      t += 0.016;
      ctx.clearRect(0, 0, w, h);
      var gust = 0.7 + Math.sin(t * 0.35) * 0.5 + Math.sin(t * 0.13) * 0.3;
      for (var i = 0; i < leaves.length; i++) {
        var L = leaves[i];
        L.x += L.vx * gust * 1.6;
        L.y += L.vy + Math.sin(t * 1.4 + L.phase) * 0.5;
        L.rot += L.vr * gust;
        if (L.x > w + 40 || L.y > h + 40) leaves[i] = spawn(true);
        ctx.save();
        ctx.translate(L.x, L.y);
        ctx.rotate(L.rot);
        var wob = 0.65 + Math.sin(t * 2 + L.phase) * 0.35;
        if (L.ink) {
          ctx.fillStyle = 'rgba(233,225,206,0.28)';
          ctx.beginPath();
          ctx.arc(0, 0, L.size * 0.35, 0, Math.PI * 2);
          ctx.fill();
        } else {
          ctx.fillStyle = 'rgba(192,57,43,' + (0.5 + wob * 0.3).toFixed(2) + ')';
          ctx.beginPath();
          ctx.ellipse(0, 0, L.size, L.size * 0.45 * wob, 0, 0, Math.PI * 2);
          ctx.fill();
          ctx.strokeStyle = 'rgba(120,30,22,0.5)';
          ctx.lineWidth = 0.8;
          ctx.beginPath();
          ctx.moveTo(-L.size, 0); ctx.lineTo(L.size * 0.9, 0);
          ctx.stroke();
        }
        ctx.restore();
      }
    }
    function start() { if (!raf) raf = requestAnimationFrame(tick); }
    function stop() { if (raf) { cancelAnimationFrame(raf); raf = null; } }
    // Останавливаем анимацию, когда hero уходит из вида — убирает джанк прокрутки
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        onScreen = entries[0].isIntersecting;
        if (onScreen) start(); else stop();
      }, { threshold: 0 });
      io.observe(canvas);
    }
    start();
  }

  /* ---------- Тушевое проявление заголовков (mask-анимация) ---------- */
  function makeInkSprite(frames, size, seed) {
    var c = document.createElement('canvas');
    c.width = frames * size; c.height = size;
    var ctx = c.getContext('2d');
    var fract = function (x) { return x - Math.floor(x); };
    var rnd = function (i) { return fract(Math.sin((i + seed * 137.13) * 12.9898) * 43758.5453); };
    var seeds = Array.from({ length: 4 }, function (_, i) {
      return {
        x: size * (0.22 + rnd(i * 4 + 1) * 0.56),
        y: size * (0.22 + rnd(i * 4 + 2) * 0.56),
        max: size * (0.6 + rnd(i * 4 + 3) * 0.35),
        ph: rnd(i * 4 + 4) * Math.PI * 2,
        delay: i === 0 ? 0 : rnd(i * 9 + 5) * 0.3
      };
    });
    var dots = Array.from({ length: 50 }, function (_, i) {
      return {
        x: size * rnd(i * 3 + 40),
        y: size * rnd(i * 3 + 41),
        r: size * (0.004 + rnd(i * 3 + 42) * 0.022),
        at: 0.05 + rnd(i * 3 + 43) * 0.6
      };
    });
    for (var f = 0; f < frames; f++) {
      var tt = f / (frames - 1);
      ctx.save();
      ctx.translate(f * size, 0);
      ctx.beginPath(); ctx.rect(0, 0, size, size); ctx.clip();
      ctx.fillStyle = '#000';
      if (tt > 0.97) { ctx.fillRect(0, 0, size, size); ctx.restore(); continue; }
      ctx.filter = 'blur(1.2px)';
      for (var si = 0; si < seeds.length; si++) {
        var s = seeds[si];
        var lt = Math.max(0, (tt - s.delay) / (1 - s.delay));
        if (lt <= 0) continue;
        var R = s.max * (1 - Math.pow(1 - lt, 2.4));
        ctx.beginPath();
        var STEPS = 72;
        for (var k = 0; k <= STEPS; k++) {
          var a = (k / STEPS) * Math.PI * 2;
          var ww = Math.sin(a * 3 + s.ph) + Math.sin(a * 7 + s.ph * 2.3) * 0.55 + Math.sin(a * 13 + s.ph * 4.1) * 0.3;
          var r = R * (1 + 0.26 * ww * (0.6 + 0.4 * lt));
          var x = s.x + Math.cos(a) * r, y = s.y + Math.sin(a) * r;
          if (k === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        ctx.closePath(); ctx.fill();
      }
      for (var di = 0; di < dots.length; di++) {
        var d = dots[di];
        if (tt < d.at) continue;
        var g = Math.min(1, (tt - d.at) * 7);
        ctx.beginPath(); ctx.arc(d.x, d.y, d.r * (0.5 + g), 0, Math.PI * 2); ctx.fill();
      }
      ctx.restore();
    }
    return c.toDataURL('image/png');
  }

  // Сам элемент + потомки с box-shadow, вместе с их реальными значениями тени
  function collectShadowed(el) {
    var nodes = [el].concat([].slice.call(el.querySelectorAll('*')));
    var res = [];
    nodes.forEach(function (n) {
      var bs = getComputedStyle(n).boxShadow;
      if (bs && bs !== 'none') res.push({ n: n, real: bs });
    });
    return res;
  }
  // та же геометрия тени, но прозрачный цвет — стартовая точка для плавного проявления
  function transparentShadow(bs) { return bs.replace(/rgba?\([^)]*\)/g, 'rgba(0,0,0,0)'); }

  function initInkReveals() {
    var els = [].slice.call(document.querySelectorAll('[data-ink]'));
    if (!els.length || !('IntersectionObserver' in window)) { revealAllInk(); return; }
    var FRAMES = 26;
    var sprites = null;
    try {
      sprites = [makeInkSprite(FRAMES, 340, 1), makeInkSprite(FRAMES, 340, 2), makeInkSprite(FRAMES, 340, 3)];
    } catch (e) { sprites = null; }
    var dur = 1.4;
    els.forEach(function (el, i) {
      if (!sprites) { el.style.opacity = '0'; el.style.transition = 'opacity 1s ease'; return; }
      var url = 'url(' + sprites[i % sprites.length] + ')';
      el.style.webkitMaskImage = url; el.style.maskImage = url;
      el.style.webkitMaskRepeat = 'no-repeat'; el.style.maskRepeat = 'no-repeat';
      el.style.webkitMaskSize = (FRAMES * 100) + '% 100%'; el.style.maskSize = (FRAMES * 100) + '% 100%';
      el.style.webkitMaskPosition = '0% 0%'; el.style.maskPosition = '0% 0%';
    });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        var el = en.target;
        if (!sprites) { el.style.opacity = '1'; return; }
        var idx = Math.max(0, els.indexOf(el));
        var delay = (idx % 4) * 0.14;
        el.style.animation = 'inkreveal ' + dur + 's steps(' + (FRAMES - 1) + ') ' + delay + 's both';
        setTimeout(function () {
          // Тени сейчас срезаны маской. Считываем их реальные значения.
          var shadowed = collectShadowed(el);
          // 1. ДО снятия маски делаем тени прозрачными (та же геометрия), без перехода
          shadowed.forEach(function (s) {
            s.n.style.transition = 'none';
            s.n.style.boxShadow = transparentShadow(s.real);
          });
          // 2. снимаем маску — тени прозрачные, поэтому рывка нет
          el.style.animation = '';
          el.style.webkitMaskImage = ''; el.style.maskImage = '';
          el.style.webkitMaskSize = ''; el.style.maskSize = '';
          el.style.webkitMaskPosition = ''; el.style.maskPosition = '';
          if (!shadowed.length) return;
          // 3. форсируем reflow — фиксируем «прозрачное» как старт перехода (надёжнее rAF)
          void el.offsetWidth;
          // 4. включаем переход и ставим реальную тень → тень плавно проступает
          shadowed.forEach(function (s) {
            s.n.style.transition = 'box-shadow 0.6s ease';
            s.n.style.boxShadow = s.real;
            setTimeout(function () {
              s.n.style.transition = ''; s.n.style.boxShadow = ''; // вернуть CSS/hover
            }, 700);
          });
        }, (dur + delay) * 1000 + 120);
      });
    }, { threshold: 0.12 });
    els.forEach(function (el) { io.observe(el); });
  }
})();
