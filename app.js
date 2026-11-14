/**
 * HR Ретрит · Школа Елены Витчак
 * Интерактив лендинга, перенос UTM-меток, предзаполнение B2B-счёта и телеметрия событий.
 */
(() => {
  'use strict';

  // Конфигурация метрик (подставить ID счётчика Яндекс.Метрики после получения доступа от помощницы)
  const ANALYTICS_CONFIG = {
    ymCounterId: null, // например: 98765432
    webhookEndpoint: '', // опционально: CRM / Bitrix24 / Tilda / Formspree / Telegram Bot webhook
    whatsappPhone: '79851010777',
    b2bEmail: 'hr.retrit@yandex.ru',
    yookassaUrl: 'https://yookassa.ru/my/i/ai_qNXLO-eTd/l',
  };

  /** Сбор и сохранение UTM-меток для сквозной аналитики */
  function readUtmParams() {
    const params = new URLSearchParams(window.location.search);
    const keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    const utm = {};
    keys.forEach((key) => {
      const value = params.get(key);
      if (value) utm[key] = value;
    });
    if (Object.keys(utm).length > 0) {
      try {
        sessionStorage.setItem('vitchak_utm', JSON.stringify(utm));
      } catch (_) {}
    }
    try {
      const stored = sessionStorage.getItem('vitchak_utm');
      return stored ? JSON.parse(stored) : utm;
    } catch (_) {
      return utm;
    }
  }

  /** Отправка целей в Яндекс.Метрику / консоль прототипа */
  function trackEvent(goalName, meta = {}) {
    const payload = {
      goal: goalName,
      ts: new Date().toISOString(),
      utm: readUtmParams(),
      ...meta,
    };
    if (
      ANALYTICS_CONFIG.ymCounterId &&
      typeof window.ym === 'function'
    ) {
      try {
        window.ym(ANALYTICS_CONFIG.ymCounterId, 'reachGoal', goalName, payload);
      } catch (_) {}
    }
    window.VitchakMetrics = window.VitchakMetrics || [];
    window.VitchakMetrics.push(payload);
  }

  // Трекинг кликов по ключевым CTA и кнопкам оплаты
  document.querySelectorAll('[data-track]').forEach((el) => {
    el.addEventListener('click', () => {
      trackEvent(el.getAttribute('data-track'), {
        href: el.getAttribute('href') || '',
      });
    });
  });

  // Мобильное меню
  const burgerBtn = document.getElementById('burgerBtn');
  const mobileNav = document.getElementById('mobileNav');
  if (burgerBtn && mobileNav) {
    burgerBtn.addEventListener('click', () => {
      const isOpen = mobileNav.classList.toggle('is-open');
      mobileNav.hidden = !isOpen;
      burgerBtn.setAttribute('aria-expanded', String(isOpen));
    });

    mobileNav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        mobileNav.classList.remove('is-open');
        mobileNav.hidden = true;
        burgerBtn.setAttribute('aria-expanded', 'false');
      });
    });
  }

  // Быстрое предзаполнение формы при запросе счёта для юрлица
  const prefillB2B = document.getElementById('prefillB2B');
  const leadComment = document.getElementById('leadComment');
  const leadEmail = document.getElementById('leadEmail');
  if (prefillB2B && leadComment) {
    prefillB2B.addEventListener('click', () => {
      if (!leadComment.value.trim()) {
        leadComment.value = 'Прошу выставить счёт для оплаты от юридического лица (59 000 ₽) и прислать закрывающие документы.';
      }
      setTimeout(() => {
        if (leadEmail) leadEmail.focus();
      }, 260);
    });
  }

  // Обработка формы заявки с валидацией 152-ФЗ и прямой передачей в WhatsApp / Email / ЮKassa
  const form = document.getElementById('retreatForm');
  const feedback = document.getElementById('formFeedback');
  if (form && feedback) {
    form.addEventListener('submit', (e) => {
      e.preventDefault();

      const nameInput = document.getElementById('leadName');
      const contactInput = document.getElementById('leadContact');
      const consentCheckbox = document.getElementById('consentPd');

      const name = (nameInput?.value || '').trim();
      const contact = (contactInput?.value || '').trim();
      const email = (leadEmail?.value || '').trim();
      const comment = (leadComment?.value || '').trim();

      if (!name || !contact) {
        showFeedback('Пожалуйста, укажите имя и телефон или Telegram для связи.');
        return;
      }

      if (consentCheckbox && !consentCheckbox.checked) {
        showFeedback('Необходимо согласие на обработку персональных данных и условия оферты.');
        return;
      }

      const utm = readUtmParams();
      trackEvent('retreat_form_submit', { hasEmail: Boolean(email) });

      const messageLines = [
        'Заявка на HR-ретрит Елены Витчак (поток 1 декабря 2026)',
        `Имя: ${name}`,
        `Контакт: ${contact}`,
        email ? `Email: ${email}` : null,
        comment ? `Комментарий: ${comment}` : null,
        Object.keys(utm).length ? `UTM: ${JSON.stringify(utm)}` : null,
      ].filter(Boolean);

      const encodedText = encodeURIComponent(messageLines.join('\n'));
      const waHref = `https://wa.me/${ANALYTICS_CONFIG.whatsappPhone}?text=${encodedText}`;
      const mailHref = `mailto:${ANALYTICS_CONFIG.b2bEmail}?subject=${encodeURIComponent('Заявка на HR-ретрит (1 декабря 2026)')}&body=${encodedText}`;

      // В рабочей версии здесь выполняется POST-отправка на ANALYTICS_CONFIG.webhookEndpoint / Telegram-бот
      showFeedbackHtml(`
        <strong>Заявка сформирована для ${escapeHtml(name)}.</strong><br>
        Выберите быстрый шаг, чтобы отправить данные в 1 клик или сразу оплатить место:<br>
        <span style="display:inline-flex;flex-wrap:wrap;gap:10px;margin-top:8px;">
          <a href="${waHref}" target="_blank" rel="noopener noreferrer" style="font-weight:700;text-decoration:underline;">Отправить в WhatsApp →</a>
          <a href="${mailHref}" style="font-weight:700;text-decoration:underline;">Запросить счёт по Email →</a>
          <a href="${ANALYTICS_CONFIG.yookassaUrl}" target="_blank" rel="noopener noreferrer" style="font-weight:700;text-decoration:underline;">Оплатить 59 000 ₽ в ЮKassa →</a>
        </span>
      `);
    });
  }

  function showFeedback(text) {
    if (!feedback) return;
    feedback.textContent = text;
    feedback.classList.add('is-visible');
  }

  function showFeedbackHtml(html) {
    if (!feedback) return;
    feedback.innerHTML = html;
    feedback.classList.add('is-visible');
  }

  function escapeHtml(str) {
    return str
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;');
  }

  // Запись визита с UTM-метками при загрузке страницы
  readUtmParams();
  trackEvent('page_view_vitchak_retreat');
})();
