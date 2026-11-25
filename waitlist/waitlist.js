/**
 * Лист ожидания HR-ретрита (evitchak.ru/waitlist/)
 * Валидация 5 полей, consent-gated Яндекс.Метрика 114307438 (152-ФЗ), перенос UTM/yclid и отправка в РФ API.
 */
(() => {
  'use strict';

  const CONFIG = {
    ymCounterId: 114307438,
    consentStorageKey: 'vitchak_cookie_consent_v1',
    endpoint: '/api/waitlist-lead.php',
  };

  let metrikaLoaded = false;

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function readUtmParams() {
    const params = new URLSearchParams(window.location.search);
    const keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid', 'gclid'];
    const utm = {};
    keys.forEach((key) => {
      const val = params.get(key);
      if (val) utm[key] = val;
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
      wlUtmSource: utm.utm_source || '',
      wlUtmMedium: utm.utm_medium || '',
      wlUtmCampaign: utm.utm_campaign || '',
      wlUtmContent: utm.utm_content || '',
      wlUtmTerm: utm.utm_term || '',
      wlUtmYclid: utm.yclid || '',
      wlPageUrl: window.location.href,
    };
    Object.entries(map).forEach(([id, val]) => {
      const el = document.getElementById(id);
      if (el) el.value = val;
    });
  }

  function trackEvent(goalName, meta = {}) {
    if (CONFIG.ymCounterId && typeof window.ym === 'function') {
      try {
        window.ym(CONFIG.ymCounterId, 'reachGoal', goalName, {
          goal: goalName,
          ts: new Date().toISOString(),
          utm: readUtmParams(),
          ...meta,
        });
      } catch (_) {}
    }
  }

  function loadMetrikaAfterConsent() {
    if (metrikaLoaded || !CONFIG.ymCounterId) return;
    metrikaLoaded = true;

    (function (m, e, t, r, i, k, a) {
      m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
      m[i].l = 1 * new Date();
      for (var j = 0; j < e.scripts.length; j++) { if (e.scripts[j].src === r) { return; } }
      k = e.createElement(t);
      a = e.getElementsByTagName(t)[0];
      k.async = 1;
      k.src = r;
      a.parentNode.insertBefore(k, a);
    })(window, document, 'script', `https://mc.yandex.ru/metrika/tag.js?id=${CONFIG.ymCounterId}`, 'ym');

    window.ym(CONFIG.ymCounterId, 'init', {
      clickmap: true,
      trackLinks: true,
      accurateTrackBounce: true,
      referrer: document.referrer,
      url: location.href,
      webvisor: false,
    });

    try {
      window.ym(CONFIG.ymCounterId, 'getClientID', (clientId) => {
        const input = document.getElementById('wlYmClientId');
        if (input && clientId) input.value = String(clientId);
      });
    } catch (_) {}
  }

  function initCookieConsent() {
    const banner = document.getElementById('cookieBanner');
    const acceptBtn = document.getElementById('cookieAcceptAll');
    const necessaryBtn = document.getElementById('cookieNecessaryOnly');

    let saved = null;
    try {
      saved = localStorage.getItem(CONFIG.consentStorageKey);
    } catch (_) {}

    if (saved === 'all') {
      loadMetrikaAfterConsent();
      return;
    }
    if (saved === 'necessary' || !banner) {
      return;
    }

    banner.hidden = false;
    const saveChoice = (val) => {
      try {
        localStorage.setItem(CONFIG.consentStorageKey, val);
      } catch (_) {}
      banner.hidden = true;
      if (val === 'all') {
        loadMetrikaAfterConsent();
        trackEvent('cookie_consent_all');
      }
    };
    if (acceptBtn) acceptBtn.addEventListener('click', () => saveChoice('all'));
    if (necessaryBtn) necessaryBtn.addEventListener('click', () => saveChoice('necessary'));
  }

  function initMobileMenu() {
    const burgerBtn = document.getElementById('wlBurgerBtn');
    const mobileNav = document.getElementById('wlMobileNav');
    if (!burgerBtn || !mobileNav) return;

    const closeNav = (returnFocus = false) => {
      if (!mobileNav.classList.contains('is-open')) return;
      mobileNav.classList.remove('is-open');
      mobileNav.hidden = true;
      mobileNav.inert = true;
      burgerBtn.setAttribute('aria-expanded', 'false');
      if (returnFocus) burgerBtn.focus();
    };

    mobileNav.inert = mobileNav.hidden;
    burgerBtn.addEventListener('click', () => {
      const open = mobileNav.classList.toggle('is-open');
      mobileNav.hidden = !open;
      mobileNav.inert = !open;
      burgerBtn.setAttribute('aria-expanded', String(open));
    });
    mobileNav.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => closeNav(false)));
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeNav(true);
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth > 960) closeNav(false);
    });
  }

  function normalizeTelegramHandle(raw) {
    let val = String(raw || '').trim();
    val = val.replace(/^https?:\/\/(t\.me|telegram\.me)\//i, '').replace(/^(t\.me|telegram\.me)\//i, '');
    val = val.split(/[?#/]/)[0] || '';
    if (!val) return '';
    if (!val.startsWith('@')) val = `@${val}`;
    return val;
  }

  function showFeedback(html, isError = false) {
    const fb = document.getElementById('wlFeedback');
    if (!fb) return;
    fb.innerHTML = html;
    fb.classList.toggle('is-error', isError);
    fb.classList.add('is-visible');
  }

  function initForm() {
    const form = document.getElementById('waitlistForm');
    const submitBtn = document.getElementById('wlSubmitBtn');
    if (!form) return;

    const formLoadedAt = Date.now();

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const websiteHp = (document.getElementById('wlWebsiteHp')?.value || '').trim();
      if (websiteHp) {
        showFeedback('Заявка принята.');
        return;
      }

      const name = (document.getElementById('wlName')?.value || '').trim();
      const email = (document.getElementById('wlEmail')?.value || '').trim();
      const phone = (document.getElementById('wlPhone')?.value || '').trim();
      const telegramRaw = (document.getElementById('wlTelegram')?.value || '').trim();
      const telegram = normalizeTelegramHandle(telegramRaw);
      const motivation = (document.getElementById('wlMotivation')?.value || '').trim();

      const consentPd = document.getElementById('wlConsentPd');
      const consentOffer = document.getElementById('wlConsentOffer');
      const consentPromo = document.getElementById('wlConsentPromo');

      if (!name || !email || !phone || !telegramRaw || !motivation) {
        showFeedback('Пожалуйста, заполните все 5 обязательных полей формы.', true);
        return;
      }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
        showFeedback('Пожалуйста, проверьте корректность e-mail (например, name@company.ru).', true);
        return;
      }
      if (phone.replace(/\D/g, '').length < 7) {
        showFeedback('Пожалуйста, укажите корректный номер телефона (от 7 цифр).', true);
        return;
      }
      if (!/^@[a-zA-Z0-9_]{3,32}$/.test(telegram)) {
        showFeedback('Укажите корректный ник в Telegram (например, @username, латиница и цифры от 3 символов).', true);
        return;
      }
      if (!consentPd?.checked || !consentOffer?.checked) {
        showFeedback('Отметьте оба обязательных согласия: на обработку персональных данных и ознакомление с публичной офертой.', true);
        return;
      }

      const payload = {
        name,
        email,
        phone,
        telegram,
        motivation,
        website_hp: websiteHp,
        formElapsedMs: Math.max(0, Date.now() - formLoadedAt),
        pdnConsent: Boolean(consentPd?.checked),
        offerConsent: Boolean(consentOffer?.checked),
        marketingConsent: Boolean(consentPromo?.checked),
        utm: readUtmParams(),
        ymClientId: document.getElementById('wlYmClientId')?.value || '',
        pageUrl: window.location.href,
      };

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Отправляем заявку...';
      }

      try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 8000);
        const resp = await fetch(CONFIG.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          signal: controller.signal,
        });
        clearTimeout(timer);
        const data = await resp.json().catch(() => null);

        if ((resp.status === 422 || resp.status === 429) && data && typeof data.error === 'string') {
          showFeedback(escapeHtml(data.error), true);
          return;
        }
        if (!resp.ok || !data || data.ok !== true) {
          showFeedback('Не удалось отправить заявку. Попробуйте ещё раз через минуту или напишите в Telegram <a href="https://t.me/alvitcha" target="_blank" rel="noopener noreferrer">@alvitcha</a>.', true);
          return;
        }

        trackEvent('waitlist_form_submit', {
          waitlistId: data.waitlistId || '',
          consentPromo: Boolean(consentPromo?.checked),
        });

        const wlTag = data.waitlistId ? ` № ${escapeHtml(data.waitlistId)}` : '';
        form.reset();
        syncUtmInputs();
        showFeedback(`
          <strong>Вы в листе ожидания${wlTag}!</strong><br>
          Спасибо, ${escapeHtml(name)}. Мы сохранили ваш запрос и пришлём приоритетное приглашение до открытия общих продаж.<br>
          <span style="display:inline-flex;flex-wrap:wrap;gap:8px 16px;margin-top:8px;">
            <a href="https://t.me/hr_expert" target="_blank" rel="noopener noreferrer">Канал Елены @hr_expert →</a>
            <a href="../">Смотреть программу ретрита →</a>
          </span>
        `, false);
      } catch (_) {
        showFeedback('Ошибка соединения с сервером. Повторите попытку или напишите в <a href="https://t.me/alvitcha" target="_blank" rel="noopener noreferrer">Telegram @alvitcha</a>.', true);
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Записаться в лист ожидания';
        }
      }
    });
  }

  document.querySelectorAll('[data-track]').forEach((el) => {
    el.addEventListener('click', () => {
      trackEvent(el.getAttribute('data-track') || 'click');
    });
  });

  syncUtmInputs();
  initCookieConsent();
  initMobileMenu();
  initForm();
})();
