/*
 * Справка: как фронтенд общается с send.php.
 * Это НЕ файл для подключения — код уже встроен в ../index.html (внизу, в <script>).
 * Здесь он выписан отдельно, чтобы бэкендеру был виден контракт.
 *
 * Менять во фронте нужно только одну строку — и только если send.php окажется
 * на другом домене (сайт на GitHub Pages, скрипт на Beget):
 *
 *     var FORM_ENDPOINT = 'send.php';   →   'https://домен.ru/send.php'
 */

var FORM_ENDPOINT = 'send.php';

// Обе формы обрабатываются одной функцией, отличаются только type и текстом успеха:
//   wireForm('contactForm', 'contactSlot', 'lead',   'Заявка принята', '…');
//   wireForm('reviewForm',  'reviewSlot',  'review', 'Спасибо за отзыв', '…');

function wireForm(formId, slotId, type, okTitle, okText) {
  var form = document.getElementById(formId);
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = form.querySelector('.form__submit');
    var note = form.querySelector('.form-note');
    var label = btn.textContent;

    // Отправляем только те поля, что есть в этой форме (+ honeypot company).
    var data = { type: type };
    ['name', 'contact', 'message', 'text', 'company'].forEach(function (k) {
      if (form.elements[k]) data[k] = form.elements[k].value;
    });

    btn.disabled = true;
    btn.textContent = 'Отправляем…';

    fetch(FORM_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (res) {
        if (!res || !res.ok) return Promise.reject(res && res.error);
        // успех — форма заменяется блоком «принято»
        document.getElementById(slotId).innerHTML =
          '<div class="form-success"><div class="stamp" aria-hidden="true">承</div>' +
          '<h3>' + okTitle + '</h3><p>' + okText + '</p></div>';
      })
      .catch(function () {
        // ошибка — форму оставляем, чтобы можно было повторить
        btn.disabled = false;
        btn.textContent = label;
        note.textContent = 'Отправить не получилось. Напишите нам в Telegram @fggtf24 или на lumensites24@bk.ru.';
        note.style.color = 'var(--crimson)';
      });
  });
}
