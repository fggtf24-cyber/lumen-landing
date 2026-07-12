/*
 * Как подключить форму лендинга к бэкенду.
 * Во фронте (../index.html) нужно сделать 2 правки — см. шаги ниже.
 */

/* ─────────────────────────────────────────────────────────────────────────
 * ШАГ 1. Honeypot-поле (антиспам). Добавьте внутрь <form id="contactForm">
 * скрытое поле — реальные люди его не видят и не заполняют, боты заполняют:
 *
 *   <input type="text" name="company" tabindex="-1" autocomplete="off"
 *          style="position:absolute;left:-9999px" aria-hidden="true">
 * ───────────────────────────────────────────────────────────────────────── */

/* ─────────────────────────────────────────────────────────────────────────
 * ШАГ 2. Замените текущий обработчик submit формы в <script> внизу index.html
 * на этот. Пропишите свой URL API в LEAD_ENDPOINT.
 * ───────────────────────────────────────────────────────────────────────── */

var LEAD_ENDPOINT = 'https://ВАШ-API-ХОСТ/api/lead'; // ← подставить после деплоя

document.getElementById('contactForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var form = e.currentTarget;
  var slot = document.getElementById('contactSlot');
  var btn = form.querySelector('.form__submit');

  var payload = {
    name: form.elements.name.value,
    contact: form.elements.contact.value,
    message: form.elements.message.value,
    company: form.elements.company ? form.elements.company.value : '' // honeypot
  };

  btn.disabled = true;
  var original = btn.textContent;
  btn.textContent = 'Отправляем…';

  fetch(LEAD_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function () {
      // тот же блок успеха, что и в текущей верстке
      slot.innerHTML =
        '<div class="form-success">' +
        '<div class="stamp" aria-hidden="true">承</div>' +
        '<h3>Заявка принята</h3>' +
        '<p>Свяжемся с вами в ближайшее время.</p>' +
        '</div>';
    })
    .catch(function () {
      btn.disabled = false;
      btn.textContent = original;
      alert('Не удалось отправить заявку. Напишите нам в Telegram @fggtf24 или на fggtf24@gmail.com');
    });
});
