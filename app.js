/**
 * HR Ретрит · Школа Елены Витчак
 * Интерактив лендинга, consent-gated Яндекс.Метрика (152-ФЗ), перенос UTM/yclid и hybrid-обработка заявок B2C/B2B.
 */
(() => {
  'use strict';

  const ANALYTICS_CONFIG = {
    ymCounterId: null, // ID счётчика Яндекс.Метрики (указывается после подключения кабинета клиента)
    webhookEndpoint: '', // Серверный обработчик на домене РФ (автоподстановка /api/hr-retreat-lead.php на evitchak.ru / evitchak.com)
    whatsappPhone: '79256697377',
    telegramHandle: 'alvitcha',
    b2bEmail: 'hr.retrit@yandex.ru',
    yookassaUrl: '', // Временно скрыта устаревшая ссылка (старая сумма 39 000 ₽ и старое название); подставить новую на 50 000 / 59 000 ₽ от бухгалтера
    streamLabel: 'HR-ретрит · 4-й запуск (14 января – 4 февраля)',
    earlyPriceRub: 50000,
    standardPriceRub: 59000,
    earlyDeadlineLabel: '23 декабря',
    consentStorageKey: 'vitchak_cookie_consent_v1',
  };

  const EARLY_DEADLINE_MS = Date.parse('2026-12-23T23:59:59+03:00');
  let metrikaLoaded = false;

  /** Возвращает true, пока действует раннее бронирование (до 23 декабря 2026 включительно) */
  function isEarlyBirdActive() {
    try {
      const previewPhase = new URLSearchParams(window.location.search).get('phase');
      if (previewPhase === 'standard') return false;
    } catch (_) {
      // Окружение без URLSearchParams
    }
    return Date.now() <= EARLY_DEADLINE_MS;
  }

  /** Возвращает URL серверного обработчика с учётом боевых доменов evitchak.ru / evitchak.com */
  function getWebhookEndpoint() {
    if (ANALYTICS_CONFIG.webhookEndpoint) return ANALYTICS_CONFIG.webhookEndpoint;
    const host = window.location.hostname.toLowerCase();
    if (
      host === 'evitchak.ru' ||
      host === 'www.evitchak.ru' ||
      host === 'evitchak.com' ||
      host === 'www.evitchak.com' ||
      host === 'localhost' ||
      host === '127.0.0.1'
    ) {
      return '/api/hr-retreat-lead.php';
    }
    return '';
  }

  /** Определяет актуальную цену с учётом дедлайна раннего бронирования (23 декабря 2026) */
  function getActivePriceRub() {
    return isEarlyBirdActive()
      ? ANALYTICS_CONFIG.earlyPriceRub
      : ANALYTICS_CONFIG.standardPriceRub;
  }

  function formatRub(amount) {
    return `${String(amount).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} ₽`;
  }

  /** Сбор и сохранение UTM/рекламных меток для сквозной аналитики */
  function readUtmParams() {
    const params = new URLSearchParams(window.location.search);
    const keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid', 'gclid'];
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

  function syncUtmInputs() {
    const utm = readUtmParams();
    const map = {
      utmSource: utm.utm_source || '',
      utmMedium: utm.utm_medium || '',
      utmCampaign: utm.utm_campaign || '',
      utmContent: utm.utm_content || '',
      utmTerm: utm.utm_term || '',
      utmYclid: utm.yclid || '',
      pageUrlField: window.location.href,
    };
    Object.entries(map).forEach(([id, val]) => {
      const el = document.getElementById(id);
      if (el) el.value = val;
    });
  }

  /** Отправка целей в Яндекс.Метрику (только при согласии пользователя и наличии ID) */
  function trackEvent(goalName, meta = {}) {
    const payload = {
      goal: goalName,
      ts: new Date().toISOString(),
      utm: readUtmParams(),
      ...meta,
    };
    if (ANALYTICS_CONFIG.ymCounterId && typeof window.ym === 'function') {
      try {
        window.ym(ANALYTICS_CONFIG.ymCounterId, 'reachGoal', goalName, payload);
      } catch (_) {}
    }
    window.VitchakMetrics = window.VitchakMetrics || [];
    window.VitchakMetrics.push(payload);
  }

  /** Загрузка скрипта Яндекс.Метрики строго после согласия пользователя */
  function loadMetrikaAfterConsent() {
    if (metrikaLoaded || !ANALYTICS_CONFIG.ymCounterId) return;
    metrikaLoaded = true;

    (function (m, e, t, r, i, k, a) {
      m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
      m[i].l = 1 * new Date();
      k = e.createElement(t);
      a = e.getElementsByTagName(t)[0];
      k.async = 1;
      k.src = r;
      a.parentNode.insertBefore(k, a);
    })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');

    window.ym(ANALYTICS_CONFIG.ymCounterId, 'init', {
      clickmap: true,
      trackLinks: true,
      accurateTrackBounce: true,
      webvisor: false,
    });

    try {
      window.ym(ANALYTICS_CONFIG.ymCounterId, 'getClientID', (clientId) => {
        const input = document.getElementById('ymClientId');
        if (input && clientId) input.value = String(clientId);
      });
    } catch (_) {}
  }

  /** Cookie Consent Banner (152-ФЗ) */
  function initCookieConsent() {
    const banner = document.getElementById('cookieBanner');
    const acceptBtn = document.getElementById('cookieAcceptAll');
    const necessaryBtn = document.getElementById('cookieNecessaryOnly');

    let saved = null;
    try {
      saved = localStorage.getItem(ANALYTICS_CONFIG.consentStorageKey);
    } catch (_) {}

    if (saved === 'all') {
      loadMetrikaAfterConsent();
      return;
    }
    if (saved === 'necessary') {
      return;
    }
    if (!banner) return;

    banner.hidden = false;

    const saveChoice = (value) => {
      try {
        localStorage.setItem(ANALYTICS_CONFIG.consentStorageKey, value);
      } catch (_) {}
      banner.hidden = true;
      if (value === 'all') {
        loadMetrikaAfterConsent();
        trackEvent('cookie_consent_all');
      }
    };

    if (acceptBtn) acceptBtn.addEventListener('click', () => saveChoice('all'));
    if (necessaryBtn) necessaryBtn.addEventListener('click', () => saveChoice('necessary'));
  }

  // Трекинг кликов по ключевым CTA и кнопкам оплаты
  document.querySelectorAll('[data-track]').forEach((el) => {
    el.addEventListener('click', () => {
      trackEvent(el.getAttribute('data-track'), {
        href: el.getAttribute('href') || '',
      });
    });
  });

  // Мобильное меню (aria-expanded + inert sync + Escape / outside click)
  const burgerBtn = document.getElementById('burgerBtn');
  const mobileNav = document.getElementById('mobileNav');
  if (burgerBtn && mobileNav) {
    const closeMobileNav = (returnFocus = false) => {
      if (!mobileNav.classList.contains('is-open')) return;
      mobileNav.classList.remove('is-open');
      mobileNav.hidden = true;
      mobileNav.inert = true;
      burgerBtn.setAttribute('aria-expanded', 'false');
      if (returnFocus) burgerBtn.focus();
    };

    mobileNav.inert = mobileNav.hidden;
    burgerBtn.addEventListener('click', () => {
      const isOpen = mobileNav.classList.toggle('is-open');
      mobileNav.hidden = !isOpen;
      mobileNav.inert = !isOpen;
      burgerBtn.setAttribute('aria-expanded', String(isOpen));
    });

    mobileNav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => closeMobileNav(false));
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeMobileNav(true);
    });

    document.addEventListener('click', (e) => {
      if (
        mobileNav.classList.contains('is-open') &&
        !mobileNav.contains(e.target) &&
        !burgerBtn.contains(e.target)
      ) {
        closeMobileNav(false);
      }
    });
  }

  // Переключатель формата оплаты B2C / B2B
  const payerRadios = document.querySelectorAll('input[name="payer_type"]');
  const payerPillB2c = document.getElementById('payerPillB2c');
  const payerPillB2b = document.getElementById('payerPillB2b');
  const b2bCompanyField = document.getElementById('b2bCompanyField');
  const submitLeadBtn = document.getElementById('submitLeadBtn');

  function syncPricingPhaseUI() {
    const phase = isEarlyBirdActive() ? 'early' : 'standard';
    document.documentElement.dataset.pricingPhase = phase;
    const checkedMode = document.querySelector('input[name="payer_type"]:checked')?.value || 'b2c';
    applyPayerMode(checkedMode);
  }

  function applyPayerMode(mode) {
    const isB2b = mode === 'b2b';
    const earlyActive = isEarlyBirdActive();
    const activePriceLabel = formatRub(getActivePriceRub());
    if (payerPillB2c) payerPillB2c.classList.toggle('is-active', !isB2b);
    if (payerPillB2b) payerPillB2b.classList.toggle('is-active', isB2b);
    if (b2bCompanyField) b2bCompanyField.hidden = !isB2b;
    if (submitLeadBtn) {
      let labelPrefix;
      if (isB2b) {
        labelPrefix = 'Запросить счёт на компанию ';
      } else {
        labelPrefix = earlyActive
          ? 'Отправить заявку и получить ссылку '
          : 'Получить ссылку на оплату ';
      }
      const priceSpan = document.createElement('span');
      priceSpan.style.whiteSpace = 'nowrap';
      priceSpan.textContent = `(${activePriceLabel})`;
      submitLeadBtn.replaceChildren(document.createTextNode(labelPrefix), priceSpan);
    }
  }

  payerRadios.forEach((radio) => {
    radio.addEventListener('change', () => {
      if (radio.checked) applyPayerMode(radio.value);
    });
  });

  // Предзаполнение B2C (ссылка ИП) или B2B (счёт на компанию)
  const prefillB2cPay = document.getElementById('prefillB2cPay');
  const prefillB2B = document.getElementById('prefillB2B');
  const leadName = document.getElementById('leadName');
  const leadComment = document.getElementById('leadComment');
  const leadEmail = document.getElementById('leadEmail');
  const leadCompany = document.getElementById('leadCompany');

  if (prefillB2cPay) {
    prefillB2cPay.addEventListener('click', () => {
      const b2cRadio = document.querySelector('input[name="payer_type"][value="b2c"]');
      if (b2cRadio) {
        b2cRadio.checked = true;
        applyPayerMode('b2c');
      }
      const activePriceLabel = formatRub(getActivePriceRub());
      if (leadComment && !leadComment.value.trim()) {
        leadComment.value = isEarlyBirdActive()
          ? `Прошу оформить участие по ранней цене ${activePriceLabel} (до ${ANALYTICS_CONFIG.earlyDeadlineLabel}, полная стоимость 59 000 ₽) и направить ссылку на оплату от ИП.`
          : `Прошу направить прямую ссылку на оплату участия от ИП (${activePriceLabel}).`;
      }
      setTimeout(() => {
        if (leadName) leadName.focus();
      }, 280);
    });
  }

  if (prefillB2B) {
    prefillB2B.addEventListener('click', () => {
      const b2bRadio = document.querySelector('input[name="payer_type"][value="b2b"]');
      if (b2bRadio) {
        b2bRadio.checked = true;
        applyPayerMode('b2b');
      }
      const activePriceLabel = formatRub(getActivePriceRub());
      if (leadComment && !leadComment.value.trim()) {
        leadComment.value = `Прошу выставить счёт для оплаты от юридического лица (${activePriceLabel}) и прислать закрывающие документы.`;
      }
      setTimeout(() => {
        if (leadCompany) leadCompany.focus();
        else if (leadEmail) leadEmail.focus();
      }, 280);
    });
  }

  // Копирование B2B-почты в буфер в 1 клик
  const copyB2bEmailBtn = document.getElementById('copyB2bEmailBtn');
  if (copyB2bEmailBtn) {
    const defaultLabel = copyB2bEmailBtn.textContent;
    copyB2bEmailBtn.addEventListener('click', async () => {
      const email = copyB2bEmailBtn.getAttribute('data-copy-email') || ANALYTICS_CONFIG.b2bEmail;
      const ok = await copyToClipboard(email);
      if (ok) {
        copyB2bEmailBtn.textContent = 'почта скопирована ✓';
        trackEvent('copy_b2b_email');
        setTimeout(() => {
          copyB2bEmailBtn.textContent = defaultLabel;
        }, 2400);
      }
    });
  }

  // Обработка формы заявки: 152-ФЗ (2 обязательных + 1 добровольное согласие) + server/direct fallback
  const form = document.getElementById('retreatForm');
  const feedback = document.getElementById('formFeedback');
  if (form && feedback) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const websiteHp = (document.getElementById('leadWebsiteHp')?.value || '').trim();
      if (websiteHp) {
        showFeedback('Заявка принята.');
        return;
      }

      const name = (leadName?.value || '').trim();
      const contact = (document.getElementById('leadContact')?.value || '').trim();
      const email = (leadEmail?.value || '').trim();
      const company = (leadCompany?.value || '').trim();
      const comment = (leadComment?.value || '').trim();
      const payerMode = document.querySelector('input[name="payer_type"]:checked')?.value || 'b2c';

      const consentPd = document.getElementById('consentPd');
      const consentOffer = document.getElementById('consentOffer');
      const consentPromo = document.getElementById('consentPromo');

      if (!name || !contact) {
        showFeedback('Пожалуйста, укажите имя и телефон или Telegram для связи.');
        return;
      }

      if ((consentPd && !consentPd.checked) || (consentOffer && !consentOffer.checked)) {
        showFeedback('Отметьте оба обязательных согласия: на обработку персональных данных и условия публичной оферты.');
        return;
      }

      const earlyActive = isEarlyBirdActive();
      const activePriceRub = getActivePriceRub();
      const activePriceLabel = formatRub(activePriceRub);
      const utm = readUtmParams();
      const ymClientId = document.getElementById('ymClientId')?.value || '';
      const pdnOk = Boolean(consentPd?.checked);
      const offerOk = Boolean(consentOffer?.checked);
      const promoOk = Boolean(consentPromo?.checked);
      const payload = {
        program: ANALYTICS_CONFIG.streamLabel,
        priceRub: activePriceRub,
        payerType: payerMode,
        name,
        contact,
        email,
        company,
        comment,
        website_hp: websiteHp,
        pdnConsent: pdnOk,
        offerConsent: offerOk,
        marketingConsent: promoOk,
        consentPd: pdnOk,
        consentOffer: offerOk,
        consentPromo: promoOk,
        utm,
        ymClientId,
        pageUrl: window.location.href,
      };

      trackEvent('retreat_form_submit', {
        payerType: payerMode,
        priceRub: activePriceRub,
        hasEmail: Boolean(email),
        consentPromo: promoOk,
      });

      const rateNote = earlyActive
        ? `раннее бронирование до ${ANALYTICS_CONFIG.earlyDeadlineLabel}`
        : 'стандартная стоимость участия';

      const messageLines = [
        `Заявка на ${ANALYTICS_CONFIG.streamLabel}`,
        `Стоимость по заявке: ${activePriceLabel} (${rateNote})`,
        `Формат оплаты: ${payerMode === 'b2b' ? 'Счёт на компанию (B2B)' : 'Личная оплата (ссылка на счёт ИП)'}`,
        `Имя: ${name}`,
        `Контакт: ${contact}`,
        email ? `Email: ${email}` : null,
        company && payerMode === 'b2b' ? `Компания / ИНН: ${company}` : null,
        comment ? `Комментарий: ${comment}` : null,
        Object.keys(utm).length ? `UTM: ${JSON.stringify(utm)}` : null,
      ].filter(Boolean);

      const textSummary = messageLines.join('\n');
      const encodedText = encodeURIComponent(textSummary);
      const tgHref = `https://t.me/${ANALYTICS_CONFIG.telegramHandle}?text=${encodedText}`;
      const mailHref = `mailto:${ANALYTICS_CONFIG.b2bEmail}?subject=${encodeURIComponent('Заявка на HR-ретрит (14 января – 4 февраля)')}&body=${encodedText}`;
      const waHref = `https://wa.me/${ANALYTICS_CONFIG.whatsappPhone}?text=${encodedText}`;

      let serverDelivered = false;
      const webhookUrl = getWebhookEndpoint();
      if (webhookUrl) {
        if (submitLeadBtn) submitLeadBtn.disabled = true;
        try {
          const controller = new AbortController();
          const timer = setTimeout(() => controller.abort(), 5000);
          const resp = await fetch(webhookUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            signal: controller.signal,
          });
          clearTimeout(timer);
          const data = await resp.json().catch(() => null);
          serverDelivered = Boolean(resp.ok && data && data.ok === true);
        } catch (_) {
          serverDelivered = false;
        } finally {
          if (submitLeadBtn) submitLeadBtn.disabled = false;
        }
      }

      const headerNote = serverDelivered
        ? `<strong>Заявка для ${escapeHtml(name)} принята (цена ${activePriceLabel}).</strong><br>Доступны также быстрые действия:`
        : `<strong>Заявка сформирована для ${escapeHtml(name)} (цена ${activePriceLabel}).</strong><br>Отправьте заявку в удобный мессенджер — бухгалтер направит прямую ссылку на оплату от ИП или счёт B2B:`;

      const yookassaLabel = earlyActive
        ? 'Полный тариф 59 000 ₽ в ЮKassa →'
        : 'Оплата 59 000 ₽ онлайн в ЮKassa →';

      showFeedbackHtml(`
        ${headerNote}<br>
        <span style="display:inline-flex;flex-wrap:wrap;gap:8px 14px;margin-top:8px;">
          <a href="${tgHref}" target="_blank" rel="noopener noreferrer" style="font-weight:700;text-decoration:underline;">Получить ссылку в Telegram →</a>
          <a href="${mailHref}" style="font-weight:700;text-decoration:underline;">Отправить на Email →</a>
          <a href="${waHref}" target="_blank" rel="noopener noreferrer" style="font-weight:700;text-decoration:underline;">WhatsApp* →</a>
          ${payerMode === 'b2c' && ANALYTICS_CONFIG.yookassaUrl ? `<a href="${ANALYTICS_CONFIG.yookassaUrl}" target="_blank" rel="noopener noreferrer" style="font-weight:600;text-decoration:underline;">${yookassaLabel}</a>` : ''}
          <button type="button" class="copy-email-btn" id="copyLeadBtn">Скопировать текст заявки</button>
        </span>
      `);

      const copyLeadBtn = document.getElementById('copyLeadBtn');
      if (copyLeadBtn) {
        copyLeadBtn.addEventListener('click', async () => {
          const copied = await copyToClipboard(textSummary);
          if (copied) copyLeadBtn.textContent = 'Текст заявки скопирован ✓';
        });
      }
    });
  }

  async function copyToClipboard(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (_) {}
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    } catch (_) {
      return false;
    }
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

  /** Переключатель 3 вариантов оформления (цвет + шрифтовая пара) */
  function initThemeSwitcher() {
    const THEME_META = {
      emerald: '#144E46',
      editorial: '#622230',
      graphite: '#1D3557',
    };
    const ALIAS = { '1': 'emerald', '2': 'editorial', '3': 'graphite' };
    const buttons = document.querySelectorAll('[data-theme-choice]');
    const themeColorMeta = document.querySelector('meta[name="theme-color"]');

    const normalize = (val) => {
      const key = ALIAS[val] || val;
      return THEME_META[key] ? key : 'emerald';
    };

    const applyTheme = (rawTheme, updateUrl) => {
      const theme = normalize(rawTheme);
      if (theme === 'emerald') {
        document.documentElement.removeAttribute('data-theme');
      } else {
        document.documentElement.dataset.theme = theme;
      }
      if (themeColorMeta) themeColorMeta.setAttribute('content', THEME_META[theme]);

      buttons.forEach((btn) => {
        const active = btn.getAttribute('data-theme-choice') === theme;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', String(active));
      });

      if (updateUrl) {
        try {
          const url = new URL(window.location.href);
          url.searchParams.set('theme', theme);
          window.history.replaceState({}, '', url.toString());
        } catch (_) {}
      }
    };

    const initialParam = new URLSearchParams(window.location.search).get('theme');
    applyTheme(initialParam || document.documentElement.dataset.theme || 'emerald', false);

    buttons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const chosen = btn.getAttribute('data-theme-choice') || 'emerald';
        applyTheme(chosen, true);
        trackEvent('theme_switch', { theme: chosen });
      });
    });
  }

  initThemeSwitcher();
  syncPricingPhaseUI();
  syncUtmInputs();
  initCookieConsent();
  trackEvent('page_view_vitchak_retreat');
})();
