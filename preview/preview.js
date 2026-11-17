/**
 * Школа Елены Витчак — интерактивный контроллер показательного многостраничного сайта (/preview/).
 * В режиме PREVIEW_MODE: true формы работают безопасно локально (без записи в боевую БД и спама на почту).
 */
(() => {
  'use strict';

  const PREVIEW_CONFIG = {
    PREVIEW_MODE: true,
    whatsappPhone: '79256697377',
    telegramHandle: 'alvitcha',
    b2bEmail: 'hr.retrit@yandex.ru',
    liveLeadEndpoint: '../api/hr-retreat-lead.php',
  };

  // 1. Подсветка активной ссылки навигации
  const currentNav = document.body.getAttribute('data-nav') || '';
  if (currentNav) {
    document.querySelectorAll(`[data-nav-key="${currentNav}"]`).forEach((el) => {
      el.classList.add('is-current');
      el.setAttribute('aria-current', 'page');
    });
  }

  // 2. Мобильное меню-бургер
  const burgerBtn = document.getElementById('burgerBtn');
  const mobileNav = document.getElementById('mobileNav');
  const setMenuOpen = (open) => {
    if (!burgerBtn || !mobileNav) return;
    burgerBtn.setAttribute('aria-expanded', String(open));
    mobileNav.hidden = !open;
    if (open) mobileNav.removeAttribute('inert');
    else mobileNav.setAttribute('inert', '');
  };
  if (burgerBtn && mobileNav) {
    burgerBtn.addEventListener('click', () => {
      setMenuOpen(burgerBtn.getAttribute('aria-expanded') !== 'true');
    });
    mobileNav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => setMenuOpen(false));
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') setMenuOpen(false);
    });
  }

  // 3. Интерактивный навигатор выбора программы на главной (preview/index.html)
  const FINDER_DATA = {
    retreat: {
      tag: 'Флагман · Идёт набор на 4-й поток',
      title: 'HR-ретрит: следующий уровень',
      desc: 'Четыре камерные онлайн-встречи с Еленой Витчак (14, 21, 28 января и 4 февраля 2027), чтобы выйти из операционки, оценить риски профессии и собрать личную стратегию на 3–12–36 месяцев.',
      points: [
        '4 живые встречи × 2 часа + follow-up через месяц + доступ в HR-Network',
        'Личный навигатор: 4 карты, матрица Stop–Start–Shift и скрипты переговоров',
        'Оплата от физлица или по счёту B2B (УСН без НДС)',
      ],
      metaLabel: 'Стоимость участия',
      metaVal: '50 000 ₽ до 23 декабря (далее 59 000 ₽)',
      ctaText: 'Открыть программу HR-ретрита →',
      ctaHref: 'retreat.html',
    },
    cleanHead: {
      tag: 'Концепт программы · Предзапись',
      title: 'Telegram-бот-курс «Чистая голова · 21 день»',
      desc: 'Ежедневная 20-минутная система разгрузки ума и восстановления внутреннего ресурса в формате Telegram Mini App — с аудиопрактиками, короткими заданиями и трекером привычек.',
      points: [
        '21 последовательный шаг по 15–20 минут в удобном ритме с телефона',
        'Техники снижения тревожного шума и выстраивания рабочих границ',
        'Интерактивное демо плеера уже доступно на странице программы',
      ],
      metaLabel: 'Статус запуска',
      metaVal: 'Предзапись · тарифы утверждаются',
      ctaText: 'Попробовать плеер программы →',
      ctaHref: 'clean-head.html',
    },
    aiTeam: {
      tag: 'Для руководителей и команд · Открытый и B2B-треки',
      title: '«Руководитель гибридной команды: люди + ИИ-агенты»',
      desc: 'Методологическая рамка управления командами, где рутинную аналитику и черновики берут на себя ИИ-агенты, а руководитель и HR концентрируются на смысле, людях и стратегии.',
      points: [
        'Матрица разделения зон ответственности между человеком и ИИ',
        'Сценарии для HR-директора, операционного блока и совета директоров',
        'Адаптация под задачи компании (корпоративный B2B-формат)',
      ],
      metaLabel: 'Формат и условия',
      metaVal: 'Программа и смета согласуются под запрос',
      ctaText: 'Смотреть матрицу «Люди + ИИ» →',
      ctaHref: 'ai-team.html',
    },
    coaching: {
      tag: 'Персональный формат и B2B',
      title: 'Индивидуальный коучинг и работа с компаниями',
      desc: 'Персональная траектория под конкретный управленческий или карьерный запрос (сертифицированный коуч ICF, тренер PCM) либо закрытая сессия для вашей HR-команды и топ-менеджмента.',
      points: [
        'Индивидуальная работа с целями, внутренними ограничениями и решениями',
        'Разбор реальных кейсов и сложных переговоров со стейкхолдерами',
        'Юридическое оформление по договору ИП (УСН без НДС, акты)',
      ],
      metaLabel: 'Условия участия',
      metaVal: 'По персональному согласованию',
      ctaText: 'Сравнить форматы и оставить запрос →',
      ctaHref: 'about.html#formats',
    },
  };

  const finderTabs = document.querySelectorAll('[data-finder-key]');
  if (finderTabs.length) {
    const tagEl = document.getElementById('finderResTag');
    const titleEl = document.getElementById('finderResTitle');
    const descEl = document.getElementById('finderResDesc');
    const pointsEl = document.getElementById('finderResPoints');
    const metaLblEl = document.getElementById('finderMetaLbl');
    const metaValEl = document.getElementById('finderMetaVal');
    const ctaEl = document.getElementById('finderResCta');

    const applyFinder = (key) => {
      const item = FINDER_DATA[key];
      if (!item) return;
      finderTabs.forEach((b) => {
        const active = b.getAttribute('data-finder-key') === key;
        b.classList.toggle('is-active', active);
        b.setAttribute('aria-selected', String(active));
      });
      if (tagEl) tagEl.textContent = item.tag;
      if (titleEl) titleEl.textContent = item.title;
      if (descEl) descEl.textContent = item.desc;
      if (metaLblEl) metaLblEl.textContent = item.metaLabel;
      if (metaValEl) metaValEl.textContent = item.metaVal;
      if (ctaEl) {
        ctaEl.textContent = item.ctaText;
        ctaEl.setAttribute('href', item.ctaHref);
      }
      if (pointsEl) {
        pointsEl.replaceChildren(...item.points.map((txt) => {
          const li = document.createElement('li');
          li.textContent = txt;
          return li;
        }));
      }
    };

    finderTabs.forEach((btn) => {
      btn.addEventListener('click', () => applyFinder(btn.getAttribute('data-finder-key') || 'retreat'));
    });
    applyFinder('retreat');
  }

  // 4. Интерактивный плеер «Чистая голова · 21 день» (preview/clean-head.html)
  const dayPills = document.querySelectorAll('.pv-day-pill');
  if (dayPills.length) {
    const LESSONS = {
      1: {
        module: 'Модуль 1 · Разгрузка ума',
        tag: 'УРОК 01 ИЗ 21 · 18 МИНУТ',
        title: 'Стоп-кран от тревожного шума',
        summary: 'Короткая практика возврата из круговорота чужих срочных задач в ясное присутствие за 15 минут.',
        durationSec: 1085,
        hw1: 'Прослушать практику без телефона и мессенджеров',
        hw2: 'Записать 3 лишних раздражителя, которые можно отменить сегодня',
        baseProgress: 14,
      },
      7: {
        module: 'Модуль 1 · Тишина в голове',
        tag: 'УРОК 07 ИЗ 21 · 16 МИНУТ',
        title: 'Вечерний сброс операционки перед сном',
        summary: 'Техника отключения внутреннего диалога после сложных переговоров, чтобы сон действительно восстанавливал.',
        durationSec: 960,
        hw1: 'Сделать 5-минутную разгрузку задач на бумаге до 21:00',
        hw2: 'Убрать уведомления рабочих чатов после 20:30 на 3 вечера',
        baseProgress: 38,
      },
      14: {
        module: 'Модуль 2 · Границы и опора',
        tag: 'УРОК 14 ИЗ 21 · 19 МИНУТ',
        title: 'Как говорить «нет» без чувства вины',
        summary: 'Чёткие речевые опоры и внутренняя настройка на сложный разговор с партнёрами или руководством.',
        durationSec: 1140,
        hw1: 'Выбрать 1 запрос недели, на который вы ответите паузой или отказом',
        hw2: 'Пройти короткий аудио-чек опоры тела перед ключевой встречей',
        baseProgress: 67,
      },
      21: {
        module: 'Модуль 3 · Ясный вектор',
        tag: 'УРОК 21 ИЗ 21 · 20 МИНУТ',
        title: 'Ваш личный протокол спокойного тонуса',
        summary: 'Сборка 7-минутного утреннего и антикризисного ритуала, который остаётся с вами после завершения курса.',
        durationSec: 1200,
        hw1: 'Сохранить личную карточку 3 быстрых практик на телефон',
        hw2: 'Назначить дату ежемесячной сверки своего состояния',
        baseProgress: 96,
      },
    };

    let currentDay = 1;
    let playing = false;
    let elapsedSec = 0;
    let speed = 1;
    let timer = null;

    const hwChecks = document.querySelectorAll('.pv-hw-check');
    const playBtn = document.getElementById('pvPlayBtn');
    const audioTimer = document.getElementById('pvAudioTimer');
    const speedBtn = document.getElementById('pvSpeedBtn');

    const fmt = (sec) => {
      const s = Math.max(0, Math.floor(sec));
      return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
    };

    const stopAudio = () => {
      playing = false;
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
      if (playBtn) playBtn.textContent = '▶';
    };

    const updateProgress = () => {
      const lesson = LESSONS[currentDay];
      const done = [...hwChecks].filter((c) => c.checked).length;
      const pct = Math.min(100, lesson.baseProgress + done * 2);
      const pctEl = document.getElementById('pvProgressPct');
      const fillEl = document.getElementById('pvProgressFill');
      if (pctEl) pctEl.textContent = `${pct}% пройдено`;
      if (fillEl) fillEl.style.width = `${pct}%`;
    };

    const selectDay = (day) => {
      currentDay = day;
      const lesson = LESSONS[day];
      if (!lesson) return;
      stopAudio();
      elapsedSec = 0;
      dayPills.forEach((p) => p.classList.toggle('is-active', Number(p.getAttribute('data-day')) === day));
      const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
      setTxt('pvModuleLabel', lesson.module);
      setTxt('pvLessonTag', lesson.tag);
      setTxt('pvLessonTitle', lesson.title);
      setTxt('pvLessonSummary', lesson.summary);
      setTxt('pvHw1', lesson.hw1);
      setTxt('pvHw2', lesson.hw2);
      if (audioTimer) audioTimer.textContent = `${fmt(elapsedSec)} / ${fmt(lesson.durationSec)}`;
      updateProgress();
    };

    dayPills.forEach((p) => p.addEventListener('click', () => selectDay(Number(p.getAttribute('data-day')))));
    hwChecks.forEach((c) => c.addEventListener('change', updateProgress));

    if (playBtn) {
      playBtn.addEventListener('click', () => {
        if (playing) { stopAudio(); return; }
        playing = true;
        playBtn.textContent = '❚❚';
        timer = setInterval(() => {
          elapsedSec += speed;
          if (elapsedSec >= LESSONS[currentDay].durationSec) {
            elapsedSec = 0;
            stopAudio();
          }
          if (audioTimer) audioTimer.textContent = `${fmt(elapsedSec)} / ${fmt(LESSONS[currentDay].durationSec)}`;
        }, 1000);
      });
    }

    if (speedBtn) {
      const speeds = [1, 1.25, 1.5];
      speedBtn.addEventListener('click', () => {
        speed = speeds[(speeds.indexOf(speed) + 1) % speeds.length];
        speedBtn.textContent = `${speed}×`;
      });
    }

    selectDay(1);
  }

  // 5. Матрица ролей «Люди + ИИ-агенты» (preview/ai-team.html)
  const scTabs = document.querySelectorAll('.pv-sc-tab');
  if (scTabs.length) {
    const SCENARIOS = {
      hr: {
        humanTitle: 'Смысл, доверие и политическая воля',
        humanPoints: [
          'Финальные решения по найму и повышению ключевых руководителей.',
          'Трудные переговоры со стейкхолдерами и удержание ценных талантов.',
          'Этика, корпоративная культура и справедливая оценка вклада человека.',
        ],
        aiTitle: 'Рутина, аналитика и черновики 24/7',
        aiPoints: [
          'Первичный анализ резюме и сопоставление профилей под стратегию.',
          'Подготовка структур 1-on-1, опросников вовлечённости и регламентов.',
          'Мониторинг перегрузки команд и ранние сигналы риска выгорания.',
        ],
        m1Label: 'Фокус HR-директора',
        m1Val: 'Высвобождение до 11 часов в неделю от рутины',
        m2Label: 'Ключевой артефакт',
        m2Val: 'Карта делегирования ролей и KPI агентов',
        m3Label: 'Ценность для компании',
        m3Val: 'Скорость решений без раздувания штата',
      },
      ops: {
        humanTitle: 'Архитектура приоритетов и ответственность',
        humanPoints: [
          'Постановка нестандартных целей, где нет готовых прошлых прецедентов.',
          'Разрешение конфликтов интересов между смежными подразделениями.',
          'Контроль рисков, за которые отвечает бизнес-лидер лично.',
        ],
        aiTitle: 'Сводки, контроль сроков и моделирование',
        aiPoints: [
          'Сборка ежедневных отчётов из десятков чатов и таблиц в 1 лист.',
          'Напоминания по поручениям и фиксация отклонений от сроков.',
          'Быстрое проигрывание сценариев нагрузки и ресурсов до планёрки.',
        ],
        m1Label: 'Ритм управления',
        m1Val: 'Подготовка планёрок в сжатом формате',
        m2Label: 'Ключевой артефакт',
        m2Val: 'Протокол «Человек утверждает — агент готовит»',
        m3Label: 'Ценность для бизнеса',
        m3Val: 'Прозрачность процессов без микроменеджмента',
      },
      board: {
        humanTitle: 'Стратегический вектор и защита капитала',
        humanPoints: [
          'Выбор курса развития бизнеса и новых рынков на горизонте 1–3 лет.',
          'Утверждение альянсов, инвестиций и ключевых назначений.',
          'Ответственность перед акционерами, партнёрами и репутацией бренда.',
        ],
        aiTitle: 'Сценарный радар и подготовка позиций',
        aiPoints: [
          'Сжатие объёмных отчётов в структурированную повестку на 2 листа.',
          'Сравнение рыночных практик и выявление скрытых слабых мест.',
          'Подготовка контраргументов и вопросов к защите проектов.',
        ],
        m1Label: 'Работа с материалами',
        m1Val: 'Краткие управленческие дайджесты вместо ручной вычитки',
        m2Label: 'Ключевой артефакт',
        m2Val: 'ИИ-контур поддержки управленческих решений',
        m3Label: 'Ценность для совета',
        m3Val: 'Чистый фокус на стратегии и людях',
      },
    };

    const renderSc = (key) => {
      const data = SCENARIOS[key];
      if (!data) return;
      scTabs.forEach((t) => {
        const active = t.getAttribute('data-sc') === key;
        t.classList.toggle('is-active', active);
        t.setAttribute('aria-pressed', String(active));
      });
      const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
      setTxt('pvHumanTitle', data.humanTitle);
      setTxt('pvAiTitle', data.aiTitle);
      setTxt('pvM1Lbl', data.m1Label);
      setTxt('pvM1Val', data.m1Val);
      setTxt('pvM2Lbl', data.m2Label);
      setTxt('pvM2Val', data.m2Val);
      setTxt('pvM3Lbl', data.m3Label);
      setTxt('pvM3Val', data.m3Val);

      const renderList = (id, items) => {
        const ul = document.getElementById(id);
        if (!ul) return;
        ul.replaceChildren(...items.map((txt) => {
          const li = document.createElement('li');
          li.textContent = txt;
          return li;
        }));
      };
      renderList('pvHumanList', data.humanPoints);
      renderList('pvAiList', data.aiPoints);
    };

    scTabs.forEach((t) => t.addEventListener('click', () => renderSc(t.getAttribute('data-sc') || 'hr')));
    renderSc('hr');
  }

  // 6. Формы предпросмотра: переключение B2C/B2B, условная оферта (только HR-ретрит) и безопасный превью-вывод
  const escapeHtml = (str) => String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');

  document.querySelectorAll('[data-preview-form]').forEach((form) => {
    const feedback = form.querySelector('.form-feedback');
    const b2bBox = form.querySelector('[data-b2b-box]');
    const programSel = form.querySelector('[data-program-select]');
    const offerRow = form.querySelector('[data-offer-row]');
    const offerChk = form.querySelector('input[name="consent_offer"]');

    const syncPayer = () => {
      const mode = form.querySelector('input[name="payer_type"]:checked')?.value || 'b2c';
      form.querySelectorAll('.payer-pill').forEach((pill) => {
        const inp = pill.querySelector('input[type="radio"]');
        pill.classList.toggle('is-active', Boolean(inp && inp.checked));
      });
      if (b2bBox) b2bBox.hidden = mode !== 'b2b';
    };

    const syncProgramOffer = () => {
      if (!programSel || !offerRow) return;
      const isRetreat = programSel.value === 'retreat';
      offerRow.hidden = !isRetreat;
      if (offerChk) offerChk.required = isRetreat;
    };

    form.querySelectorAll('input[name="payer_type"]').forEach((r) => r.addEventListener('change', syncPayer));
    if (programSel) programSel.addEventListener('change', syncProgramOffer);
    syncPayer();
    syncProgramOffer();

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!feedback) return;

      const hp = (form.querySelector('input[name="website"]')?.value || '').trim();
      if (hp) {
        feedback.textContent = 'Заявка принята.';
        feedback.classList.add('is-visible');
        return;
      }

      const name = (form.querySelector('input[name="name"]')?.value || '').trim();
      const contact = (form.querySelector('input[name="contact"]')?.value || '').trim();
      const email = (form.querySelector('input[name="email"]')?.value || '').trim();
      const company = (form.querySelector('input[name="company"]')?.value || '').trim();
      const comment = (form.querySelector('textarea[name="comment"]')?.value || '').trim();
      const payerMode = form.querySelector('input[name="payer_type"]:checked')?.value || 'b2c';
      const program = programSel ? programSel.value : (form.getAttribute('data-default-program') || 'retreat');
      const programLabel = programSel
        ? (programSel.options[programSel.selectedIndex]?.text || program)
        : (form.getAttribute('data-program-label') || 'HR-ретрит');

      const pdnChk = form.querySelector('input[name="consent_pd"]');
      const promoChk = form.querySelector('input[name="consent_promo"]');
      const needOffer = program === 'retreat' && Boolean(offerChk);

      if (!name || !contact) {
        feedback.textContent = 'Пожалуйста, укажите имя и телефон или Telegram для связи.';
        feedback.classList.add('is-visible');
        return;
      }
      if ((pdnChk && !pdnChk.checked) || (needOffer && !offerChk.checked)) {
        feedback.textContent = needOffer
          ? 'Отметьте оба обязательных согласия: на обработку персональных данных и условия публичной оферты.'
          : 'Отметьте обязательное согласие на обработку персональных данных по 152-ФЗ.';
        feedback.classList.add('is-visible');
        return;
      }

      const summary = [
        `Заявка (${PREVIEW_CONFIG.PREVIEW_MODE ? 'ПРЕДПРОСМОТР' : 'САЙТ'}): ${programLabel}`,
        `Формат: ${payerMode === 'b2b' ? 'B2B (компания / ИП)' : 'B2C (физлицо)'}`,
        `Имя: ${name}`,
        `Контакт: ${contact}`,
        email ? `Email: ${email}` : null,
        company && payerMode === 'b2b' ? `Компания / ИНН: ${company}` : null,
        comment ? `Комментарий: ${comment}` : null,
        `Рассылка 38-ФЗ: ${promoChk && promoChk.checked ? 'Да' : 'Нет'}`,
      ].filter(Boolean).join('\n');

      const enc = encodeURIComponent(summary);
      const tgHref = `https://t.me/${PREVIEW_CONFIG.telegramHandle}?text=${enc}`;
      const mailHref = `mailto:${PREVIEW_CONFIG.b2bEmail}?subject=${encodeURIComponent('Заявка · ' + programLabel)}&body=${enc}`;
      const previewBadge = PREVIEW_CONFIG.PREVIEW_MODE
        ? 'Режим показательного предпросмотра: заявка не записана в боевую базу HR-ретрита. После утверждения сайта формы подключатся к серверу за 1 минуту.'
        : 'Заявка успешно отправлена.';

      feedback.innerHTML = `
        <strong>Предпросмотр заявки для ${escapeHtml(name)} (${escapeHtml(programLabel)}).</strong><br>
        <span>${previewBadge}</span><br>
        <span style="display:inline-flex;flex-wrap:wrap;gap:8px 14px;margin-top:8px;">
          ${program === 'retreat' && PREVIEW_CONFIG.PREVIEW_MODE ? '<a href="../#apply" style="font-weight:700;text-decoration:underline;">Перейти к боевой записи на 4-й поток →</a>' : ''}
          <a href="${tgHref}" target="_blank" rel="noopener noreferrer" style="font-weight:600;text-decoration:underline;">Открыть в Telegram →</a>
          <a href="${mailHref}" style="font-weight:600;text-decoration:underline;">Сформировать Email →</a>
          <button type="button" class="copy-email-btn" data-copy-summary>Скопировать текст</button>
        </span>
      `;
      feedback.classList.add('is-visible');

      const copyBtn = feedback.querySelector('[data-copy-summary]');
      if (copyBtn) {
        copyBtn.addEventListener('click', async () => {
          try {
            if (navigator.clipboard && window.isSecureContext) {
              await navigator.clipboard.writeText(summary);
              copyBtn.textContent = 'Скопировано ✓';
            }
          } catch (_) {}
        });
      }
    });
  });

  // Копирование email в один клик
  document.querySelectorAll('[data-copy-email]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const addr = btn.getAttribute('data-copy-email') || '';
      if (!addr) return;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(addr);
          const orig = btn.textContent;
          btn.textContent = 'Почта скопирована ✓';
          setTimeout(() => { btn.textContent = orig; }, 2400);
        }
      } catch (_) {}
    });
  });
})();
