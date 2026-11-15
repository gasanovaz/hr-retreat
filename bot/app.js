(() => {
  'use strict';

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
  let tickTimer = null;

  const formatTime = (sec) => {
    const s = Math.max(0, Math.floor(sec));
    const mm = String(Math.floor(s / 60)).padStart(2, '0');
    const ss = String(s % 60).padStart(2, '0');
    return `${mm}:${ss}`;
  };

  // Economics switcher
  const segBtns = document.querySelectorAll('.seg-btn');
  const econTotal = document.getElementById('econTotal');
  segBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      segBtns.forEach((b) => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      const users = Number(btn.dataset.users || 300);
      const total = users * 4900;
      if (econTotal) {
        econTotal.textContent = `${String(total).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} ₽`;
      }
    });
  });

  // Phone UI elements
  const dayPills = document.querySelectorAll('.day-pill');
  const hwChecks = document.querySelectorAll('.hw-check');
  const playBtn = document.getElementById('audioPlayBtn');
  const playIcon = document.getElementById('playIcon');
  const waveBars = document.getElementById('waveBars');
  const audioTimer = document.getElementById('audioTimer');
  const speedBtn = document.getElementById('speedBtn');
  const tgTabs = document.querySelectorAll('.tg-tab');
  const tgPanels = document.querySelectorAll('.tg-panel');
  const tierCards = document.querySelectorAll('.tier-card');
  const tierToast = document.getElementById('tierToast');
  const tgResetBtn = document.getElementById('tgResetBtn');

  function updateProgress() {
    const lesson = LESSONS[currentDay];
    const done = [...hwChecks].filter((c) => c.checked).length;
    const hwCount = document.getElementById('hwCount');
    if (hwCount) hwCount.textContent = `${done} / ${hwChecks.length}`;
    const pct = Math.min(100, lesson.baseProgress + done * 2);
    const pctEl = document.getElementById('progressPercent');
    const fillEl = document.getElementById('progressFill');
    if (pctEl) pctEl.textContent = `${pct}% пройдено`;
    if (fillEl) fillEl.style.width = `${pct}%`;
  }

  function renderTimer() {
    const lesson = LESSONS[currentDay];
    if (audioTimer) {
      audioTimer.textContent = `${formatTime(elapsedSec)} / ${formatTime(lesson.durationSec)}`;
    }
  }

  function stopAudio() {
    playing = false;
    if (tickTimer) {
      clearInterval(tickTimer);
      tickTimer = null;
    }
    if (playIcon) playIcon.textContent = '▶';
    if (waveBars) waveBars.classList.remove('is-playing');
  }

  function selectDay(day) {
    currentDay = day;
    const lesson = LESSONS[day];
    stopAudio();
    elapsedSec = 0;

    dayPills.forEach((p) => p.classList.toggle('is-active', Number(p.dataset.day) === day));
    document.getElementById('courseStepLabel').textContent = lesson.module;
    document.getElementById('lessonTag').textContent = lesson.tag;
    document.getElementById('lessonTitle').textContent = lesson.title;
    document.getElementById('lessonSummary').textContent = lesson.summary;
    document.getElementById('hwText1').textContent = lesson.hw1;
    document.getElementById('hwText2').textContent = lesson.hw2;

    renderTimer();
    updateProgress();
  }

  dayPills.forEach((pill) => {
    pill.addEventListener('click', () => selectDay(Number(pill.dataset.day)));
  });

  hwChecks.forEach((chk) => chk.addEventListener('change', updateProgress));

  if (playBtn) {
    playBtn.addEventListener('click', () => {
      if (playing) {
        stopAudio();
        return;
      }
      playing = true;
      if (playIcon) playIcon.textContent = '❚❚';
      if (waveBars) waveBars.classList.add('is-playing');
      tickTimer = setInterval(() => {
        elapsedSec += speed;
        if (elapsedSec >= LESSONS[currentDay].durationSec) {
          elapsedSec = 0;
          stopAudio();
        }
        renderTimer();
      }, 1000);
    });
  }

  if (speedBtn) {
    const speeds = [1, 1.25, 1.5];
    speedBtn.addEventListener('click', () => {
      const idx = (speeds.indexOf(speed) + 1) % speeds.length;
      speed = speeds[idx];
      speedBtn.textContent = `${speed}×`;
    });
  }

  function switchTab(target) {
    tgTabs.forEach((t) => t.classList.toggle('is-active', t.dataset.target === target));
    tgPanels.forEach((p) => {
      const match = p.dataset.panel === target;
      p.hidden = !match;
      p.classList.toggle('is-active', match);
    });
  }

  tgTabs.forEach((tab) => {
    tab.addEventListener('click', () => switchTab(tab.dataset.target));
  });

  if (tgResetBtn) {
    tgResetBtn.addEventListener('click', () => {
      switchTab('today');
      selectDay(1);
    });
  }

  tierCards.forEach((card) => {
    card.addEventListener('click', () => {
      if (tierToast) {
        tierToast.textContent = `Выбран тариф «${card.dataset.tier}» — в боте сразу открывается оплата ЮKassa / СБП`;
      }
    });
  });

  selectDay(1);
})();
